<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\Contacts\ContactResourceReferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactDataController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return Inertia::render('settings/contact-data', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'fields' => $workspace->contactCustomFields()->orderBy('name')->get(['id', 'name', 'type']),
            'tags' => $workspace->tags()->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function storeField(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $data = $this->validateData($request, $workspace, 'contact_custom_fields');
        $workspace->contactCustomFields()->create([...$data, 'type' => 'text']);

        return back();
    }

    public function updateField(Request $request, Workspace $workspace, int $field): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $target = $workspace->contactCustomFields()->findOrFail($field);
        $target->update($this->validateData($request, $workspace, 'contact_custom_fields', $target->id));

        return back();
    }

    public function storeTag(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $data = $this->validateData($request, $workspace, 'tags');
        $workspace->tags()->create([...$data, 'created_by_user_id' => $request->user()->id]);

        return back();
    }

    public function updateTag(Request $request, Workspace $workspace, int $tag): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $target = $workspace->tags()->findOrFail($tag);
        $target->update($this->validateData($request, $workspace, 'tags', $target->id));

        return back();
    }

    public function destroyField(Request $request, Workspace $workspace, int $field): RedirectResponse
    {
        return $this->destroyResource($request, $workspace, 'field', $field);
    }

    public function destroyTag(Request $request, Workspace $workspace, int $tag): RedirectResponse
    {
        return $this->destroyResource($request, $workspace, 'tag', $tag);
    }

    private function destroyResource(Request $request, Workspace $workspace, string $kind, int $id): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        DB::transaction(function () use ($request, $workspace, $kind, $id): void {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $resource = ($kind === 'field' ? $workspace->contactCustomFields() : $workspace->tags())->whereKey($id)->lockForUpdate()->firstOrFail();
            $references = app(ContactResourceReferences::class)->find($workspace, $kind, $id);
            if ($references !== []) {
                throw ValidationException::withMessages(['resource' => 'Não é possível excluir: este item é utilizado por '.implode(', ', $references).'. Remova as referências e publique os ajustes antes de excluir.']);
            }
            app(AuditLogService::class)->record($workspace, 'contact_'.$kind.'.deleted', $request->user(), $resource);
            $resource->delete();
        }, 3);

        return back();
    }

    private function validateData(Request $request, Workspace $workspace, string $table, ?int $id = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique($table, 'name')->where('workspace_id', $workspace->id)->ignore($id)],
        ];
        if ($table === 'tags') {
            $rules['color'] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }

        return $request->validate($rules, [
            'name.required' => 'Informe um nome.',
            'name.string' => 'Informe um nome válido.',
            'name.max' => 'O nome deve ter até 100 caracteres.',
            'name.unique' => 'Este nome já está cadastrado neste workspace.',
            'color.required' => 'Escolha uma cor.',
            'color.string' => 'Informe uma cor válida.',
            'color.regex' => 'Informe uma cor no formato #RRGGBB.',
        ]);
    }
}

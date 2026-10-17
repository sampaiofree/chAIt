<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\InternationalPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 403);
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        return Inertia::render('settings/team', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'members' => $workspace->memberships()->with('user')->get()->map(fn ($membership) => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role,
                'can_view_unassigned' => $membership->can_view_unassigned,
                'can_manage_flows' => $membership->can_manage_flows,
                'can_use_flow_assistant' => $membership->can_use_flow_assistant,
            ])->values(),
        ]);
    }

    public function update(Request $request, Workspace $workspace, int $membership): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $data = $request->validate([
            'can_view_unassigned' => ['sometimes', 'boolean'],
            'can_manage_flows' => ['sometimes', 'boolean'],
            'can_use_flow_assistant' => ['sometimes', 'boolean'],
        ]);
        abort_if($data === [], 422, 'Informe ao menos uma permissão para atualizar.');
        $target = $workspace->memberships()->findOrFail($membership);
        abort_if(WorkspaceRole::from($target->role)->atLeast(WorkspaceRole::Admin), 403, 'A permissão de administradores é definida pelo papel.');
        $target->update($data);

        return back();
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'can_view_unassigned' => ['required', 'boolean'],
            'can_manage_flows' => ['required', 'boolean'],
            'can_use_flow_assistant' => ['sometimes', 'boolean'],
        ]);
        // Account fields are validated only when creating an account, never for a link.
        $data += $request->only(['name', 'phone', 'password', 'password_confirmation']);
        $data['email'] = Str::lower($data['email']);

        DB::transaction(function () use ($data, $workspace): void {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();

            if ($user !== null) {
                if ($workspace->memberships()->where('user_id', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'Este usuário já faz parte da equipe.',
                    ]);
                }
            } else {
                validator($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'phone' => ['bail', 'required', new InternationalPhone],
                    'password' => ['required', 'string', Password::default(), 'confirmed'],
                ], [
                    'phone.required' => 'Informe o número do WhatsApp com DDI para criar uma conta.',
                ])->validate();

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'password' => Hash::make($data['password']),
                    // Prevent the User-created hook from making a private workspace.
                    'current_workspace_id' => $workspace->id,
                ]);
            }

            $workspace->memberships()->create([
                'user_id' => $user->id,
                'role' => WorkspaceRole::Agent->value,
                'invited_at' => now(),
                'joined_at' => now(),
                'last_seen_at' => now(),
                'can_view_unassigned' => $data['can_view_unassigned'],
                'can_manage_flows' => $data['can_manage_flows'],
                'can_use_flow_assistant' => $data['can_use_flow_assistant'] ?? false,
            ]);
        });

        return back()->with('success', 'Usuário adicionado à equipe.');
    }
}

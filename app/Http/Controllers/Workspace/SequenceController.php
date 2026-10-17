<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Sequences\SequenceDefinitionService;
use App\Services\Sequences\SequenceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SequenceController extends Controller
{
    use EnsuresWorkspaceAccess;

    private function manage(Request $request, Workspace $workspace): void
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        abort_unless($request->user()->canManageFlows($workspace), 403);
    }

    public function index(Request $request, Workspace $workspace): Response
    {
        $this->manage($request, $workspace);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:all,active,draft,inactive']]);
        $query = Sequence::where('workspace_id', $workspace->id)->with('publishedVersion')->withCount([
            'enrollments as active_enrollments_count' => fn ($q) => $q->whereNotIn('status', SequenceEnrollment::TERMINAL),
        ])->orderBy('name');
        if ($search = trim($data['search'] ?? '')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        match ($data['status'] ?? 'all') {
            'active' => $query->where('is_active', true),
            'draft' => $query->whereNull('published_version_id'),
            'inactive' => $query->where('is_active', false)->whereNotNull('published_version_id'),
            default => null,
        };
        $sequences = $query->get()->map(fn ($sequence) => [
            'id' => $sequence->id, 'name' => $sequence->name, 'is_active' => $sequence->is_active,
            'published_version_id' => $sequence->published_version_id,
            'steps_count' => count($sequence->draft['steps'] ?? []), 'active_enrollments_count' => $sequence->active_enrollments_count,
        ]);

        return Inertia::render('sequences/index', ['workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'sequences' => $sequences, 'filters' => ['search' => $search, 'status' => $data['status'] ?? 'all']]);
    }

    public function store(Request $request, Workspace $workspace, AuditLogService $audit): JsonResponse
    {
        $this->manage($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Informe o nome da sequência.');
        $sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => $name, 'created_by_user_id' => $request->user()->id,
            'draft' => ['name' => $name, 'timezone' => 'America/Sao_Paulo', 'steps' => []]]);
        $audit->record($workspace, 'sequence.created', $request->user(), $sequence);

        return response()->json(['data' => $sequence], 201);
    }

    public function edit(Request $request, Workspace $workspace, int $sequence): Response
    {
        $this->manage($request, $workspace);
        $model = Sequence::where('workspace_id', $workspace->id)->findOrFail($sequence);

        return Inertia::render('sequences/editor', ['workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'sequence' => $model, 'flows' => $this->flows($workspace), 'timezones' => timezone_identifiers_list(),
            'published_version' => $model->publishedVersion?->version,
            'enrollments' => $model->enrollments()->when(! ($request->user()->canAccessWorkspace($workspace, WorkspaceRole::Admin) || $request->user()->membershipFor($workspace)->can_view_unassigned), fn ($query) => $query->whereHas('thread', fn ($thread) => $thread->where('assigned_to_user_id', $request->user()->id)))->with('version', 'nextStep.flowVersion.flow', 'contact')->latest('id')->limit(20)->get()
                ->map(fn ($enrollment) => array_merge($this->snapshot($enrollment), ['contact_id' => $enrollment->contact_id, 'contact_name' => $enrollment->contact?->display_name ?: $enrollment->contact?->phone]))]);
    }

    public function update(Request $request, Workspace $workspace, int $sequence, SequenceDefinitionService $definitions): JsonResponse
    {
        $this->manage($request, $workspace);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $model = Sequence::where('workspace_id', $workspace->id)->findOrFail($sequence);

        return response()->json(['data' => $definitions->save($model, $workspace, $request->all(), $revision)]);
    }

    public function publish(Request $request, Workspace $workspace, int $sequence, SequenceDefinitionService $definitions): JsonResponse
    {
        $this->manage($request, $workspace);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $model = Sequence::where('workspace_id', $workspace->id)->findOrFail($sequence);
        $version = $definitions->publish($model, $workspace, $request->user(), $revision);

        return response()->json(['data' => ['version' => $version->version, 'is_active' => true]]);
    }

    public function deactivate(Request $request, Workspace $workspace, int $sequence): JsonResponse
    {
        return $this->setActive($request, $workspace, $sequence, false);
    }

    public function activate(Request $request, Workspace $workspace, int $sequence): JsonResponse
    {
        return $this->setActive($request, $workspace, $sequence, true);
    }

    private function setActive(Request $request, Workspace $workspace, int $id, bool $active): JsonResponse
    {
        $this->manage($request, $workspace);
        DB::transaction(function () use ($request, $workspace, $id, $active): void {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $sequence = Sequence::where('workspace_id', $workspace->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($active) {
                abort_unless($sequence->published_version_id, 422, 'Publique uma versão antes de ativar.');
                $ids = DB::table('sequence_version_flows')->where('sequence_version_id', $sequence->published_version_id)->pluck('flow_id');
                abort_unless(Flow::where('workspace_id', $workspace->id)->whereIn('id', $ids)->where('is_active', true)->count() === $ids->count(), 409, 'Ative os fluxos referenciados antes de reativar a sequência.');
                $versionIds = DB::table('sequence_version_flows')->where('sequence_version_id', $sequence->published_version_id)->pluck('flow_version_id');
                foreach (FlowVersion::where('workspace_id', $workspace->id)->whereIn('id', $versionIds)->get() as $version) {
                    $validator = app(FlowGraphValidator::class);
                    $validator->validateResourceReferences($validator->normalize($version->graph), $workspace);
                }
            }
            $sequence->update(['is_active' => $active]);
            app(AuditLogService::class)->record($workspace, $active ? 'sequence.activated' : 'sequence.deactivated', $request->user(), $sequence);
        });

        return response()->json(['data' => ['is_active' => $active]]);
    }

    private function contactThread(Request $request, Workspace $workspace, Contact $contact): Thread
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->firstOrFail();
        $thread = Thread::where('workspace_id', $workspace->id)->where('uazapi_connection_id', $connection->id)->where('contact_id', $contact->id)->firstOrFail();
        $membership = $request->user()->membershipFor($workspace);
        abort_unless(WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) || $membership->can_view_unassigned || $thread->assigned_to_user_id === $request->user()->id, 404);

        return $thread;
    }

    public function enrollment(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        $thread = $this->contactThread($request, $workspace, $contact);
        $currentId = AutomationContext::where('workspace_id', $workspace->id)->where('uazapi_connection_id', $thread->uazapi_connection_id)->where('contact_id', $contact->id)->value('current_enrollment_id');
        $enrollment = $currentId ? SequenceEnrollment::find($currentId) : SequenceEnrollment::where('workspace_id', $workspace->id)
            ->where('uazapi_connection_id', $thread->uazapi_connection_id)->where('contact_id', $contact->id)->latest('id')->first();

        return response()->json(['data' => $enrollment ? $this->snapshot($enrollment) : null,
            'sequences' => $request->user()->canManageFlows($workspace) ? Sequence::where('workspace_id', $workspace->id)->where('is_active', true)->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name']) : [],
            'can_enroll' => $request->user()->canManageFlows($workspace)]);
    }

    public function enroll(Request $request, Workspace $workspace, Contact $contact, SequenceEnrollmentService $enrollments): JsonResponse
    {
        $this->manage($request, $workspace);
        $thread = $this->contactThread($request, $workspace, $contact);
        $data = $request->validate(['sequence_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid']]);
        $sequence = Sequence::where('workspace_id', $workspace->id)->findOrFail($data['sequence_id']);
        $result = $enrollments->enroll($thread, $sequence, 'manual', 'manual:'.$data['idempotency_key'], $request->user());

        return response()->json(['result' => $result['result'], 'data' => $this->snapshot($result['enrollment'])]);
    }

    public function snapshot(SequenceEnrollment $enrollment): array
    {
        $remaining = $enrollment->remaining_seconds;
        if ($enrollment->running_since && $enrollment->due_at) {
            $remaining = max(0, (int) ceil(now()->diffInSeconds($enrollment->due_at, false)));
        }

        return ['id' => $enrollment->id, 'sequence_id' => $enrollment->sequence_id, 'name' => $enrollment->version->name,
            'version' => $enrollment->version->version, 'timezone' => $enrollment->version->timezone, 'status' => $enrollment->status,
            'step_position' => $enrollment->nextStep?->position, 'flow_name' => $enrollment->nextStep?->flowVersion?->flow?->name,
            'due_at' => $enrollment->due_at?->toIso8601String(), 'remaining_seconds' => $remaining,
            'blocked_reasons' => $enrollment->blocked_reasons ?? [], 'terminal_reason' => $enrollment->terminal_reason,
            'updated_at' => $enrollment->updated_at->toIso8601String(), 'schedule_type' => $enrollment->nextStep?->schedule_type];
    }

    private function flows(Workspace $workspace): mixed
    {
        return Flow::where('workspace_id', $workspace->id)->where('is_active', true)->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name']);
    }
}

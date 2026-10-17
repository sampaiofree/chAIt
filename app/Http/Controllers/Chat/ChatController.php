<?php

namespace App\Http\Controllers\Chat;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ChatOperation;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\Workspace;
use App\Services\Chat\ChatOperations;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatReconciliation;
use App\Services\Chat\HumanAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function index(Request $request, Workspace $workspace, ChatProjection $projection): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        $tagIds = $request->validate([
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('workspace_id', $workspace->id)],
        ])['tag_ids'] ?? [];
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->first();
        if (! $connection) {
            return response()->json(['data' => [], 'selected_thread' => null, 'next_cursor' => null, 'import' => ['status' => 'unavailable', 'offset' => 0, 'complete' => true]]);
        }
        $connection = app(ChatReconciliation::class)->start($connection);
        $membership = $request->user()->membershipFor($workspace);
        $query = Thread::where('workspace_id', $workspace->id)->where('uazapi_connection_id', $connection->id);
        if (! WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) && ! $membership->can_view_unassigned) {
            $query->where('assigned_to_user_id', $request->user()->id);
        }
        $allowedQuery = clone $query;
        if ($request->input('assignment') === 'mine') {
            $query->where('assigned_to_user_id', $request->user()->id);
        }
        if ($request->input('assignment') === 'unassigned') {
            $query->whereNull('assigned_to_user_id');
        }
        if ($request->input('status') === 'open') {
            $query->whereIn('status', ['open', 'in_progress']);
        }
        if ($request->input('status') === 'completed') {
            $query->where('status', 'completed');
        }
        if ($tagIds !== []) {
            $query->whereHas('contact.tags', fn ($q) => $q->where('tags.workspace_id', $workspace->id)->whereIn('tags.id', $tagIds));
        }
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 255);
        if ($search !== '') {
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('display_name', 'ilike', '%'.$search.'%');
                $digits = preg_replace('/\D/', '', $search);
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }
        // Stable cursor supports rows without a provider message timestamp.
        $query->select('threads.*')->selectRaw("COALESCE(last_message_at, '1970-01-01'::timestamp) AS sort_at");
        if ($cursor = $request->input('cursor')) {
            $decoded = json_decode(base64_decode((string) $cursor, true) ?: '', true);
            abort_unless(is_array($decoded) && isset($decoded['at'], $decoded['id']) && is_numeric($decoded['id']), 422);
            $query->whereRaw("(COALESCE(last_message_at, '1970-01-01'::timestamp), id) < (?, ?)", [$decoded['at'], (int) $decoded['id']]);
        }
        $rows = $query->orderByDesc('sort_at')->orderByDesc('id')->limit(201)->get();
        $more = $rows->count() > 200;
        $rows = $rows->take(200);
        $selectedId = $request->integer('thread_id') ?: $rows->first()?->id;
        $serialized = $projection->serialize($rows, $selectedId);
        $last = $rows->last();
        $selected = $serialized->firstWhere('id', $selectedId);
        if (! $selected && $selectedId && ($detail = $allowedQuery->whereKey($selectedId)->first())) {
            $selected = $projection->serialize(collect([$detail]), $selectedId)->first();
        }
        $endCursor = $last ? base64_encode(json_encode(['at' => $last->sort_at, 'id' => $last->id])) : null;

        return response()->json(['data' => $serialized->values(), 'selected_thread' => $selected, 'next_cursor' => $more ? $endCursor : null, 'end_cursor' => $endCursor, 'import' => ['status' => $connection->chat_import_status, 'offset' => $connection->chat_import_offset, 'complete' => $connection->chat_import_status === 'complete' || $connection->chat_last_reconciled_at !== null]]);
    }

    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless(config('chat.enabled'), 404);
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        $connection = UazapiConnection::where('workspace_id', $workspace->id)->firstOrFail();
        abort_unless($connection->status === 'connected', 409, 'WhatsApp desconectado.');
        $connection = app(ChatReconciliation::class)->start($connection, true);

        return response()->json(['data' => ['status' => $connection->chat_import_status, 'offset' => $connection->chat_import_offset]], $connection->chat_import_status === 'complete' ? 200 : 202);
    }

    public function connect(Request $request, Workspace $workspace, string $kind = 'connect'): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        abort_unless(filled(config('services.uazapi.base_url')), 503);
        $operation = DB::transaction(function () use ($request, $workspace, $kind) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $connection = UazapiConnection::firstOrCreate(['workspace_id' => $workspace->id], ['instance_name' => 'chait-w'.$workspace->id.'-'.Str::lower(Str::random(8)), 'status' => 'creating']);
            $existing = ChatOperation::where('uazapi_connection_id', $connection->id)->where('kind', $kind)->whereIn('status', ['pending', 'running'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $request->user()->id, 409, 'Outra operação está em andamento.');

                return $existing;
            }

            return ChatOperation::create(['workspace_id' => $workspace->id, 'user_id' => $request->user()->id, 'uazapi_connection_id' => $connection->id, 'kind' => $kind, 'status' => 'pending', 'track_id' => (string) Str::uuid(), 'payload' => ['step' => 'create'], 'expires_at' => now()->addHours(config('chat.temporary_hours'))]);
        });
        app(ChatOperations::class)->dispatch($operation);

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status]], 202);
    }

    public function operation(Request $request, Workspace $workspace, Thread $thread, string $kind): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        abort_unless($thread->workspace_id === $workspace->id && $thread->uazapi_connection_id, 404);
        $member = $request->user()->membershipFor($workspace);
        abort_unless(WorkspaceRole::from($member->role)->atLeast(WorkspaceRole::Admin) || $member->can_view_unassigned || $thread->assigned_to_user_id === $request->user()->id, 404);
        $payload = $request->only(['read_version', 'track_id', 'preview', 'refresh']);
        if ($kind === 'media') {
            $payload['message_id'] = (string) $request->route('messageId');
        }
        if ($kind === 'send') {
            abort_if($thread->assigned_to_user_id && $thread->assigned_to_user_id !== $request->user()->id, 409, 'Esta conversa já tem responsável.');
            abort_unless($thread->uazapiConnection?->status === 'connected', 409, 'WhatsApp desconectado.');
            $voice = $request->input('media_type') === 'voice';
            $data = $request->validate([
                'media_type' => ['nullable', 'in:voice'], 'track_id' => ['nullable', 'uuid'],
                'text' => $voice ? ['prohibited'] : ['nullable', 'string', 'max:4096'],
                'caption' => $voice ? ['prohibited'] : ['nullable', 'string', 'max:1024'],
                'file' => $voice ? ['required', 'file', 'max:10240', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a'] : ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ]);
            abort_unless(filled($data['text'] ?? null) || $request->hasFile('file'), 422);
            $payload = array_diff_key($data, ['file' => true]);
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $payload += ['file_path' => $file->store('chat-temporary', 'local'), 'file_name' => $file->getClientOriginalName(), 'file_mime' => $file->getMimeType()];
                $kind = 'send_file';
            }
            try {
                $operation = DB::transaction(function () use ($thread, $request, $workspace, $kind, $payload) {
                    app(HumanAssignment::class)->claim($thread, $request->user(), $request->user(), $workspace, false);

                    return app(ChatOperations::class)->create($thread, $request->user()->id, $kind, $payload);
                }, 3);
            } catch (\Throwable $exception) {
                if (isset($payload['file_path'])) {
                    Storage::disk('local')->delete($payload['file_path']);
                }
                throw $exception;
            }

            return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status, 'track_id' => $operation->track_id]], 202);
        }
        $operation = app(ChatOperations::class)->create($thread, $request->user()->id, $kind, $payload);

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => $operation->status, 'track_id' => $operation->track_id]], 202);
    }
}

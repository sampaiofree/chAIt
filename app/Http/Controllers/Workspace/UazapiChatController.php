<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Concerns\EnsuresWorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\FlowExecution;
use App\Models\Note;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\Workspace;
use App\Services\AuditLogService;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatPublisher;
use App\Services\Chat\HumanAssignment;
use App\Services\Chat\InboundEvents;
use App\Services\Flows\FlowAutomationControl;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use App\Services\Uazapi\UazapiRejectedException;
use App\Services\Uazapi\VoiceAudioConverter;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UazapiChatController extends Controller
{
    use EnsuresWorkspaceAccess;

    public function connection(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        $connection = UazapiConnection::query()->where('workspace_id', $workspace->id)->first();

        $data = $connection ? [
            'status' => $connection->status,
            'phone' => $connection->phone,
            'connected_at' => $connection->connected_at,
            'last_checked_at' => $connection->provider_checked_at,
        ] : null;
        if (config('chat.enabled') && $connection && $request->user()->canAccessWorkspace($workspace, WorkspaceRole::Admin)) {
            $data += $connection->only(['profile_name', 'profile_pic_url', 'last_disconnect_at', 'last_disconnect_reason']);
        }

        return response()->json(['data' => $data]);
    }

    public function start(Request $request, Workspace $workspace, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled')) {
            return app(ChatController::class)->connect($request, $workspace);
        }
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        abort_unless(filled(config('services.uazapi.base_url')) && filled(config('services.uazapi.admin_token')), 503, 'Configure UAZAPI_BASE_URL e UAZAPI_ADMIN_TOKEN no ambiente do servidor.');

        $connection = UazapiConnection::query()->firstOrCreate(['workspace_id' => $workspace->id], [
            'instance_name' => 'chait-w'.$workspace->id.'-'.Str::lower(Str::random(8)),
            'status' => 'creating',
        ]);

        if (! $connection->instance_token) {
            $result = $uazapi->createInstance($connection->instance_name);
            $instance = $result['instance'] ?? $result;
            $token = $result['token'] ?? data_get($instance, 'token') ?? data_get($instance, 'instanceToken');
            abort_unless(is_string($token) && $token !== '', 502, 'A UAZAPI não retornou o token da instância.');

            $connection->forceFill([
                'instance_id' => (string) (data_get($instance, 'id') ?? data_get($instance, 'instanceId') ?? $connection->instance_name),
                'instance_token' => $token,
                'webhook_secret_hash' => hash('sha256', $this->webhookSecret($connection)),
                'status' => 'disconnected',
            ])->save();

        }

        $secret = $this->webhookSecret($connection);
        $uazapi->configureWebhook($connection, route('webhooks.uazapi', [
            'connection' => $connection->id, 'secret' => $secret,
        ]));

        $qr = $uazapi->connect($connection);
        $connection->update(['status' => 'connecting']);

        return response()->json(['data' => [
            'status' => 'connecting',
            'qr_code' => data_get($qr, 'qrcode') ?? data_get($qr, 'qrCode') ?? data_get($qr, 'base64') ?? data_get($qr, 'instance.qrcode'),
            'pairing_code' => data_get($qr, 'pairingCode') ?? data_get($qr, 'instance.paircode'),
        ]]);
    }

    public function status(Request $request, Workspace $workspace, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_diagnostic')) {
            return $this->connection($request, $workspace);
        }
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        $connection = UazapiConnection::query()->where('workspace_id', $workspace->id)->first();
        if (! $connection) {
            return response()->json(['data' => null]);
        }

        $providerAvailable = true;
        $checkedAt = now();
        $qrCode = null;
        $pairingCode = null;
        try {
            $data = $uazapi->status($connection);
            $rawStatus = strtolower((string) (data_get($data, 'instance.status') ?? data_get($data, 'status.instance.status') ?? (is_string(data_get($data, 'status')) ? data_get($data, 'status') : 'disconnected')));
            $connected = (bool) (data_get($data, 'status.connected') ?? in_array($rawStatus, ['connected', 'open', 'online'], true));
            $phone = data_get($data, 'status.jid.user') ?? data_get($data, 'phone') ?? data_get($data, 'instance.phone');
            $qrCode = data_get($data, 'qrcode') ?? data_get($data, 'qrCode') ?? data_get($data, 'instance.qrcode');
            $pairingCode = data_get($data, 'pairingCode') ?? data_get($data, 'instance.paircode');
            $connection->update([
                'status' => $connected ? 'connected' : ($rawStatus ?: 'disconnected'),
                'phone' => $phone ?: $connection->phone,
                'connected_at' => $connected ? ($connection->connected_at ?? now()) : null,
                'activated_at' => $connected ? ($connection->activated_at ?? now()) : $connection->activated_at,
                'profile_name' => data_get($data, 'instance.profileName') ?? $connection->profile_name,
                'profile_pic_url' => data_get($data, 'instance.profilePicUrl') ?? $connection->profile_pic_url,
                'last_disconnect_at' => $this->providerTimestamp(data_get($data, 'instance.lastDisconnect')) ?? $connection->last_disconnect_at,
                'last_disconnect_reason' => data_get($data, 'instance.lastDisconnectReason') ?? $connection->last_disconnect_reason,
                'provider_checked_at' => $checkedAt,
            ]);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (HttpClientException|RuntimeException) {
            $providerAvailable = false;
            $connection->update(['provider_checked_at' => $checkedAt]);
        }

        $connection->refresh();
        $result = [
            'status' => $connection->status,
            'phone' => $connection->phone,
            'connected_at' => $connection->connected_at,
            'provider_available' => $providerAvailable,
            'last_checked_at' => $connection->provider_checked_at,
        ];
        if (WorkspaceRole::from($request->user()->membershipFor($workspace)->role)->atLeast(WorkspaceRole::Admin)) {
            $result += [
                'profile_name' => $connection->profile_name,
                'profile_pic_url' => $connection->profile_pic_url,
                'last_disconnect_at' => $connection->last_disconnect_at,
                'last_disconnect_reason' => $connection->last_disconnect_reason,
                'qr_code' => $qrCode,
                'pairing_code' => $pairingCode,
            ];
        }

        return response()->json(['data' => $result]);
    }

    public function diagnose(Request $request, Workspace $workspace, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->connect($request, $workspace, 'diagnose');
        }
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $request->attributes->set('chat_diagnostic', true);

        return $this->status($request, $workspace, $uazapi);
    }

    public function index(Request $request, Workspace $workspace, UazapiClient $uazapi): JsonResponse
    {
        return app(ChatController::class)->index($request, $workspace, app(ChatProjection::class));
    }

    public function history(Request $request, Workspace $workspace, Thread $thread, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->operation($request, $workspace, $thread, 'history');
        }
        $this->authorizeThread($request, $workspace, $thread);
        $thread->refresh();
        $readVersion = (int) $thread->unread_version;
        $connection = $thread->uazapiConnection;
        abort_unless($connection, 404);
        abort_unless(filled($thread->external_chat_id), 404);
        $offset = (int) ($request->validate(['offset' => ['sometimes', 'integer', 'min:0', 'max:100000']])['offset'] ?? 0);
        try {
            $page = $this->asList($uazapi->messages($connection, $thread->external_chat_id, $offset), ['messages', 'data', 'items']);
            $messages = array_reverse($page);
            $trackId = $request->string('track_id')->toString();
            if ($offset === 0 && $trackId !== '') {
                $correlated = array_values(array_filter($this->asList($uazapi->messages($connection, $thread->external_chat_id, 0, $trackId, restrictToChat: true), ['messages', 'data', 'items']), fn ($item) => ($item['chatid'] ?? data_get($item, 'key.remoteJid')) === $thread->external_chat_id));
                $messages = [...$messages, ...$correlated];
            }
            $messages = collect($messages)->unique(fn (array $message) => (string) (data_get($message, 'id') ?? data_get($message, 'messageid') ?? data_get($message, 'track_id')))->values();

            return response()->json(['data' => $messages->map(fn ($message) => $this->transientMessage($message, $workspace, $thread))->values(), 'next_offset' => count($page) === 100 ? $offset + 100 : null, 'history_limited' => false, 'read_version' => $readVersion]);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (HttpClientException|RuntimeException) {
            return response()->json(['data' => [], 'next_offset' => null, 'history_limited' => true, 'message' => 'A UAZAPI não disponibilizou este histórico. Mensagens antigas podem ter expirado ou não terem sido sincronizadas pelo aparelho.'], 503);
        }
    }

    public function markRead(Request $request, Workspace $workspace, Thread $thread, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->operation($request, $workspace, $thread, 'read');
        }
        $this->authorizeThread($request, $workspace, $thread);
        $validated = $request->validate(['read_version' => ['required', 'integer', 'min:0']]);
        $connection = $thread->uazapiConnection;
        abort_unless($connection && $connection->workspace_id === $workspace->id, 404);
        abort_unless($connection->status === 'connected', 409, 'WhatsApp desconectado.');

        $thread->refresh();
        if ((int) $thread->unread_version !== (int) $validated['read_version']) {
            return response()->json(['message' => 'A conversa recebeu novas mensagens.', 'data' => $this->serialize($thread)], 409);
        }

        if ($thread->unread_count === 0) {
            return response()->json(['data' => $this->serialize($thread)]);
        }

        try {
            $uazapi->markChatRead($connection, $thread->external_chat_id ?: $thread->contact->phone);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (HttpClientException|RuntimeException) {
            return response()->json(['message' => 'A UAZAPI não confirmou a leitura.', 'data' => $this->serialize($thread)], 503);
        }

        $result = DB::transaction(function () use ($thread, $validated): array {
            $current = Thread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->unread_version !== (int) $validated['read_version']) {
                return ['stale' => true, 'thread' => $current];
            }

            $current->forceFill([
                'unread_count' => 0,
                'unread_version' => $current->unread_version + 1,
            ])->save();

            return ['stale' => false, 'thread' => $current];
        });

        if ($result['stale']) {
            // Restore the provider's unread state if a new inbound message
            // arrived while its read request was in flight.
            try {
                $uazapi->markChatRead($connection, $thread->external_chat_id ?: $thread->contact->phone, false);
            } catch (UazapiDeferredException $exception) {
                throw $exception;
            } catch (HttpClientException|RuntimeException) {
                // The local unread count remains authoritative until a later
                // successful read or an updated provider count is received.
            }

            return response()->json(['message' => 'A conversa recebeu novas mensagens.', 'data' => $this->serialize($result['thread'])], 409);
        }

        return response()->json(['data' => $this->serialize($result['thread'])]);
    }

    public function avatar(Request $request, Workspace $workspace, Thread $thread, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->operation($request, $workspace, $thread, 'avatar');
        }
        $this->authorizeThread($request, $workspace, $thread);
        $preview = $request->boolean('preview', true);
        $chatId = $thread->external_chat_id ?: $thread->contact->phone;
        $key = "uazapi:avatar:{$workspace->id}:{$thread->uazapi_connection_id}:".sha1($chatId).':'.($preview ? 'preview' : 'full');
        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }
        $refresh = $request->boolean('refresh');
        $cachedAvatar = Cache::remember($key, now()->addMinutes(15), function () use ($uazapi, $thread, $chatId, $preview, $refresh) {
            $profile = $thread->contact->profile ?? [];
            $knownUrl = $preview
                ? (data_get($profile, 'imagePreview') ?: data_get($profile, 'image_preview') ?: data_get($profile, 'image'))
                : data_get($profile, 'image');

            if (! $refresh && is_string($knownUrl) && $knownUrl !== '') {
                return ['url' => $knownUrl];
            }

            try {
                $url = data_get($uazapi->avatar($thread->uazapiConnection, $chatId, $preview), 'url');
                $url = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
                if ($preview && ($url !== data_get($profile, 'imagePreview') || ($refresh && $knownUrl))) {
                    if ($url) {
                        $profile['imagePreview'] = $url;
                    } elseif ($refresh) {
                        unset($profile['imagePreview'], $profile['image_preview'], $profile['image']);
                    }
                    $thread->contact->update(['profile' => $profile]);
                    app(ChatPublisher::class)->thread($thread->id);
                }

                return ['url' => $url];
            } catch (UazapiDeferredException $exception) {
                throw $exception;
            } catch (HttpClientException|RuntimeException) {
                return ['url' => null];
            }
        });

        return response()->json(['data' => ['url' => data_get($cachedAvatar, 'url')]]);
    }

    public function media(Request $request, Workspace $workspace, Thread $thread, string $messageId, UazapiClient $uazapi): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->operation($request, $workspace, $thread, 'media');
        }
        $this->authorizeThread($request, $workspace, $thread);
        abort_unless($messageId !== '' && strlen($messageId) <= 191, 404);
        try {
            $found = $this->asList($uazapi->findMessage($thread->uazapiConnection, $messageId), ['messages', 'data', 'items']);
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (HttpClientException|RuntimeException) {
            return response()->json(['data' => ['url' => null, 'unavailable' => true]], 410);
        }
        $chatId = $thread->external_chat_id ?: $thread->contact->phone;
        $belongsToThread = collect($found)->contains(fn (array $message) => (string) (data_get($message, 'id') ?? data_get($message, 'messageid')) === $messageId
            && (string) (data_get($message, 'chatid') ?? data_get($message, 'key.remoteJid') ?? '') === $chatId
        );
        abort_unless($belongsToThread, 404);
        try {
            $url = data_get($uazapi->downloadMessage($thread->uazapiConnection, $messageId), 'fileURL');
        } catch (UazapiDeferredException $exception) {
            throw $exception;
        } catch (HttpClientException|RuntimeException) {
            return response()->json(['data' => ['url' => null, 'unavailable' => true]], 410);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    public function open(Request $request, Workspace $workspace, Thread $thread, AuditLogService $audit, FlowAutomationControl $automations): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);

        return DB::transaction(function () use ($request, $workspace, $thread, $audit, $automations): JsonResponse {
            $thread->contact()->lockForUpdate()->firstOrFail();
            $thread->refresh();
            $this->mayViewUnassigned($request, $workspace, $thread);
            abort_if($thread->assigned_to_user_id, 409, 'Altere a atribuição para atender esta conversa.');
            $thread->forceFill(['status' => 'open', 'human_opened_at' => $thread->human_opened_at ?? now(), 'finished_at' => null])->save();
            $automations->humanOpened($thread);
            $audit->record($workspace, 'conversation.opened', $request->user(), $thread);

            return response()->json(['data' => $this->serialize($thread->fresh(['contact', 'assignedTo', 'notes.user']), true)]);
        });
    }

    public function claim(Request $request, Workspace $workspace, Thread $thread): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);
        $claimed = app(HumanAssignment::class)->claim($thread, $request->user(), $request->user(), $workspace, true);

        return response()->json(['data' => $this->serialize($claimed->fresh(['contact', 'assignedTo', 'notes.user']), true)]);
    }

    public function assignTo(Request $request, Workspace $workspace, Thread $thread): JsonResponse
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace, WorkspaceRole::Admin);
        $this->authorizeThread($request, $workspace, $thread);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $membership = $workspace->memberships()->where('user_id', $data['user_id'])->whereNotNull('joined_at')->firstOrFail();
        $claimed = app(HumanAssignment::class)->claim($thread, $request->user(), $membership->user, $workspace, true);

        return response()->json(['data' => $this->serialize($claimed->fresh(['contact', 'assignedTo', 'notes.user']), true)]);
    }

    public function finish(Request $request, Workspace $workspace, Thread $thread, AuditLogService $audit, FlowAutomationControl $automations): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);

        return DB::transaction(function () use ($request, $workspace, $thread, $audit, $automations): JsonResponse {
            $thread->contact()->lockForUpdate()->firstOrFail();
            $thread->refresh();
            $this->mayActOnThread($request, $thread);
            $former = $thread->assigned_to_user_id;
            $thread->assignments()->where('state', 'active')->update(['state' => 'released', 'updated_at' => now()]);
            $thread->forceFill(['status' => 'completed', 'last_assigned_to_user_id' => $former ?: $thread->last_assigned_to_user_id, 'assigned_to_user_id' => null, 'finished_at' => now()])->save();
            $automations->humanFinished($thread);
            $audit->record($workspace, 'conversation.finished', $request->user(), $thread, null, ['former_assignee_id' => $former]);

            return response()->json(['data' => $this->serialize($thread->fresh(['contact', 'assignedTo', 'notes.user']), true)]);
        });
    }

    public function pauseAutomations(Request $request, Workspace $workspace, Thread $thread, FlowAutomationControl $automations, AuditLogService $audit): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);
        $this->mayViewUnassigned($request, $workspace, $thread);
        abort_unless($thread->uazapi_connection_id, 409, 'Indisponível sem uma conexão do WhatsApp.');
        $data = $request->validate([
            'mode' => ['required', 'in:timed,indefinite'],
            'expires_at' => ['required_if:mode,timed', 'prohibited_unless:mode,timed', 'nullable', 'date_format:Y-m-d\TH:i'],
        ], [
            'expires_at.required_if' => 'Escolha uma data e um horário para retomar.',
            'expires_at.date_format' => 'Informe uma data e um horário válidos.',
        ]);
        $expiresAt = $data['mode'] === 'timed'
            ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['expires_at'], $workspace->timezone)->utc()
            : null;
        if ($expiresAt && $expiresAt->lte(now())) {
            throw ValidationException::withMessages(['expires_at' => 'Escolha uma data e um horário futuros.']);
        }
        DB::transaction(function () use ($request, $workspace, $thread, $automations, $audit, $expiresAt): void {
            $automations->pause($thread, $request->user()->id, $expiresAt);
            $audit->record($workspace, 'conversation.automation_paused', $request->user(), $thread, null, [
                'expires_at' => $expiresAt?->toIso8601String(),
                'uazapi_connection_id' => $thread->uazapi_connection_id,
            ]);
        });

        return response()->json(['data' => $this->serialize($thread->fresh(['contact', 'assignedTo']), false)]);
    }

    public function resumeAutomations(Request $request, Workspace $workspace, Thread $thread, FlowAutomationControl $automations, AuditLogService $audit): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);
        $this->mayViewUnassigned($request, $workspace, $thread);
        abort_unless(! in_array($thread->status, ['open', 'in_progress'], true) || $thread->human_opened_at === null, 409, 'Finalize o atendimento humano antes de retomar a automação.');
        DB::transaction(function () use ($request, $workspace, $thread, $automations, $audit): void {
            $automations->resume($thread);
            $audit->record($workspace, 'conversation.automation_resumed', $request->user(), $thread, null, [
                'uazapi_connection_id' => $thread->uazapi_connection_id,
            ]);
        });

        return response()->json(['data' => $this->serialize($thread->fresh(['contact', 'assignedTo']), false)]);
    }

    public function send(Request $request, Workspace $workspace, Thread $thread, UazapiClient $uazapi, AuditLogService $audit, VoiceAudioConverter $voiceConverter): JsonResponse
    {
        if (config('chat.enabled') && ! $request->attributes->get('chat_operation_direct')) {
            return app(ChatController::class)->operation($request, $workspace, $thread, 'send');
        }
        $this->authorizeThread($request, $workspace, $thread);
        if ($request->attributes->get('chat_operation_direct')) {
            abort_unless($thread->status === 'in_progress' && $thread->assigned_to_user_id === $request->user()->id, 403, 'A atribuição mudou antes do envio.');
        }
        abort_if($thread->assigned_to_user_id && $thread->assigned_to_user_id !== $request->user()->id, 409, 'Esta conversa já tem responsável.');
        $connection = $thread->uazapiConnection;
        abort_unless($connection && $connection->status === 'connected', 409, 'WhatsApp desconectado.');
        $isVoice = $request->input('media_type') === 'voice';
        $validated = $request->validate([
            'media_type' => ['nullable', 'in:voice'],
            'text' => $isVoice ? ['prohibited'] : ['nullable', 'string', 'max:4096'],
            'file' => $isVoice
                ? ['required', 'file', 'max:10240', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a']
                : ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'caption' => $isVoice ? ['prohibited'] : ['nullable', 'string', 'max:1024'],
            'track_id' => $isVoice ? ['required', 'uuid'] : ['nullable', 'string', 'max:191'],
        ]);
        abort_unless(filled($validated['text'] ?? null) || $request->hasFile('file'), 422, 'Escreva uma mensagem ou selecione um anexo.');
        $voice = $isVoice ? $voiceConverter->convert($request->file('file')) : null;
        if (! $request->attributes->get('chat_operation_direct')) {
            $thread = app(HumanAssignment::class)->claim($thread, $request->user(), $request->user(), $workspace, false);
        }
        if ($isVoice) {
            // Conversion can take several seconds. Recheck ownership and the
            // connection before sending if the conversation changed meanwhile.
            $thread->refresh();
            $request->user()->unsetRelation('memberships');
            $this->authorizeThread($request, $workspace, $thread);
            abort_unless($thread->status === 'in_progress' && $thread->assigned_to_user_id === $request->user()->id, 403, 'Assuma o atendimento antes de responder.');
            $connection = $thread->uazapiConnection;
            abort_unless($connection && $connection->status === 'connected', 409, 'WhatsApp desconectado.');
        }
        $trackId = null;
        $serialized = null;
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            // Upload content through a short-lived data URL; it is never stored in the application database.
            $mimeType = $isVoice ? 'audio/ogg' : $file->getMimeType();
            $encoded = $voice['file'] ?? ('data:'.$mimeType.';base64,'.base64_encode(file_get_contents($file->getRealPath())));
            $kind = $isVoice ? 'ptt' : (str_starts_with($mimeType, 'image/') ? 'image' : (str_starts_with($mimeType, 'video/') ? 'video' : 'document'));
            $trackId = $request->string('track_id')->toString() ?: (string) Str::uuid();
            try {
                $providerMessage = $uazapi->sendMedia($connection, $thread->external_chat_id ?: $thread->contact->phone, $encoded, $kind, $validated['caption'] ?? null, $isVoice ? null : $file->getClientOriginalName(), $mimeType, $trackId);
            } catch (UazapiDeferredException $exception) {
                throw $exception;
            } catch (UazapiRejectedException $exception) {
                // Provider authentication failures must not log out the SaaS user.
                $status = in_array($exception->status, [413, 415, 429]) ? $exception->status : 422;

                return response()->json(['message' => $exception->getMessage(), 'data' => ['sent' => false, 'status' => 'failed', 'track_id' => $trackId]], $status);
            } catch (UazapiDeferredException $exception) {
                throw $exception;
            } catch (HttpClientException|RuntimeException) {
                return response()->json(['data' => ['sent' => false, 'status' => 'confirmation_pending', 'track_id' => $trackId]], 202);
            }
            $providerMessage = is_array(data_get($providerMessage, 'message')) ? data_get($providerMessage, 'message') : $providerMessage;
            $providerMessage['track_id'] = data_get($providerMessage, 'track_id') ?? $trackId;
            $providerMessage['fromMe'] = true;
            $providerMessage['messageType'] = data_get($providerMessage, 'messageType') ?? $kind;
            if ($isVoice) {
                $providerMessage['mimetype'] = 'audio/ogg';
                $providerMessage['duration_seconds'] = $voice['duration_seconds'];
            }
            if (in_array(strtolower((string) data_get($providerMessage, 'status')), ['queued', 'pending'])) {
                return response()->json(['data' => ['sent' => false, 'status' => 'confirmation_pending', 'track_id' => $trackId]], 202);
            }
            $serialized = $this->transientMessage($providerMessage, $workspace, $thread);
            if ($isVoice) {
                $serialized['is_voice'] = true;
            }
        } else {

            $trackId = config('chat.enabled') ? ($validated['track_id'] ?? (string) Str::uuid()) : null;
            $sent = $uazapi->sendText($connection, $thread->external_chat_id ?: $thread->contact->phone, $validated['text'], $trackId);
            if (config('chat.enabled')) {
                if (in_array(strtolower((string) ($sent['status'] ?? data_get($sent, 'message.status'))), ['queued', 'pending'], true)) {
                    return response()->json(['data' => ['sent' => false, 'status' => 'confirmation_pending', 'track_id' => $trackId]], 202);
                }
                $serialized = $this->transientMessage(array_merge($sent['message'] ?? $sent, ['text' => $validated['text'], 'fromMe' => true, 'track_id' => $trackId]), $workspace, $thread);
            }
        }
        if (config('chat.enabled') && $serialized) {
            app(ChatProjection::class)->sync($connection, [], ['chatid' => $thread->external_chat_id, 'id' => $serialized['id'], 'messageid' => $serialized['whatsapp_id'] ?? $serialized['id'], 'messageType' => $serialized['type'], 'text' => $serialized['text'], 'fromMe' => true, 'messageTimestamp' => $serialized['timestamp'] ?? now()->getTimestampMs()]);
        } else {
            $thread->forceFill(['last_message_at' => now(), 'last_outbound_at' => now()])->save();
        }
        if (config('chat.enabled') && $serialized) {
            app(ChatPublisher::class)->send($workspace->id, 'message.received', $serialized, $thread->id);
        }
        $audit->record($workspace, $request->hasFile('file') ? 'conversation.attachment_sent' : 'conversation.message_sent', $request->user(), $thread);

        return response()->json(['data' => ['sent' => true, 'status' => 'sent', 'message' => $serialized, 'track_id' => $trackId]]);
    }

    public function updateContact(Request $request, Workspace $workspace, Thread $thread, AuditLogService $audit): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);
        $this->mayActOnThread($request, $thread);
        $contact = $thread->contact;
        $contact->update($request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:14'],
        ]));
        $audit->record($workspace, 'contact.updated', $request->user(), $contact);

        return response()->json(['data' => $contact->only(['id', 'display_name', 'phone', 'email', 'cpf'])]);
    }

    public function addNote(Request $request, Workspace $workspace, Thread $thread, AuditLogService $audit): JsonResponse
    {
        $this->authorizeThread($request, $workspace, $thread);
        $this->mayActOnThread($request, $thread);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $note = Note::query()->create(['workspace_id' => $workspace->id, 'thread_id' => $thread->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);
        $audit->record($workspace, 'conversation.note_added', $request->user(), $note);

        return response()->json(['data' => $note->load('user')], 201);
    }

    public function webhook(Request $request, UazapiConnection $connection, string $secret): JsonResponse
    {
        abort_unless($connection->webhook_secret_hash && hash_equals($connection->webhook_secret_hash, hash('sha256', $secret)), 404);
        $providerToken = $request->input('token');
        abort_if(is_string($providerToken) && ! hash_equals((string) $connection->instance_token, $providerToken), 404);
        $inbox = app(InboundEvents::class)->accept($request, $connection);

        return response()->json(['ok' => true, 'receipt_id' => $inbox->id]);
    }

    private function serialize(Thread $thread, bool $detail = false): array
    {
        if (config('chat.enabled')) {
            return app(ChatProjection::class)->serialize(collect([$thread]), $detail ? $thread->id : null)->first();
        }
        $data = [
            'id' => $thread->id, 'status' => $thread->status,
            'last_message_at' => $thread->last_message_at, 'unread_count' => $thread->unread_count,
            'unread_version' => (int) $thread->unread_version,
            'contact' => array_merge($thread->contact->only(['id', 'display_name', 'phone', 'email', 'cpf']), [
                'avatar_url' => data_get($thread->contact->profile, 'imagePreview') ?? data_get($thread->contact->profile, 'image') ?? null,
            ]),
            'assigned_to' => $thread->assignedTo?->only(['id', 'name']),
            'last_assigned_to_user_id' => $thread->last_assigned_to_user_id,
            'automation' => $this->automationSummary($thread),
            'automations_paused' => $thread->uazapi_connection_id ? DB::table('automation_pauses')->where('uazapi_connection_id', $thread->uazapi_connection_id)->where('contact_id', $thread->contact_id)->where('reason', 'manual')->whereNull('released_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists() : false,
            'tags' => $thread->contact->tags()->orderBy('name')->get(['tags.id', 'tags.name'])->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values(),
            'custom_fields' => $thread->contact->customFieldValues()->with('field:id,name')->get()->map(fn ($value) => ['id' => $value->field_id, 'name' => $value->field?->name, 'value' => $value->value])->values(),
        ];
        if ($detail) {
            $data['notes'] = $thread->notes->map(fn (Note $note) => ['id' => $note->id, 'body' => $note->body, 'author' => $note->user?->name, 'created_at' => $note->created_at])->values();
        }

        return $data;
    }

    private function automationSummary(Thread $thread): array
    {
        if (! $thread->uazapi_connection_id) {
            return [
                'state' => 'unavailable',
                'pause_reasons' => [],
                'execution_status' => null,
                'can_resume' => false,
                'can_pause' => false,
                'expires_at' => null,
            ];
        }

        $pauses = DB::table('automation_pauses')
            ->where('uazapi_connection_id', $thread->uazapi_connection_id)
            ->where('contact_id', $thread->contact_id)
            ->whereNull('released_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['reason', 'expires_at']);
        $pauseReasons = $pauses->pluck('reason')->unique()->values();
        $humanPaused = in_array($thread->status, ['open', 'in_progress'], true)
            && $thread->human_opened_at !== null;
        if ($humanPaused && ! $pauseReasons->contains('human')) {
            $pauseReasons->push('human');
        }

        $executionStatus = FlowExecution::query()
            ->where('contact_id', $thread->contact_id)
            ->where('uazapi_connection_id', $thread->uazapi_connection_id)
            ->whereIn('status', FlowExecutionManager::ACTIVE)
            ->latest('id')
            ->value('status');
        $hasManualPause = $pauseReasons->contains('manual');
        $manualPauses = $pauses->where('reason', 'manual');
        $expiresAt = $hasManualPause && ! $manualPauses->contains(fn ($pause) => $pause->expires_at === null)
            ? $manualPauses->max('expires_at') : null;

        return [
            'state' => $pauseReasons->isEmpty() ? 'allowed' : 'paused',
            'pause_reasons' => $pauseReasons->values()->all(),
            'execution_status' => $executionStatus,
            'can_resume' => $hasManualPause && ! $humanPaused,
            'can_pause' => ! $hasManualPause,
            'expires_at' => $expiresAt ? Carbon::parse($expiresAt, 'UTC')->toIso8601String() : null,
        ];
    }

    private function providerUnreadCount(array $chat): ?int
    {
        foreach (['wa_unreadCount', 'unreadCount', 'unread_count'] as $key) {
            if (array_key_exists($key, $chat) && is_numeric($chat[$key])) {
                return max(0, (int) $chat[$key]);
            }
        }

        return null;
    }

    public function transientMessage(array $message, Workspace $workspace, Thread $thread): array
    {
        $id = data_get($message, 'id') ?? data_get($message, 'messageid');
        $content = data_get($message, 'content');
        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }
        $type = strtolower((string) (data_get($message, 'messageType') ?? data_get($message, 'type') ?? 'text'));
        $audioContent = data_get($content, 'audioMessage') ?? data_get($content, 'audio') ?? $content;
        $mimeType = data_get($message, 'mimetype') ?? data_get($audioContent, 'mimetype');
        $isAudio = preg_match('/audio|ptt/', $type) === 1 || str_starts_with((string) $mimeType, 'audio/');
        $duration = data_get($message, 'duration_seconds') ?? data_get($audioContent, 'seconds');
        $mediaUrl = data_get($message, 'fileURL') ?? data_get($message, 'media.url') ?? data_get($message, 'mediaUrl') ?? data_get($content, 'image.url') ?? data_get($content, 'imageMessage.url');
        $caption = data_get($message, 'text') ?? data_get($content, 'image.caption') ?? data_get($content, 'imageMessage.caption') ?? data_get($message, 'caption') ?? '';

        return [
            'id' => $id ?? data_get($message, 'track_id') ?? (string) Str::uuid(),
            'whatsapp_id' => data_get($message, 'messageid') ?? data_get($message, 'key.id'),
            'from_me' => (bool) (data_get($message, 'fromMe') ?? data_get($message, 'from_me') ?? false),
            'type' => $type,
            'text' => is_string(data_get($message, 'text')) ? data_get($message, 'text') : (is_string($content) ? $content : ''),
            'caption' => $caption,
            'media_url' => $mediaUrl,
            'media_endpoint' => $id ? route('workspaces.conversations.media', [$workspace, $thread, $id]) : null,
            'track_id' => data_get($message, 'track_id') ?? data_get($message, 'trackId'),
            'status' => strtolower((string) (data_get($message, 'status') ?? 'sent')),
            'is_image' => str_contains($type, 'image') || str_contains($type, 'photo') || str_starts_with((string) data_get($message, 'mimetype'), 'image/'),
            'is_audio' => $isAudio,
            'is_voice' => $isAudio && (in_array($type, ['ptt', 'myaudio']) || (bool) data_get($audioContent, 'ptt', false)),
            'mime_type' => is_string($mimeType) ? $mimeType : null,
            'duration_seconds' => $isAudio && is_numeric($duration) ? (float) $duration : null,
            'timestamp' => $this->providerTimestamp(data_get($message, 'messageTimestamp') ?? data_get($message, 'timestamp') ?? data_get($message, 'createdAt')),
        ];
    }

    private function asList(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        return array_is_list($data) ? $data : [];
    }

    private function authorizeThread(Request $request, Workspace $workspace, Thread $thread): void
    {
        $this->ensureWorkspaceAccess($request->user(), $workspace);
        abort_unless($thread->workspace_id === $workspace->id, 404);

        $membership = $request->user()->membershipFor($workspace);
        $canViewAll = WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) || $membership->can_view_unassigned;
        abort_unless($canViewAll || $thread->assigned_to_user_id === $request->user()->id, 404);
    }

    private function mayViewUnassigned(Request $request, Workspace $workspace, Thread $thread): void
    {
        $membership = $request->user()->membershipFor($workspace);
        abort_unless($thread->assigned_to_user_id === $request->user()->id || WorkspaceRole::from($membership->role)->atLeast(WorkspaceRole::Admin) || $membership->can_view_unassigned, 403);
    }

    private function mayActOnThread(Request $request, Thread $thread): void
    {
        abort_unless($thread->assigned_to_user_id === $request->user()->id || WorkspaceRole::from($request->user()->membershipFor($thread->workspace)->role)->atLeast(WorkspaceRole::Admin), 403, 'Somente o responsável ou administrador pode alterar este atendimento.');
    }

    private function webhookSecret(UazapiConnection $connection): string
    {
        return hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
    }

    private function providerTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $seconds = (float) $value;
            if ($seconds > 9999999999) {
                $seconds /= 1000;
            }

            return Carbon::createFromTimestamp((int) $seconds);
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

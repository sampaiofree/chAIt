<?php

use App\Jobs\CleanupAiSession;
use App\Jobs\ExpireAiSession;
use App\Jobs\ProcessAiTurn;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\FlowAsset;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\Flows\AiActionService;
use App\Services\Flows\AiInboundMessage;
use App\Services\Flows\AiSessionService;
use App\Services\Flows\AiTurnBudget;
use App\Services\Flows\FlowAutomationControl;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowInboundProcessor;
use App\Services\Flows\FlowRuntime;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function aiGraph(array $data = []): array
{
    return [
        'schema_version' => 2,
        'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Start']],
            ['id' => 'ai', 'type' => 'ai', 'position' => ['x' => 0, 'y' => 150], 'data' => array_merge([
                'label' => 'IA', 'ai_prompt' => 'Converse com o lead.', 'ai_model' => 'gpt-6.1-sol',
                'ai_inactivity_seconds' => 3600, 'ai_expiry_action' => 'end',
                'ai_max_messages' => null, 'ai_limit_action' => 'human',
            ], $data)],
        ],
        'edges' => [['id' => 'start-ai', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'ai', 'targetHandle' => 'input']],
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];
}

function aiFixture(array $data = []): array
{
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id, 'external_chat_id' => '5511999999999@s.whatsapp.net',
        'status' => 'completed', 'human_opened_at' => null, 'assigned_to_user_id' => null,
    ]);
    $flow = $workspace->flows()->create(['name' => 'IA', 'graph' => aiGraph($data)]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => aiGraph($data),
        'published_by_user_id' => $user->id, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
    WorkspaceOpenAiIntegration::create(['workspace_id' => $workspace->id, 'api_key' => 'sk-test-workspace']);

    return compact('user', 'workspace', 'connection', 'contact', 'thread', 'flow', 'version');
}

test('OpenAI key is encrypted, admin-only and never returned by settings', function () {
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'gpt-6.1-sol'])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $this->actingAs($user)->putJson(route('workspaces.openai.update', $workspace), ['api_key' => 'sk-secret-example'])
        ->assertOk()->assertJsonMissing(['api_key' => 'sk-secret-example']);
    expect(WorkspaceOpenAiIntegration::first()->api_key)->toBe('sk-secret-example')
        ->and(DB::table('workspace_openai_integrations')->value('api_key'))->not->toContain('sk-secret-example');
    $this->get(route('openai.settings'))->assertOk()->assertDontSee('sk-secret-example');
    $agent = User::factory()->create();
    $this->actingAs($agent)->putJson(route('workspaces.openai.update', $workspace), ['api_key' => 'sk-other'])->assertForbidden();
});

test('AI draft can be saved but publishing requires a valid key and model', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'IA', 'graph' => aiGraph(['ai_prompt' => ''])]);
    $this->actingAs($user)->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => 'IA', 'graph' => aiGraph(['ai_prompt' => '']), 'revision' => 1,
    ])->assertOk();
    $this->postJson(route('workspaces.flows.publish', [$workspace, $flow]), ['revision' => 2])->assertUnprocessable();
    $flow->update(['graph' => aiGraph(), 'revision' => 3]);
    $this->postJson(route('workspaces.flows.publish', [$workspace, $flow]), ['revision' => 3])->assertUnprocessable();
    WorkspaceOpenAiIntegration::create(['workspace_id' => $workspace->id, 'api_key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'gpt-6.1-sol'])]);
    $this->postJson(route('workspaces.flows.publish', [$workspace, $flow]), ['revision' => 3])->assertOk();
});

test('AI opening waits in the node and a tool continues the published flow once', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-one']),
        'api.openai.com/v1/responses' => Http::response(['output' => [[
            'type' => 'function_call', 'call_id' => 'call-complete', 'name' => 'concluir_bloco_ia', 'arguments' => '{"saida":"continuar"}',
        ]]]),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    expect($execution->fresh()->status)->toBe('waiting_ai')->and($session->status)->toBe('active');
    Queue::assertPushed(ProcessAiTurn::class);
    Queue::assertPushed(ExpireAiSession::class);
    app(AiSessionService::class)->process($session->id, null);
    expect($session->fresh()->status)->toBe('completed')->and($execution->fresh()->status)->toBe('completed');
    Queue::assertPushed(CleanupAiSession::class);
    app(AiSessionService::class)->process($session->id, null);
    expect(AiSession::sole()->status)->toBe('completed');
});

test('AI greeting is sent once and expiration ignores stale timers', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-two']),
        'api.openai.com/v1/responses' => Http::response(['output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Olá! Como posso ajudar?']],
        ]]]),
        'api.uazapi.test/send/text' => Http::response(['id' => 'out-1', 'status' => 'sent']),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    app(AiSessionService::class)->process($session->id, null);
    expect($session->fresh()->status)->toBe('active')->and($session->fresh()->message_count)->toBe(0);
    app(AiSessionService::class)->expire($session->id);
    expect($session->fresh()->status)->toBe('active');
    $session->forceFill(['expires_at' => now()->subSecond()])->save();
    app(AiSessionService::class)->expire($session->id);
    expect($session->fresh()->status)->toBe('expired')->and($execution->fresh()->status)->toBe('completed');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text') && $request['text'] === 'Olá! Como posso ajudar?');
});

test('incoming message is queued by identifier and optional limit advances after one reply', function () {
    Queue::fake();
    $fixture = aiFixture(['ai_max_messages' => 1, 'ai_limit_action' => 'continue']);
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-limit']),
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Olá!']]]]])
            ->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Entendi sua dúvida.']]]]]),
        'api.uazapi.test/send/text' => Http::response(['id' => 'out-1', 'status' => 'sent']),
        'api.uazapi.test/message/find' => Http::response(['messages' => [[
            'id' => 'in-1', 'chatid' => $fixture['thread']->external_chat_id, 'messageType' => 'text',
            'text' => 'Qual é o preço?', 'fromMe' => false,
        ]]]),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    app(AiSessionService::class)->process($session->id, null);
    app(FlowInboundProcessor::class)->handle($fixture['connection'], $fixture['thread'], [
        'id' => 'in-1', 'messageType' => 'text', 'text' => 'Qual é o preço?', 'fromMe' => false,
    ]);
    Queue::assertPushed(ProcessAiTurn::class, fn ($job) => $job->sessionId === $session->id && $job->providerMessageId === 'in-1');
    app(AiSessionService::class)->process($session->id, 'in-1');
    expect($session->fresh()->message_count)->toBe(1)->and($session->fresh()->status)->toBe('completed')
        ->and($execution->fresh()->status)->toBe('completed');
    app(AiSessionService::class)->process($session->id, 'in-1');
    expect(DB::table('ai_session_inputs')->count())->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && ($request['input'][0]['content'][0]['text'] ?? null) === 'Qual é o preço?');
});

test('AI tool handoff opens human support and invalidates the session', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-human']),
        'api.openai.com/v1/responses' => Http::response(['output' => [[
            'type' => 'function_call', 'call_id' => 'call-complete', 'name' => 'concluir_bloco_ia', 'arguments' => '{"saida":"atendimento_humanizado"}',
        ]]]),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    app(AiSessionService::class)->process(AiSession::sole()->id, null);
    expect(AiSession::sole()->status)->toBe('handed_off')->and($execution->fresh()->status)->toBe('interrupted')
        ->and($fixture['thread']->fresh()->status)->toBe('open');
    expect(DB::table('automation_pauses')->where('reason', 'human')->count())->toBe(1);
});

test('OpenAI rejection opens human support and keeps the reason metadata-only', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-error']),
        'api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'secret provider error']], 401),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    app(AiSessionService::class)->process(AiSession::sole()->id, null);
    expect(AiSession::sole()->status)->toBe('handed_off')->and(AiSession::sole()->end_reason)->not->toContain('secret provider error');
});

test('cleanup deletes remote items before the conversation', function () {
    Queue::fake();
    $fixture = aiFixture();
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    $session = AiSession::create([
        'workspace_id' => $fixture['workspace']->id, 'flow_execution_id' => $execution->id,
        'flow_version_id' => $fixture['version']->id, 'contact_id' => $fixture['contact']->id,
        'thread_id' => $fixture['thread']->id, 'uazapi_connection_id' => $fixture['connection']->id,
        'node_id' => 'ai', 'status' => 'completed', 'model' => 'gpt-6.1-sol',
        'remote_conversation_id' => 'conv-clean', 'expires_at' => now(), 'ended_at' => now(),
    ]);
    $calls = [];
    $listed = 0;
    Http::fake(function ($request) use (&$calls, &$listed) {
        $calls[] = $request->method().' '.$request->url();
        if ($request->method() === 'GET') {
            return Http::response(['data' => $listed++ === 0 ? [['id' => 'item-1']] : [], 'has_more' => false]);
        }

        return Http::response(['deleted' => true]);
    });
    app(AiSessionService::class)->cleanup($session->id);
    expect($session->fresh()->cleanup_completed_at)->toBeNull();
    app(AiSessionService::class)->cleanup($session->id);
    expect($calls)->toBe([
        'GET https://api.openai.com/v1/conversations/conv-clean/items?limit=20',
        'DELETE https://api.openai.com/v1/conversations/conv-clean/items/item-1',
        'GET https://api.openai.com/v1/conversations/conv-clean/items?limit=20',
        'DELETE https://api.openai.com/v1/conversations/conv-clean',
    ])->and($session->fresh()->cleanup_completed_at)->not->toBeNull();
});

test('media inputs are fetched by message id and converted only in memory', function () {
    Queue::fake();
    $fixture = aiFixture();
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    $messages = [
        'image' => ['messageType' => 'image', 'mimetype' => 'image/png'],
        'audio' => ['messageType' => 'ptt', 'mimetype' => 'audio/ogg'],
        'document' => ['messageType' => 'document', 'mimetype' => 'application/pdf', 'fileName' => 'pedido.pdf'],
        'video' => ['messageType' => 'video', 'mimetype' => 'video/mp4'],
    ];
    Http::fake(function ($request) use ($messages, $fixture) {
        if (str_ends_with($request->url(), '/message/find')) {
            $id = $request['id'];

            return Http::response(['messages' => [array_merge($messages[$id], [
                'id' => $id, 'chatid' => $fixture['thread']->external_chat_id, 'fromMe' => false,
            ])]]);
        }
        if (str_ends_with($request->url(), '/message/download')) {
            return Http::response(['fileURL' => 'https://1.1.1.1/'.$request['id']]);
        }
        if (str_contains($request->url(), '/audio/transcriptions')) {
            return Http::response(['text' => 'Quero informações.']);
        }
        if (str_starts_with($request->url(), 'https://1.1.1.1/')) {
            return Http::response('synthetic bytes');
        }

        return Http::response([], 404);
    });
    $inbound = app(AiInboundMessage::class);
    $image = $inbound->input($session, 'image', 'sk-test-workspace');
    $audio = $inbound->input($session, 'audio', 'sk-test-workspace');
    $document = $inbound->input($session, 'document', 'sk-test-workspace');
    $video = $inbound->input($session, 'video', 'sk-test-workspace');
    expect($image['content'][0]['type'])->toBe('input_image')
        ->and($audio['content'][0]['text'])->toContain('Quero informações.')
        ->and($document['content'][0]['type'])->toBe('input_file')
        ->and($video['video'])->toBeTrue()
        ->and(DB::table('ai_sessions')->where('id', $session->id)->first()->remote_conversation_id)->toBeNull();
});

test('removing the key closes active sessions while retaining an encrypted cleanup credential', function () {
    Queue::fake();
    $fixture = aiFixture();
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    $session->forceFill(['remote_conversation_id' => 'conv-remove'])->save();
    $this->actingAs($fixture['user'])->deleteJson(route('workspaces.openai.destroy', $fixture['workspace']))
        ->assertOk()->assertJsonPath('data.configured', false);
    expect($session->fresh()->status)->toBe('handed_off')
        ->and($session->fresh()->cleanup_api_key)->toBe('sk-test-workspace')
        ->and(DB::table('ai_sessions')->value('cleanup_api_key'))->not->toContain('sk-test-workspace')
        ->and(WorkspaceOpenAiIntegration::count())->toBe(0);
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => [], 'has_more' => false]);
        }

        return Http::response(['deleted' => true]);
    });
    app(AiSessionService::class)->cleanup($session->id);
    expect($session->fresh()->cleanup_api_key)->toBeNull();
});

test('video receives guidance without asking OpenAI to analyze it', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-video']),
        'api.openai.com/v1/responses' => Http::response(['output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Olá!']],
        ]]]),
        'api.uazapi.test/message/find' => Http::response(['messages' => [[
            'id' => 'video-1', 'chatid' => $fixture['thread']->external_chat_id,
            'messageType' => 'video', 'mimetype' => 'video/mp4', 'fromMe' => false,
        ]]]),
        'api.uazapi.test/send/text' => Http::response(['id' => 'out-1', 'status' => 'sent']),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    app(AiSessionService::class)->process($session->id, null);
    app(AiSessionService::class)->process($session->id, 'video-1');
    expect($session->fresh()->status)->toBe('active')->and($session->fresh()->message_count)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text')
        && str_contains($request['text'], 'Não consigo analisar vídeos'));
    $modelCalls = collect(Http::recorded())->filter(fn ($call) => str_ends_with($call[0]->url(), '/responses'));
    expect($modelCalls)->toHaveCount(1);
});

test('inactivity may route to human without taking the continue output', function () {
    Queue::fake();
    $fixture = aiFixture(['ai_expiry_action' => 'human']);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    $session->forceFill(['expires_at' => now()->subSecond()])->save();
    app(AiSessionService::class)->expire($session->id);
    expect($session->fresh()->status)->toBe('handed_off')->and($session->fresh()->end_reason)->toBe('inactivity')
        ->and($execution->fresh()->status)->toBe('interrupted')->and($fixture['thread']->fresh()->status)->toBe('open');
});

test('an OpenAI response arriving after human interruption is not sent', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake(function ($request) use ($fixture) {
        if (str_ends_with($request->url(), '/conversations')) {
            return Http::response(['id' => 'conv-late']);
        }
        if (str_ends_with($request->url(), '/responses')) {
            app(FlowAutomationControl::class)->humanOpened($fixture['thread']);

            return Http::response(['output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Mensagem tardia']],
            ]]]);
        }

        return Http::response([], 404);
    });
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    app(AiSessionService::class)->process(AiSession::sole()->id, null);
    expect($execution->fresh()->status)->toBe('interrupted')->and(AiSession::sole()->status)->toBe('interrupted');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/send/text'));
});

test('unsupported document prompts for another format and keeps the AI session active', function () {
    Queue::fake();
    $fixture = aiFixture();
    Http::fake([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-unsupported']),
        'api.openai.com/v1/responses' => Http::response(['output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Olá!']],
        ]]]),
        'api.uazapi.test/message/find' => Http::response(['messages' => [[
            'id' => 'file-1', 'chatid' => $fixture['thread']->external_chat_id,
            'messageType' => 'document', 'mimetype' => 'application/octet-stream',
            'fileName' => 'arquivo.exe', 'fromMe' => false,
        ]]]),
        'api.uazapi.test/message/download' => Http::response(['fileURL' => 'https://1.1.1.1/file-1']),
        '1.1.1.1/*' => Http::response('synthetic bytes'),
        'api.uazapi.test/send/text' => Http::response(['id' => 'out-1', 'status' => 'sent']),
    ]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $session = AiSession::sole();
    app(AiSessionService::class)->process($session->id, null);
    app(AiSessionService::class)->process($session->id, 'file-1');
    expect($session->fresh()->status)->toBe('active')->and($session->fresh()->message_count)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text')
        && str_contains($request['text'], 'PDF, DOCX e TXT'));
});

function assistantFixture(array $overrides = []): array
{
    Queue::fake();
    Storage::fake('local');
    $fixture = aiFixture();
    $workspace = $fixture['workspace'];
    $tag = $workspace->tags()->create(['name' => 'Interessado', 'color' => '#16a34a']);
    $field = $workspace->contactCustomFields()->create(['name' => 'Curso de interesse', 'type' => 'text']);
    $privateField = $workspace->contactCustomFields()->create(['name' => 'Documento pessoal', 'type' => 'text']);
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=');
    Storage::disk('local')->put('catalogo.png', $bytes);
    $asset = FlowAsset::create(['workspace_id' => $workspace->id, 'flow_id' => $fixture['flow']->id,
        'disk' => 'local', 'path' => 'catalogo.png', 'original_name' => 'catalogo.png', 'mime_type' => 'image/png', 'size' => strlen($bytes)]);
    $data = array_merge([
        'ai_media' => [['asset_id' => $asset->id, 'instruction' => 'Enviar quando solicitar o catálogo.']],
        'ai_tags' => [['tag_id' => $tag->id, 'instruction' => 'Adicionar quando demonstrar interesse.']],
        'ai_fields' => [['field_id' => $field->id, 'instruction' => 'Registrar o curso escolhido.', 'allow_overwrite' => false]],
    ], $overrides);
    $fixture['version']->update(['graph' => aiGraph($data)]);
    $fixture['flow']->update(['graph' => aiGraph($data)]);
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id, $fixture['thread']->id, $fixture['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);

    return [...$fixture, 'tag' => $tag, 'field' => $field, 'private_field' => $privateField, 'asset' => $asset, 'session' => AiSession::sole(), 'execution' => $execution];
}

function assistantCall(string $name, array $arguments = [], string $id = 'call-action'): array
{
    return ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => json_encode($arguments ?: (object) [])];
}

function assistantText(string $text = 'Como posso ajudar?'): array
{
    return ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]];
}

function assistantFake(array $outputs, array $extra = []): void
{
    $sequence = Http::sequence();
    foreach ($outputs as $output) {
        $sequence->push(['status' => 'completed', 'output' => [$output]]);
    }
    Http::fake(array_merge([
        'api.openai.com/v1/conversations' => Http::response(['id' => 'conv-assistant']),
        'api.openai.com/v1/responses' => $sequence,
        'api.uazapi.test/send/text' => Http::response(['id' => 'text-out', 'status' => 'sent']),
        'api.uazapi.test/send/media' => Http::response(['id' => 'media-out', 'status' => 'sent']),
        'api.uazapi.test/message/find' => Http::response(['messages' => []]),
    ], $extra));
}

function assistantAction(array $fixture, string $name, array $arguments = [], string $id = 'direct-action'): array
{
    return app(AiActionService::class)->execute($fixture['session'], [
        'name' => $name, 'arguments' => $arguments, 'call_id' => $id,
    ], new AiTurnBudget);
}

test('assistant performs sequential CRM actions and returns call results before its final reply', function () {
    $f = assistantFixture();
    assistantFake([
        assistantCall('consultar_contato', [], 'read'),
        assistantCall('adicionar_tag', ['tag_id' => $f['tag']->id], 'tag'),
        assistantCall('preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Informática'], 'field'),
        assistantText('Entendi seu interesse.'),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['contact']->tags()->pluck('tags.id')->all())->toBe([$f['tag']->id])
        ->and($f['contact']->customFieldValues()->value('value'))->toBe('Informática')
        ->and($f['session']->fresh()->status)->toBe('active')
        ->and(DB::table('ai_session_actions')->count())->toBe(3);
    $requests = collect(Http::recorded())->map(fn ($call) => $call[0])->filter(fn ($request) => str_ends_with($request->url(), '/responses'))->values();
    expect($requests)->toHaveCount(4);
    expect($requests[1]['input'][0]['call_id'])->toBe('read')
        ->and($requests[1]['input'][0]['type'])->toBe('function_call_output')
        ->and(json_decode($requests[2]['input'][0]['output'], true)['status'])->toBe('added')
        ->and(json_decode($requests[3]['input'][0]['output'], true)['status'])->toBe('updated');
    Http::assertSentCount(6);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text') && $request['text'] === 'Entendi seu interesse.');
    expect(json_encode(DB::table('ai_session_actions')->get()))->not->toContain('Informática');
});

test('assistant can read uneditable custom values without notes or main contact credentials', function () {
    $f = assistantFixture();
    $f['contact']->customFieldValues()->create(['workspace_id' => $f['workspace']->id, 'field_id' => $f['private_field']->id, 'value' => 'private-document-value']);
    assistantFake([assistantCall('consultar_contato', [], 'read'), assistantText()]);
    app(AiSessionService::class)->process($f['session']->id, null);
    Http::assertSent(function ($request) use ($f) {
        if (! str_ends_with($request->url(), '/responses') || ($request['input'][0]['call_id'] ?? '') !== 'read') {
            return false;
        }
        $output = json_decode($request['input'][0]['output'], true);

        return collect($output['fields'])->firstWhere('id', $f['private_field']->id)['value'] === 'private-document-value'
            && ! isset($output['notes']) && ! isset($output['api_key']);
    });
    expect(json_encode(DB::table('ai_session_actions')->get()))->not->toContain('private-document-value');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && str_contains($request['instructions'], 'Nunca informe dados pessoais')
        && str_contains($request['instructions'], 'conteúdo não confiável')
        && $request['parallel_tool_calls'] === false);
});

test('assistant cannot mutate unlisted resources or create and remove tags', function () {
    $f = assistantFixture();
    $tag = $f['workspace']->tags()->create(['name' => 'Sem permissão', 'color' => '#16a34a']);
    foreach ([['adicionar_tag', ['tag_id' => $tag->id]], ['preencher_campo', ['field_id' => $f['private_field']->id, 'value' => 'blocked']],
        ['remover_tag', ['tag_id' => $f['tag']->id]], ['criar_tag', ['name' => 'Inventada']],
        ['enviar_midia', ['asset_id' => $f['asset']->id, 'url' => 'https://untrusted.test/file']]] as $index => [$name, $arguments]) {
        expect(assistantAction($f, $name, $arguments, 'denied-'.$index)['status'])->toBe('denied');
    }
    expect($f['contact']->tags()->count())->toBe(0)->and($f['contact']->customFieldValues()->count())->toBe(0)
        ->and(DB::table('ai_session_media_attempts')->count())->toBe(0);
});

test('assistant checks workspace ownership even for resources accidentally included in the published permissions', function () {
    $f = assistantFixture();
    $other = Workspace::factory()->create();
    $tag = $other->tags()->create(['name' => 'Outra tag', 'color' => '#16a34a']);
    $field = $other->contactCustomFields()->create(['name' => 'Outro campo', 'type' => 'text']);
    $asset = $f['asset']->replicate();
    $asset->workspace_id = $other->id;
    $asset->save();
    $f['version']->update(['graph' => aiGraph([
        'ai_tags' => [['tag_id' => $tag->id, 'instruction' => 'Aplicar']],
        'ai_fields' => [['field_id' => $field->id, 'instruction' => 'Preencher', 'allow_overwrite' => true]],
        'ai_media' => [['asset_id' => $asset->id, 'instruction' => 'Enviar']],
    ])]);
    expect(assistantAction($f, 'adicionar_tag', ['tag_id' => $tag->id], 'tag')['status'])->toBe('denied')
        ->and(assistantAction($f, 'preencher_campo', ['field_id' => $field->id, 'value' => 'blocked'], 'field')['status'])->toBe('denied')
        ->and(assistantAction($f, 'enviar_midia', ['asset_id' => $asset->id], 'media')['status'])->toBe('unavailable');
});

test('assistant tag actions are idempotent across repeated calls and field calls are deduplicated', function () {
    $f = assistantFixture();
    expect(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'tag')['status'])->toBe('added')
        ->and(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'tag')['status'])->toBe('added')
        ->and(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'tag-again')['status'])->toBe('already_present');
    expect($f['contact']->tags()->count())->toBe(1);
    assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Primeiro'], 'field');
    assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Outro'], 'field');
    expect($f['contact']->customFieldValues()->value('value'))->toBe('Primeiro');
});

test('assistant preserves a populated field including a change after reading when overwrite is off', function () {
    $f = assistantFixture();
    assistantAction($f, 'consultar_contato', [], 'read');
    $f['contact']->customFieldValues()->create(['workspace_id' => $f['workspace']->id, 'field_id' => $f['field']->id, 'value' => 'Alterado por humano']);
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Valor antigo inferido'], 'field')['status'])->toBe('preserved')
        ->and($f['contact']->customFieldValues()->value('value'))->toBe('Alterado por humano');
});

test('assistant applies last writer wins only to individually authorized overwrites', function () {
    $f = assistantFixture();
    $graph = $f['version']->graph;
    $graph['nodes'][1]['data']['ai_fields'][0]['allow_overwrite'] = true;
    $f['version']->update(['graph' => $graph]);
    assistantAction($f, 'consultar_contato', [], 'read');
    $f['contact']->customFieldValues()->create(['workspace_id' => $f['workspace']->id, 'field_id' => $f['field']->id, 'value' => 'Humano']);
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'IA'], 'field')['status'])->toBe('updated')
        ->and($f['contact']->customFieldValues()->value('value'))->toBe('IA');
});

test('assistant treats null empty and whitespace field values as empty but preserves zero', function (?string $value, string $expected) {
    $f = assistantFixture();
    $f['contact']->customFieldValues()->create(['workspace_id' => $f['workspace']->id, 'field_id' => $f['field']->id, 'value' => $value]);
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Curso'], 'field')['status'])->toBe($expected);
})->with([[null, 'updated'], ['', 'updated'], ['   ', 'updated'], ['0', 'preserved']]);

test('assistant sends media once with no caption even after a new request', function () {
    $f = assistantFixture();
    assistantFake([
        assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media-1'),
        assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media-2'),
        assistantText('O catálogo já foi enviado.'),
        assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media-3'),
        assistantText('Não posso reenviar o mesmo arquivo nesta sessão.'),
    ], ['api.uazapi.test/message/find' => Http::response(['messages' => [[
        'id' => 'new-request', 'chatid' => $f['thread']->external_chat_id, 'messageType' => 'text', 'text' => 'Manda de novo', 'fromMe' => false,
    ]]])]);
    app(AiSessionService::class)->process($f['session']->id, null);
    app(AiSessionService::class)->process($f['session']->id, 'new-request');
    app(AiSessionService::class)->process($f['session']->id, 'new-request');
    $mediaRequests = collect(Http::recorded())->filter(fn ($call) => str_ends_with($call[0]->url(), '/send/media'));
    expect($mediaRequests)->toHaveCount(1)->and(DB::table('ai_session_media_attempts')->count())->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/media') && ! isset($request['text'])
        && $request['type'] === 'image' && str_starts_with($request['file'], 'data:image/png;base64,'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && ($request['input'][0]['call_id'] ?? '') === 'media-2'
        && json_decode($request['input'][0]['output'], true)['status'] === 'already_attempted');
});

test('a new assistant entry gets its own media allowance', function () {
    $f = assistantFixture();
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantCall('concluir_bloco_ia', ['saida' => 'continuar'], 'finish'), assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText()]);
    app(AiSessionService::class)->process($f['session']->id, null);
    $execution = app(FlowRuntime::class)->start($f['version'], $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    $newSession = AiSession::latest('id')->first();
    expect($newSession->id)->not->toBe($f['session']->id)->and($newSession->remote_conversation_id)->toBeNull();
    app(AiSessionService::class)->process($newSession->id, null);
    expect(DB::table('ai_session_media_attempts')->count())->toBe(2);
});

test('uncertain media is reconciled without resending and the conversation stays active', function (bool $found, string $expected) {
    $f = assistantFixture();
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText('Não consegui confirmar o envio.')], [
        'api.uazapi.test/send/media' => Http::response([], 504),
        'api.uazapi.test/message/find' => Http::response(['messages' => $found ? [['id' => 'reconciled', 'status' => 'sent']] : []]),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_media_attempts')->value('result'))->toBe($expected)
        ->and($f['session']->fresh()->status)->toBe('active');
    expect(collect(Http::recorded())->filter(fn ($call) => str_ends_with($call[0]->url(), '/send/media')))->toHaveCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/find') && $request['track_id'] === 'ai-media-'.$f['session']->id.'-'.$f['asset']->id);
})->with([[false, 'uncertain'], [true, 'sent']]);

test('rejected media consumes its attempt but does not open human support', function () {
    $f = assistantFixture();
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText('Não consegui confirmar o envio.')], [
        'api.uazapi.test/send/media' => Http::response([], 422),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_media_attempts')->value('result'))->toBe('failed')->and($f['session']->fresh()->status)->toBe('active');
    expect(assistantAction($f, 'enviar_midia', ['asset_id' => $f['asset']->id], 'repeat')['status'])->toBe('already_attempted');
});

test('missing and altered media are blocked before a send attempt', function (bool $missing) {
    $f = assistantFixture();
    if ($missing) {
        Storage::disk('local')->delete('catalogo.png');
    } else {
        Storage::disk('local')->put('catalogo.png', 'not an image');
    }
    expect(assistantAction($f, 'enviar_midia', ['asset_id' => $f['asset']->id], 'media')['status'])->toBe('unavailable')
        ->and(DB::table('ai_session_media_attempts')->count())->toBe(0);
})->with([true, false]);

test('assistant retains confirmed CRM changes and blocks pending actions after human interruption', function () {
    $f = assistantFixture();
    assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'tag');
    assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Curso'], 'field');
    app(FlowAutomationControl::class)->humanOpened($f['thread']);
    expect(assistantAction($f, 'enviar_midia', ['asset_id' => $f['asset']->id], 'media')['status'])->toBe('inactive')
        ->and(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Tardio'], 'late')['status'])->toBe('inactive')
        ->and($f['contact']->tags()->count())->toBe(1)->and($f['contact']->customFieldValues()->value('value'))->toBe('Curso');
});

test('assistant ignores a late tool response after human interruption', function () {
    $f = assistantFixture();
    Http::fake(function ($request) use ($f) {
        if (str_ends_with($request->url(), '/conversations')) {
            return Http::response(['id' => 'conv-late-action']);
        }
        if (str_ends_with($request->url(), '/responses')) {
            app(FlowAutomationControl::class)->humanOpened($f['thread']);

            return Http::response(['output' => [assistantCall('adicionar_tag', ['tag_id' => $f['tag']->id])]]);
        }

        return Http::response([], 404);
    });
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['contact']->tags()->count())->toBe(0)->and(DB::table('ai_session_actions')->count())->toBe(0);
});

test('assistant refuses actions after expiry or a mismatched execution version', function (string $change) {
    $f = assistantFixture();
    if ($change === 'expired') {
        $f['session']->update(['expires_at' => now()->subSecond()]);
    } else {
        $version = $f['flow']->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 2, 'graph' => aiGraph(), 'published_by_user_id' => $f['user']->id, 'published_at' => now()]);
        $f['execution']->update(['flow_version_id' => $version->id]);
    }
    expect(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id])['status'])->toBe('inactive')
        ->and($f['contact']->tags()->count())->toBe(0);
})->with(['expired', 'version']);

test('assistant stops after twenty tool calls and hands off without recording content', function () {
    $f = assistantFixture();
    assistantFake(array_map(fn ($index) => assistantCall('consultar_contato', [], 'read-'.$index), range(1, 21)));
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['session']->fresh()->status)->toBe('handed_off')->and($f['session']->fresh()->end_reason)->toBe('ai_turn_action_limit')
        ->and(DB::table('ai_session_actions')->count())->toBe(20);
});

test('assistant deadline blocks a tool returned after the time budget', function () {
    $f = assistantFixture();
    $budget = new AiTurnBudget;
    app()->instance(AiTurnBudget::class, $budget);
    Http::fake(function ($request) use ($f, $budget) {
        if (str_ends_with($request->url(), '/conversations')) {
            return Http::response(['id' => 'conv-time-budget']);
        }
        $property = new ReflectionProperty($budget, 'started');
        $property->setValue($budget, hrtime(true) / 1e9 - 151);

        return Http::response(['output' => [assistantCall('adicionar_tag', ['tag_id' => $f['tag']->id])]]);
    });
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['session']->fresh()->end_reason)->toBe('ai_turn_time_limit')->and($f['session']->fresh()->status)->toBe('handed_off')
        ->and($f['contact']->tags()->count())->toBe(0);
});

test('assistant publication validates permissions instructions duplicates and available files', function (string $invalid) {
    $f = assistantFixture();
    $graph = $f['version']->graph;
    if ($invalid === 'instruction') {
        $graph['nodes'][1]['data']['ai_fields'][0]['instruction'] = '  ';
    } elseif ($invalid === 'duplicate') {
        $graph['nodes'][1]['data']['ai_tags'][] = $graph['nodes'][1]['data']['ai_tags'][0];
    } elseif ($invalid === 'missing') {
        Storage::disk('local')->delete('catalogo.png');
    } else {
        $graph['nodes'][1]['data']['ai_tags'][0]['tag_id'] = 999999;
    }
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'gpt-6.1-sol'])]);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $f['flow']))
        ->toThrow(ValidationException::class);
})->with(['instruction', 'duplicate', 'missing', 'resource']);

test('assistant permits the same resources in different nodes and rejects more than twenty media per node', function () {
    $f = assistantFixture();
    $graph = $f['version']->graph;
    $next = $graph['nodes'][1];
    $next['id'] = 'another-ai';
    $graph['nodes'][] = $next;
    expect(app(FlowGraphValidator::class)->validate($graph)['nodes'])->toHaveCount(3);
    $graph['nodes'][1]['data']['ai_media'] = array_fill(0, 21, ['instruction' => 'Ainda em rascunho']);
    expect(fn () => app(FlowGraphValidator::class)->validate($graph))->toThrow(ValidationException::class);
});

test('assistant sends the supported outbound media types through the same private asset contract', function (string $mime, string $name, string $bytes, string $type) {
    $f = assistantFixture();
    Storage::disk('local')->put('catalogo.png', $bytes);
    $f['asset']->update(['mime_type' => $mime, 'original_name' => $name, 'size' => strlen($bytes)]);
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText()]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_media_attempts')->value('result'))->toBe('sent');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/media') && $request['type'] === $type
        && $request['mimetype'] === $mime && $request['docName'] === $name && ! isset($request['text']));
})->with([
    ['video/mp4', 'catalogo.mp4', pack('N', 24).'ftypisom'.pack('N', 0).'isommp42', 'video'],
    ['audio/mpeg', 'catalogo.mp3', "\xff\xfb\x90\x64".str_repeat("\0", 413)."\xff\xfb\x90\x64".str_repeat("\0", 413), 'audio'],
    ['application/pdf', 'catalogo.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 'document'],
]);

test('assistant CRM changes survive both terminal outputs and cannot execute again', function (string $output) {
    $f = assistantFixture();
    assistantFake([
        assistantCall('adicionar_tag', ['tag_id' => $f['tag']->id], 'tag'),
        assistantCall('preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Informática'], 'field'),
        assistantCall('concluir_bloco_ia', ['saida' => $output], 'finish'),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['contact']->tags()->count())->toBe(1)->and($f['contact']->customFieldValues()->value('value'))->toBe('Informática')
        ->and($f['session']->fresh()->status)->toBe($output === 'continuar' ? 'completed' : 'handed_off')
        ->and(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'late')['status'])->toBe('inactive');
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_actions')->count())->toBe(3);
})->with(['continuar', 'atendimento_humanizado']);

test('assistant rejects invalid field values and a field removed after publication without stopping the conversation', function () {
    $f = assistantFixture();
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => ''], 'empty')['status'])->toBe('denied')
        ->and(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => str_repeat('x', 4001)], 'long')['status'])->toBe('denied');
    $f['field']->delete();
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['field']->id, 'value' => 'Curso'], 'deleted')['status'])->toBe('denied')
        ->and($f['session']->fresh()->status)->toBe('active');
});

test('draft changes never grant new assistant permissions to the current published session', function () {
    $f = assistantFixture();
    $f['flow']->update(['graph' => aiGraph(['ai_fields' => [['field_id' => $f['private_field']->id, 'instruction' => 'Atualizar']]])]);
    expect(assistantAction($f, 'preencher_campo', ['field_id' => $f['private_field']->id, 'value' => 'blocked'])['status'])->toBe('denied');
});

test('assistant checks deadlines across external requests and does not reconcile queued media with another send', function () {
    $f = assistantFixture();
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText()], [
        'api.uazapi.test/send/media' => Http::response(['id' => 'pending', 'status' => 'queued']),
        'api.uazapi.test/message/find' => Http::response(['messages' => [['id' => 'pending', 'status' => 'pending']]]),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_media_attempts')->value('result'))->toBe('uncertain');
    expect(collect(Http::recorded())->filter(fn ($call) => str_ends_with($call[0]->url(), '/send/media')))->toHaveCount(1);
});

test('assistant validates actual media size and extension before publication', function (string $invalid) {
    $f = assistantFixture();
    if ($invalid === 'size') {
        Storage::disk('local')->put('catalogo.png', str_repeat('x', 20 * 1024 * 1024 + 1));
    } else {
        $f['asset']->update(['original_name' => 'catalogo.exe']);
    }
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'gpt-6.1-sol'])]);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($f['version']->graph, $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
})->with(['size', 'extension']);

test('assistant does not act on an ambiguous reply and supplies explicit evidence and privacy instructions', function () {
    $f = assistantFixture();
    assistantFake([assistantText('Qual curso você gostaria de fazer?')]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['contact']->customFieldValues()->count())->toBe(0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && str_contains($request['instructions'], 'não invente valores') && str_contains($request['instructions'], 'nem total nem parcialmente'));
});

test('a reused assistant call id cannot switch resources or turn a denied action into a media send', function () {
    $f = assistantFixture();
    $otherTag = $f['workspace']->tags()->create(['name' => 'Não autorizada', 'color' => '#16a34a']);
    expect(assistantAction($f, 'adicionar_tag', ['tag_id' => $otherTag->id], 'same-call')['status'])->toBe('denied')
        ->and(assistantAction($f, 'enviar_midia', ['asset_id' => $f['asset']->id], 'same-call')['status'])->toBe('denied')
        ->and(DB::table('ai_session_media_attempts')->count())->toBe(0);
    expect(assistantAction($f, 'adicionar_tag', ['tag_id' => $f['tag']->id], 'same-call')['status'])->toBe('denied')
        ->and($f['contact']->tags()->count())->toBe(0);
});

test('a reused assistant call id cannot finalize an unrelated action', function () {
    $f = assistantFixture();
    assistantFake([
        assistantCall('adicionar_tag', ['tag_id' => $f['tag']->id], 'same-call'),
        assistantCall('concluir_bloco_ia', ['saida' => 'continuar'], 'same-call'),
        assistantText('Vamos continuar conversando.'),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['session']->fresh()->status)->toBe('active')->and($f['contact']->tags()->count())->toBe(1)
        ->and(DB::table('ai_session_actions')->count())->toBe(1);
});

test('assistant tracking reconciliation does not filter canonicalized phone numbers or accept the wrong tracking id', function () {
    $f = assistantFixture();
    assistantFake([assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media'), assistantText()], [
        'api.uazapi.test/send/media' => Http::response([], 504),
        'api.uazapi.test/message/find' => Http::response(['messages' => [['id' => 'other-message', 'status' => 'sent', 'track_id' => 'wrong-track']]]),
    ]);
    app(AiSessionService::class)->process($f['session']->id, null);
    expect(DB::table('ai_session_media_attempts')->value('result'))->toBe('uncertain');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/find') && isset($request['track_id']) && ! isset($request['chatid']));
});

test('assistant time exhaustion during reconciliation keeps the attempted media observable and closes the session', function () {
    $f = assistantFixture();
    $budget = new AiTurnBudget;
    app()->instance(AiTurnBudget::class, $budget);
    Http::fake(function ($request) use ($f, $budget) {
        if (str_ends_with($request->url(), '/conversations')) {
            return Http::response(['id' => 'conv-time-media']);
        }
        if (str_ends_with($request->url(), '/responses')) {
            return Http::response(['output' => [assistantCall('enviar_midia', ['asset_id' => $f['asset']->id], 'media')]]);
        }
        if (str_ends_with($request->url(), '/send/media')) {
            (new ReflectionProperty($budget, 'started'))->setValue($budget, hrtime(true) / 1e9 - 151);

            return Http::response([], 504);
        }

        return Http::response([], 404);
    });
    app(AiSessionService::class)->process($f['session']->id, null);
    expect($f['session']->fresh()->status)->toBe('handed_off')
        ->and(DB::table('ai_session_media_attempts')->value('result'))->toBe('uncertain')
        ->and(DB::table('ai_session_actions')->value('result'))->toBe('uncertain');
});

test('busy assistant sessions requeue their turn and expiry instead of opening human support', function (string $operation) {
    $f = assistantFixture();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(5)->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->with('ai-session:'.$f['session']->id, 195)->andReturn($lock);
    if ($operation === 'turn') {
        app(AiSessionService::class)->process($f['session']->id, 'queued-message-id');
        Queue::assertPushed(ProcessAiTurn::class, fn ($job) => $job->sessionId === $f['session']->id && $job->providerMessageId === 'queued-message-id');
    } else {
        app(AiSessionService::class)->expire($f['session']->id);
        Queue::assertPushed(ExpireAiSession::class, fn ($job) => $job->sessionId === $f['session']->id);
    }
    expect($f['session']->fresh()->status)->toBe('active')->and($f['execution']->fresh()->status)->toBe('waiting_ai');
})->with(['turn', 'expiry']);

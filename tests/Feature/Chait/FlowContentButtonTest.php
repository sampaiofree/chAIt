<?php

use App\Jobs\ExpireFlowWait;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\Flows\AiInboundMessage;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowContentButton;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowInboundProcessor;
use App\Services\Flows\FlowRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function contentButtonFixture(string $kind = 'reply', bool $withWait = true, array $trailing = []): array
{
    Queue::fake();
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'webhook_secret_hash' => hash('sha256', 'button-test-secret')]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => $contact->phone.'@s.whatsapp.net', 'status' => 'bot', 'human_opened_at' => null]);
    $field = $workspace->contactCustomFields()->create(['name' => 'Resposta', 'type' => 'text']);
    $profile = ['verified' => true, 'max_text_length' => 4000, 'max_label_length' => 30, 'max_value_length' => 2000, 'max_reply_id_length' => 128];
    foreach (['url', 'reply', 'copy'] as $type) {
        config(["flow-content-button.connections.{$connection->id}.{$type}" => $profile]);
    }
    $button = ['kind' => $kind, 'label' => 'Tenho interesse', ...match ($kind) {
        'url' => ['url' => 'https://example.com/cursos'], 'copy' => ['copy_text' => 'CUPOM10'], default => [],
    }];
    $item = ['id' => 'stable-button-item', 'kind' => 'button', 'text' => 'Quer conhecer os cursos?', 'button' => $button];
    $graph = ['schema_version' => 2, 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
        ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'items' => [$item, ...$trailing]]],
        ...($withWait ? [['id' => 'wait', 'type' => 'wait_reply', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'Capturar', 'field_id' => $field->id, 'timeout_seconds' => 300]]] : []),
    ], 'edges' => [
        ['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input'],
        ...($withWait ? [['id' => 'next', 'source' => 'content', 'sourceHandle' => 'next', 'target' => 'wait', 'targetHandle' => 'input']] : []),
    ], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
    $flow = $workspace->flows()->create(['name' => 'Botão', 'graph' => $graph, 'revision' => 1]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'default');
    $selection = app(FlowContentButton::class)->replyId($execution, 'content', $item['id']);

    return compact('user', 'workspace', 'contact', 'connection', 'thread', 'field', 'graph', 'flow', 'version', 'execution', 'item', 'selection');
}

function contentButtonReply(array $f, array $extra = []): array
{
    return ['messageid' => 'content-click', 'messageType' => 'ButtonsResponseMessage', 'buttonOrListid' => $f['selection'], 'chatid' => $f['thread']->external_chat_id, ...$extra];
}

function contentButtonRun(array $f): void
{
    app(FlowRuntime::class)->run($f['execution']->id);
}

function contentButtonValue(array $f): ?string
{
    return DB::table('contact_custom_field_values')->where('contact_id', $f['contact']->id)->where('field_id', $f['field']->id)->value('value');
}

test('content button sends one interactive message in sequence and never creates a wait of its own', function (string $kind) {
    $f = contentButtonFixture($kind, false);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('completed');
    expect(DB::table('flow_waits')->count())->toBe(0);
    expect(FlowCapabilities::OUTPUTS['content'])->toBe(['next']);
    Http::assertSentCount(1);
    $choice = match ($kind) {
        'url' => 'Tenho interesse|url:https://example.com/cursos',
        'copy' => 'Tenho interesse|copy:CUPOM10',
        'reply' => 'Tenho interesse|'.$f['selection'],
    };
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/menu') && $request['type'] === 'button' && $request['text'] === $f['item']['text'] && $request['choices'] === [$choice] && filled($request['track_id']) && ! isset($request['listButton']));
})->with(['url', 'reply', 'copy']);

test('content button allows incomplete drafts but reports fields and homologation for publication and assistant', function () {
    $f = contentButtonFixture('url');
    $graph = $f['graph'];
    $graph['nodes'][1]['data']['items'][0] = ['id' => 'button', 'kind' => 'button'];
    expect(app(FlowGraphValidator::class)->validate($graph)['schema_version'])->toBe(2);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
    $pending = app(AssistantTools::class)->validate($graph, $f['workspace'])['pending'];
    expect(array_column($pending, 'path'))->toContain('items.0.text', 'items.0.button.label', 'items.0.button.kind');
    expect(app(FlowGraphValidator::class)->validateForPublish($f['graph'], $f['workspace'], $f['flow'])['schema_version'])->toBe(2);
    config(["flow-content-button.connections.{$f['connection']->id}.url" => []]);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($f['graph'], $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
    expect(app(FlowCapabilities::class)->catalog($f['workspace'])['content_button']['profiles']['url'])->toBeNull();
    Http::fake();
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('running');
    Http::assertNothingSent();
});

test('content button rejects duplicate buttons unsafe values unknown kinds and forged reply ids', function (string $case) {
    $f = contentButtonFixture('url');
    $graph = $f['graph'];
    $item = &$graph['nodes'][1]['data']['items'][0];
    match ($case) {
        'duplicate' => $graph['nodes'][1]['data']['items'][] = [...$item, 'id' => 'second'],
        'pipe' => $item['button']['label'] = 'A|B',
        'newline' => $item['button']['copy_text'] = "A\nB",
        'control' => $item['button']['label'] = "A\tB",
        'scheme' => $item['button']['url'] = 'javascript:alert(1)',
        'url_pipe' => $item['button']['url'] = 'https://example.com/|copy:bad',
        'kind' => $item['button']['kind'] = 'call',
        'identity' => $item['button']['reply_id'] = 'forged',
    };
    expect(fn () => app(FlowGraphValidator::class)->validate($graph))->toThrow(ValidationException::class);
})->with(['duplicate', 'pipe', 'newline', 'control', 'scheme', 'url_pipe', 'kind', 'identity']);

test('content button honors measured provider limits', function (string $kind, string $field) {
    $f = contentButtonFixture($kind);
    $graph = $f['graph'];
    data_set($graph, 'nodes.1.data.items.0.'.$field, str_repeat('a', $field === 'button.label' ? 31 : 2001));
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
})->with([['reply', 'button.label'], ['copy', 'button.copy_text']]);

test('early content reply survives HTTP confirmation trailing text and delay and is consumed once', function () {
    $f = contentButtonFixture('reply', true, [['id' => 'pause', 'kind' => 'delay', 'seconds' => 3], ['id' => 'after', 'kind' => 'text', 'text' => 'Seguimos por aqui.']]);
    Http::fake(function ($request) use ($f) {
        if (str_ends_with($request->url(), '/send/menu')) {
            app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
        }

        return Http::response(['id' => 'sent', 'status' => 'Sent']);
    });
    contentButtonRun($f);
    expect($f['execution']->fresh()->context)->toHaveKey('early_button_reply');
    expect(contentButtonValue($f))->toBeNull();
    contentButtonRun($f); // delay
    $this->travel(4)->seconds();
    contentButtonRun($f); // trailing text
    contentButtonRun($f); // activate wait and consume
    expect(contentButtonValue($f))->toBe('Tenho interesse');
    expect(DB::table('flow_waits')->sole()->status)->toBe('replied');
    expect($f['execution']->fresh()->context)->not->toHaveKey('early_button_reply');
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    expect(DB::table('uazapi_webhook_receipts')->where('event_type', 'content_reply')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('active wait captures the original sent label and preserves the published version after draft edits', function () {
    $f = contentButtonFixture();
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    contentButtonRun($f);
    $graph = $f['graph'];
    $graph['nodes'][1]['data']['items'] = [];
    $f['flow']->update(['graph' => $graph]);
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    expect(contentButtonValue($f))->toBe('Tenho interesse');
    $wait = DB::table('flow_waits')->sole();
    $this->travel(301)->seconds();
    (new ExpireFlowWait($wait->id))->handle();
    expect(DB::table('flow_waits')->sole()->status)->toBe('replied');
    expect($f['execution']->fresh()->context['resume_handle'])->toBe('replied');
});

test('a reply without an active wait follows normal keyword triggers once without reopening its source', function () {
    $f = contentButtonFixture('reply', false);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    contentButtonRun($f);
    DB::table('flow_keyword_triggers')->insert(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'flow_id' => $f['flow']->id, 'term' => 'Tenho interesse', 'operator' => 'exact', 'interrupt_active' => false, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->travel(2)->hours();
    for ($i = 0; $i < 2; $i++) {
        app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    }
    expect($f['execution']->fresh()->status)->toBe('completed');
    expect(FlowExecution::where('flow_id', $f['flow']->id)->count())->toBe(2);
    expect(contentButtonValue($f))->toBeNull();
});

test('human pauses and interrupting keywords take precedence over early reply capture', function (string $reason) {
    $f = contentButtonFixture();
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    if ($reason === 'human') {
        $f['thread']->update(['status' => 'in_progress', 'human_opened_at' => now()]);
    } else {
        DB::table('flow_keyword_triggers')->insert(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'flow_id' => $f['flow']->id, 'term' => 'Tenho interesse', 'operator' => 'exact', 'interrupt_active' => true, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    expect(contentButtonValue($f))->toBeNull();
    expect($f['execution']->fresh()->context)->not->toHaveKey('early_button_reply');
    if ($reason === 'keyword') {
        expect($f['execution']->fresh()->status)->toBe('interrupted');
    }
})->with(['human', 'keyword']);

test('only explicit replies reserved for this connection contact and version are normalized', function (string $case) {
    $f = contentButtonFixture();
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    if ($case !== 'unsent') {
        contentButtonRun($f);
    }
    $message = contentButtonReply($f);
    $contactId = $f['contact']->id;
    $connection = $f['connection'];
    if ($case === 'text') {
        $message['messageType'] = 'Conversation';
    }
    if ($case === 'other_contact') {
        $contactId++;
    }
    if ($case === 'other_connection') {
        $connection = UazapiConnection::factory()->create();
    }
    if ($case === 'forged') {
        $message['buttonOrListid'] = 'content:'.$f['execution']->id.':'.str_repeat('0', 32);
    }
    if ($case === 'from_me') {
        $message['fromMe'] = true;
    }
    expect(app(FlowContentButton::class)->resolve($connection, $contactId, $message))->toBeNull();
})->with(['unsent', 'text', 'other_contact', 'other_connection', 'forged', 'from_me']);

test('uncertain button transport reconciles by track id without resending or duplicating its text', function (string $response) {
    $f = contentButtonFixture('url', false);
    Http::fake([
        '*/send/menu' => match ($response) {
            'timeout' => Http::failedConnection(),
            'queued' => Http::response(['id' => 'sent', 'status' => 'Queued']),
            'missing_id' => Http::response(['status' => 'Sent']),
            'server_error' => Http::response([], 503),
        },
        '*/message/find' => Http::response(['messages' => [['id' => 'found', 'status' => 'Sent']]]),
    ]);
    contentButtonRun($f);
    expect($f['execution']->fresh()->context)->toHaveKey('pending_track_id');
    contentButtonRun($f);
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('completed');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/find') && filled($request['track_id']));
})->with(['timeout', 'queued', 'missing_id', 'server_error']);

test('a definitive button rejection continues without fallback or retry', function () {
    $f = contentButtonFixture('copy');
    Http::fake(['*/send/menu' => Http::response([], 422)]);
    contentButtonRun($f);
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('waiting_reply');
    Http::assertSentCount(1);
});

test('pausing during a content delay prevents a later button send', function () {
    $f = contentButtonFixture();
    $graph = $f['graph'];
    array_unshift($graph['nodes'][1]['data']['items'], ['id' => 'before', 'kind' => 'delay', 'seconds' => 3]);
    $f['version']->update(['graph' => $graph]);
    Http::fake();
    contentButtonRun($f);
    $f['thread']->update(['status' => 'in_progress', 'human_opened_at' => now()]);
    $this->travel(4)->seconds();
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('interrupted');
    Http::assertNothingSent();
});

test('webhook receipt and early reply commit together and failed processing can be retried', function () {
    $f = contentButtonFixture();
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    $url = route('webhooks.uazapi', [$f['connection']->id, 'button-test-secret']);
    $payload = ['EventType' => 'messages', 'id' => 'button-event', 'message' => contentButtonReply($f), 'chat' => ['wa_chatid' => $f['thread']->external_chat_id, 'phone' => $f['contact']->phone]];
    $this->mock(FlowInboundProcessor::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Synthetic processing failure'));
    $this->postJson($url, $payload)->assertStatus(500);
    expect(DB::table('uazapi_webhook_receipts')->count())->toBe(0);
    app()->instance(FlowInboundProcessor::class, new FlowInboundProcessor(app(FlowRuntime::class)));
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    expect($f['execution']->fresh()->context)->toHaveKey('early_button_reply');
    expect(DB::table('uazapi_webhook_receipts')->count())->toBe(2);
    contentButtonRun($f);
    expect(contentButtonValue($f))->toBe('Tenho interesse');
    expect(json_encode(DB::table('uazapi_webhook_receipts')->get()))->not->toContain('Tenho interesse', 'Quer conhecer');
});

test('AI attendance input recognizes a content reply as text without downloading it as media', function () {
    $f = contentButtonFixture('reply', false);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    Http::fake(['*/message/find' => Http::response(['messages' => [contentButtonReply($f, ['id' => 'content-click'])]])]);
    $session = new AiSession;
    $session->setRelation('execution', $f['execution']);
    $session->flow_execution_id = $f['execution']->id;
    $input = app(AiInboundMessage::class)->input($session, 'content-click', 'unused-local-test-key');
    expect($input)->toBe(['video' => false, 'content' => [['type' => 'input_text', 'text' => 'Tenho interesse']]]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/find'));
});

test('a late reply after timeout cannot become an early reply for the expired wait', function () {
    $f = contentButtonFixture();
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    contentButtonRun($f);
    $wait = DB::table('flow_waits')->sole();
    $this->travel(301)->seconds();
    (new ExpireFlowWait($wait->id))->handle();
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    expect($f['execution']->fresh()->context)->not->toHaveKey('early_button_reply');
    expect($f['execution']->fresh()->context['resume_handle'])->toBe('not_replied');
    expect(contentButtonValue($f))->toBeNull();
});

test('human interruption clears an already buffered reply before activation of its following wait', function () {
    $f = contentButtonFixture('reply', true, [['id' => 'pause', 'kind' => 'delay', 'seconds' => 3]]);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], contentButtonReply($f));
    expect($f['execution']->fresh()->context)->toHaveKey('early_button_reply');
    contentButtonRun($f);
    $f['thread']->update(['status' => 'in_progress', 'human_opened_at' => now()]);
    $this->travel(4)->seconds();
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('interrupted');
    expect($f['execution']->fresh()->context)->not->toHaveKey('early_button_reply');
    expect(contentButtonValue($f))->toBeNull();
    expect(DB::table('flow_waits')->count())->toBe(0);
});

test('missing confirmations and temporary reconciliation failures never resend a button', function () {
    $f = contentButtonFixture('url', false);
    Http::fake(['*/send/menu' => Http::response([], 503), '*/message/find' => Http::response([], 503)]);
    contentButtonRun($f);
    for ($i = 0; $i < 24; $i++) {
        contentButtonRun($f);
    }
    expect($f['execution']->fresh()->status)->toBe('running');
    $outbound = Http::recorded(fn ($request) => str_ends_with($request->url(), '/send/menu'));
    expect($outbound)->toHaveCount(1);
    expect(DB::table('flow_events')->where('flow_execution_id', $f['execution']->id)->where('event_type', 'message_confirmed')->count())->toBe(0);
});

test('legacy text content normalizes and runs with no content button profile', function () {
    $f = contentButtonFixture('url', false);
    $graph = $f['graph'];
    unset($graph['nodes'][1]['data']['items']);
    $graph['schema_version'] = 1;
    $graph['nodes'][1]['data']['messages'] = [['id' => 'legacy', 'text' => 'Mensagem antiga']];
    config(['flow-content-button.connections' => []]);
    $f['version']->update(['graph' => $graph]);
    expect(app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $f['flow'])['schema_version'])->toBe(2);
    Http::fake(['*/send/text' => Http::response(['id' => 'legacy-sent', 'status' => 'Sent'])]);
    contentButtonRun($f);
    contentButtonRun($f);
    expect($f['execution']->fresh()->status)->toBe('completed');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text') && $request['text'] === 'Mensagem antiga');
});

test('publication rejects a measured reply id limit incompatible with the system transport identity', function () {
    $f = contentButtonFixture();
    config(["flow-content-button.connections.{$f['connection']->id}.reply.max_reply_id_length" => 20]);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($f['graph'], $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
    expect(app(AssistantTools::class)->validate($f['graph'], $f['workspace'])['pending'])->toContain(['node_id' => 'content', 'path' => 'items.0.button.kind', 'message' => 'O limite homologado não comporta o identificador de resposta do sistema.']);
});

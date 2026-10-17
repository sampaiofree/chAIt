<?php

use App\Jobs\ExpireFlowMenu;
use App\Models\Contact;
use App\Models\FlowMenuWait;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\Flows\FlowAutomationControl;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowInboundProcessor;
use App\Services\Flows\FlowMenu;
use App\Services\Flows\FlowMenuRuntime;
use App\Services\Flows\FlowRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function menuFixture(string $format = 'numeric'): array
{
    Queue::fake();
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null]);
    if ($format !== 'numeric') {
        config(["flow-menu.connections.{$connection->id}.{$format}" => ['verified' => true, 'max_options' => 4, 'max_label_length' => 30]]);
    }
    $data = array_replace(FlowMenu::defaults(), ['menu_format' => $format, 'question' => 'Como ajudar?', 'options' => [['id' => 'sales', 'label' => 'Vendas'], ['id' => 'support', 'label' => 'Suporte']]]);
    $graph = ['schema_version' => 2, 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
        ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Menu', ...$data]],
        ['id' => 'sales', 'type' => 'content', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'Vendas', 'items' => [['id' => 'sales-text', 'kind' => 'text', 'text' => 'Vendas']]]],
        ['id' => 'support', 'type' => 'content', 'position' => ['x' => 300, 'y' => 400], 'data' => ['label' => 'Suporte', 'items' => [['id' => 'support-text', 'kind' => 'text', 'text' => 'Suporte']]]],
    ], 'edges' => [
        ['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'menu', 'targetHandle' => 'input'],
        ['id' => 'sales-edge', 'source' => 'menu', 'sourceHandle' => 'option:sales', 'target' => 'sales', 'targetHandle' => 'input'],
        ['id' => 'support-edge', 'source' => 'menu', 'sourceHandle' => 'option:support', 'target' => 'support', 'targetHandle' => 'input'],
    ], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
    $flow = $workspace->flows()->create(['name' => 'Menu', 'graph' => $graph, 'revision' => 1]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'default');
    app(FlowRuntime::class)->run($execution->id);
    $wait = FlowMenuWait::sole();

    return compact('user', 'workspace', 'contact', 'connection', 'thread', 'data', 'graph', 'flow', 'version', 'execution', 'wait');
}

function sendMenuFixture(array $f): void
{
    Http::fake(['*/send/*' => Http::response(['id' => 'sent-menu', 'status' => 'Sent']), '*/message/find' => Http::response(['messages' => []])]);
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    $f['wait']->refresh();
}

function menuReply(array $f, string $text, array $extra = []): void
{
    app(FlowInboundProcessor::class)->handle($f['connection'], $f['thread'], ['messageid' => 'reply-'.uniqid(), 'messageType' => 'Conversation', 'text' => $text, ...$extra]);
}

test('menu draft keeps incomplete options while publication reports specific issues', function () {
    $f = menuFixture();
    $graph = $f['graph'];
    $graph['nodes'][1]['data']['question'] = '';
    $graph['nodes'][1]['data']['options'][0]['label'] = '';
    expect(app(FlowGraphValidator::class)->validate($graph)['schema_version'])->toBe(2);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
    expect(app(AssistantTools::class)->validate($graph, $f['workspace'])['pending'])->toContain(['node_id' => 'menu', 'path' => 'question', 'message' => 'Preencha a pergunta para o contato.']);
});

test('menu handles survive reordering and reject removed options or duplicate ids', function (string $case) {
    $f = menuFixture();
    $graph = $f['graph'];
    if ($case === 'reorder') {
        $graph['nodes'][1]['data']['options'] = array_reverse($graph['nodes'][1]['data']['options']);
        expect(app(FlowGraphValidator::class)->validate($graph)['edges'])->toBe($graph['edges']);
    } else {
        $graph['nodes'][1]['data']['options'] = $case === 'remove' ? [] : [['id' => 'sales', 'label' => 'A'], ['id' => 'sales', 'label' => 'B']];
        expect(fn () => app(FlowGraphValidator::class)->validate($graph))->toThrow(ValidationException::class);
    }
})->with(['reorder', 'remove', 'duplicate']);

test('menu configuration validates timeout and invalid response bounds', function (string $field, int $value) {
    $f = menuFixture();
    $graph = $f['graph'];
    $graph['nodes'][1]['data'][$field] = $value;
    expect(fn () => app(FlowGraphValidator::class)->validate($graph))->toThrow(ValidationException::class);
})->with([['timeout_seconds', 59], ['timeout_seconds', 604801], ['max_invalid_replies', 0], ['max_invalid_replies', 11]]);

test('interactive menu publication requires a verified connection and measured limits', function (string $format) {
    $f = menuFixture($format);
    expect(app(FlowGraphValidator::class)->validateForPublish($f['graph'], $f['workspace'], $f['flow'])['schema_version'])->toBe(2);
    config(["flow-menu.connections.{$f['connection']->id}.{$format}" => []]);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($f['graph'], $f['workspace'], $f['flow']))->toThrow(ValidationException::class);
})->with(['button', 'list']);

test('numeric menu sends enumerated choices and retains the active reservation', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    expect($f['execution']->fresh()->status)->toBe('waiting_menu');
    expect($f['wait']->status)->toBe('waiting')->and($f['wait']->expires_at->diffInSeconds($f['wait']->sent_at, true))->toBe(300.0);
    expect(FlowExecutionManager::ACTIVE)->toContain('waiting_menu');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/text') && str_contains($request['text'], "1. Vendas\n2. Suporte"));
    expect(app(FlowRuntime::class)->start($f['version'], $f['contact']->id, $f['thread']->id, $f['connection'], 'sequence'))->toBeNull();
});

test('numeric choice accepts only the exact advertised number', function (string $text, bool $valid) {
    $f = menuFixture();
    sendMenuFixture($f);
    menuReply($f, $text);
    expect($f['wait']->fresh()->status)->toBe($valid ? 'selected' : 'waiting');
    if ($valid) {
        expect($f['execution']->fresh()->context['resume_handle'])->toBe('option:support');
    } else {
        expect($f['wait']->fresh()->invalid_count)->toBe(1);
    }
})->with([['2', true], [' 2 ', true], ['02', false], ['2.', false], ['Suporte', false], ['Quero 2', false], ['3', false], ['0', false]]);

test('invalid response sends configured guidance and repeats choices without renewing the deadline', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    $expires = $f['wait']->expires_at->toIso8601String();
    menuReply($f, 'não sei');
    $this->travel(30)->seconds();
    app(FlowMenuRuntime::class)->process($f['wait']->id); // guidance
    app(FlowMenuRuntime::class)->process($f['wait']->id); // repeat
    expect($f['wait']->fresh()->expires_at->toIso8601String())->toBe($expires);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request['text'] === $f['data']['invalid_reply_text']);
    menuReply($f, 'x');
    menuReply($f, 'y');
    expect($f['wait']->fresh()->status)->toBe('invalid_limit')->and($f['execution']->fresh()->context['resume_handle'])->toBe('continue');
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    Http::assertSentCount(3);
});

test('expiry wins once and late replies and jobs cannot reactivate a menu', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    $this->travel(301)->seconds();
    (new ExpireFlowMenu($f['wait']->id))->handle(app(FlowMenuRuntime::class));
    menuReply($f, '1');
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    app(FlowMenuRuntime::class)->expire($f['wait']->id);
    expect($f['wait']->fresh()->status)->toBe('timed_out')->and($f['execution']->fresh()->context['resume_handle'])->toBe('continue');
    expect(DB::table('flow_events')->where('event_type', 'menu_timed_out')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('selection wins over an obsolete expiry job and routes to the chosen branch', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    menuReply($f, '1');
    $this->travel(301)->seconds();
    app(FlowMenuRuntime::class)->expire($f['wait']->id);
    app(FlowRuntime::class)->run($f['execution']->id);
    expect($f['wait']->fresh()->selected_option_id)->toBe('sales')->and($f['execution']->fresh()->current_node_id)->toBe('sales');
});

test('duplicate receipts and stale or technical events never consume another attempt', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    menuReply($f, 'x', ['messageid' => 'same']);
    menuReply($f, 'x', ['messageid' => 'same']);
    menuReply($f, 'x', ['messageType' => 'reaction']);
    menuReply($f, 'x', ['fromMe' => true]);
    menuReply($f, 'x', ['messageTimestamp' => now()->subMinute()->timestamp * 1000]);
    menuReply($f, 'x', ['buttonOrListid' => 'menu:expired:option']);
    expect($f['wait']->fresh()->invalid_count)->toBe(1);
    expect(DB::table('flow_menu_receipts')->count())->toBe(1);
});

test('media is an invalid numeric response without storing its body', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    menuReply($f, 'private media caption', ['messageType' => 'image', 'content' => ['fileURL' => 'private-url']]);
    expect($f['wait']->fresh()->invalid_count)->toBe(1);
    expect(json_encode($f['wait']->fresh()->getAttributes()))->not->toContain('private media caption', 'private-url');
});

test('interactive formats require the current selection token rather than matching text', function (string $format) {
    $f = menuFixture($format);
    sendMenuFixture($f);
    menuReply($f, 'Vendas');
    menuReply($f, '1');
    expect($f['wait']->fresh()->invalid_count)->toBe(2);
    menuReply($f, 'Vendas', ['buttonOrListid' => app(FlowMenuRuntime::class)->selectionId($f['wait'], 'sales'), 'messageType' => $format === 'button' ? 'ButtonsResponseMessage' : 'ListResponseMessage']);
    expect($f['wait']->fresh()->status)->toBe('selected')->and($f['wait']->fresh()->selected_option_id)->toBe('sales');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/menu') && $request['type'] === $format && str_starts_with($request['choices'][0], 'Vendas|menu:'));
})->with(['button', 'list']);

test('confirmed interactive rejection falls back only after numeric transport succeeds', function () {
    $f = menuFixture('button');
    Http::fake(['*/send/menu' => Http::response(['error' => 'unsupported'], 400), '*/send/text' => Http::response(['id' => 'numeric', 'status' => 'Sent'])]);
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    expect($f['wait']->fresh()->effective_format)->toBe('button')->and($f['wait']->fresh()->expires_at)->toBeNull();
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    expect($f['wait']->fresh()->effective_format)->toBe('numeric');
    menuReply($f, '2');
    expect($f['wait']->fresh()->selected_option_id)->toBe('support');
});

test('uncertain transport reconciles without repeating a menu', function () {
    $f = menuFixture('list');
    Http::fake(['*/send/menu' => Http::response(['error' => 'unknown'], 500), '*/message/find' => Http::response(['messages' => [['id' => 'confirmed', 'status' => 'Sent']]])]);
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    $this->travel(5)->seconds();
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    expect($f['wait']->fresh()->status)->toBe('waiting')->and($f['wait']->fresh()->effective_format)->toBe('list');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/send/text'));
});

test('unconfirmed send exhausts a bounded query budget and fails rather than continuing', function () {
    $f = menuFixture();
    Http::fake(['*/send/text' => Http::response(['id' => 'pending', 'status' => 'Queued']), '*/message/find' => Http::response(['messages' => []])]);
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    for ($attempt = 0; $attempt < 24; $attempt++) {
        $this->travel(5)->seconds();
        app(FlowMenuRuntime::class)->process($f['wait']->id);
    }
    expect($f['wait']->fresh()->status)->toBe('failed')->and($f['execution']->fresh()->status)->toBe('failed');
    expect($f['execution']->fresh()->context)->not->toHaveKey('resume_handle');
    Http::assertSentCount(25);
});

test('pause or human opening cancels menu transport and ignores delayed jobs', function (string $action) {
    $f = menuFixture();
    sendMenuFixture($f);
    if ($action === 'pause') {
        app(FlowAutomationControl::class)->pause($f['thread'], $f['user']->id, now()->addMinute());
    } else {
        app(FlowAutomationControl::class)->humanOpened($f['thread']);
    }
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    menuReply($f, '1');
    expect($f['wait']->fresh()->status)->toBe('cancelled')->and($f['execution']->fresh()->status)->toBe('interrupted');
    Http::assertSentCount(1);
})->with(['pause', 'human']);

test('menu recovery covers waits outside sequences', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    $this->travel(301)->seconds();
    app(FlowMenuRuntime::class)->tick();
    Queue::assertPushed(ExpireFlowMenu::class, 2);
});

test('a reply arriving before HTTP acknowledgement is consumed only after accepted transport', function () {
    $f = menuFixture();
    Http::fake(['*/send/text' => function () use ($f) {
        // Simulates a second webhook handler while the transport call is in flight.
        menuReply($f, '2');
        expect($f['wait']->fresh()->status)->toBe('sending');

        return Http::response(['id' => 'accepted', 'status' => 'Sent']);
    }]);
    app(FlowMenuRuntime::class)->process($f['wait']->id);
    expect($f['wait']->fresh()->status)->toBe('selected')->and($f['wait']->fresh()->selected_option_id)->toBe('support');
});

test('ordinary text cannot masquerade as a click even with a selection-shaped field', function (string $format) {
    $f = menuFixture($format);
    sendMenuFixture($f);
    menuReply($f, 'Vendas', ['buttonOrListid' => app(FlowMenuRuntime::class)->selectionId($f['wait'], 'sales')]);
    expect($f['wait']->fresh()->status)->toBe('waiting')->and($f['wait']->fresh()->invalid_count)->toBe(1);
})->with(['button', 'list']);

test('numeric menus ignore interactive selections instead of consuming a numeric attempt', function () {
    $f = menuFixture();
    sendMenuFixture($f);
    menuReply($f, '1', ['messageType' => 'ButtonsResponseMessage', 'buttonOrListid' => app(FlowMenuRuntime::class)->selectionId($f['wait'], 'sales')]);
    expect($f['wait']->fresh()->status)->toBe('waiting')->and($f['wait']->fresh()->invalid_count)->toBe(0);
});

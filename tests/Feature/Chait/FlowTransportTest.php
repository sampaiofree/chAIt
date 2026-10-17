<?php

use App\Models\Contact;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiRun;
use App\Models\FlowAsset;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowTransport;
use App\Services\Flows\FlowTransportContract;
use App\Services\Flows\FlowTransportGuide;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

function transportFixture(): array
{
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Nome original', 'revision' => 1, 'graph' => [
        'schema_version' => 2, 'nodes' => [['id' => 'original_start', 'type' => 'start', 'position' => ['x' => 1, 'y' => 2], 'data' => ['label' => 'Início']]],
        'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ]]);
    Queue::fake();

    return compact('user', 'workspace', 'flow');
}

function transportDocument(): array
{
    return app(FlowTransportGuide::class)->examples()[0];
}

function transportUrl(array $fixture, string $path): string
{
    return '/workspaces/'.$fixture['workspace']->id.'/flows/'.$fixture['flow']->id.$path;
}

function transportInput(array $fixture, ?array $document = null): array
{
    $session = app(DraftSessionService::class)->current($fixture['flow']);

    return ['json' => json_encode($document ?? transportDocument()), 'base_revision' => $fixture['flow']->fresh()->revision, 'generation' => $session?->generation, 'draft_revision' => $session?->revision];
}

test('transport validates without effects and confirmed import saves inside the original flow', function () {
    $f = transportFixture();
    $folder = $f['workspace']->flowFolders()->create(['name' => 'Pasta']);
    $f['flow']->update(['folder_id' => $folder->id]);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import/validate'), transportInput($f))->assertOk()->assertJsonPath('data.valid', true);
    expect($f['workspace']->tags()->count())->toBe(0)->and($f['workspace']->contactCustomFields()->count())->toBe(0)->and($f['flow']->fresh()->revision)->toBe(1);
    $this->postJson(transportUrl($f, '/import'), transportInput($f))->assertOk()->assertJsonPath('data.state.flow.name', 'Nome original')->assertJsonPath('data.state.flow.revision', 2)->assertJsonPath('data.state.session', null);
    $saved = $f['flow']->fresh();
    expect($saved->folder_id)->toBe($folder->id)->and($saved->graph['schema_version'])->toBe(2)->and($saved->graph['nodes'])->toHaveCount(5)
        ->and($saved->graph['nodes'][0]['id'])->not->toBe('inicio')->and($saved->published_version_id)->toBeNull()->and($f['workspace']->tags()->count())->toBe(1)->and($f['workspace']->contactCustomFields()->count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('transport reuses exact names without changing colors or duplicating resources', function () {
    $f = transportFixture();
    $tag = $f['workspace']->tags()->create(['name' => 'Contato identificado', 'color' => '#123456']);
    $field = $f['workspace']->contactCustomFields()->create(['name' => 'Nome informado', 'type' => 'text']);
    $document = transportDocument();
    $document['dependencies']['tags'][0]['name'] = ' Contato identificado ';
    $document['dependencies']['tags'][0]['color'] = '#ffffff';
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertOk();
    $this->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertOk();
    expect($f['workspace']->tags()->count())->toBe(1)->and($tag->fresh()->color)->toBe('#123456')->and($f['workspace']->contactCustomFields()->count())->toBe(1);
    $graph = $f['flow']->fresh()->graph;
    expect($graph['nodes'][2]['data']['field_id'])->toBe($field->id)->and($graph['nodes'][3]['data']['actions'][0]['tag_id'])->toBe($tag->id);
});

test('transport rejects incompatible fields and rolls back resources created before a structural failure', function () {
    $f = transportFixture();
    $field = $f['workspace']->contactCustomFields()->create(['name' => 'Nome informado', 'type' => 'number']);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f))->assertUnprocessable();
    expect($f['workspace']->tags()->count())->toBe(0)->and($f['flow']->fresh()->revision)->toBe(1);
    $field->delete();
    $document = transportDocument();
    $document['edges'][] = ['source' => 'sucesso', 'output' => 'next', 'target' => 'pergunta'];
    $this->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertUnprocessable();
    expect($f['workspace']->tags()->count())->toBe(0)->and($f['workspace']->contactCustomFields()->count())->toBe(0)->and($f['flow']->fresh()->graph['nodes'][0]['id'])->toBe('original_start');
});

test('transport rejects invalid graphs and unknown nested properties without discarding anything', function (Closure $change) {
    $f = transportFixture();
    $document = transportDocument();
    $change($document);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertUnprocessable();
    expect($f['flow']->fresh()->revision)->toBe(1)->and($f['workspace']->tags()->count())->toBe(0);
})->with([
    'unknown type' => [fn (&$d) => $d['nodes'][1]['type'] = 'execute_code'],
    'unknown config' => [fn (&$d) => $d['nodes'][1]['data']['script'] = 'unsafe'],
    'nested credential' => [fn (&$d) => $d['nodes'][1]['data']['items'][0]['token'] = 'secret'],
    'raw workspace resource ID' => [fn (&$d) => $d['nodes'][2]['data']['field_id'] = 1],
    'undeclared resource' => [fn (&$d) => $d['nodes'][2]['data']['field_ref'] = 'inventado'],
    'unknown output' => [fn (&$d) => $d['edges'][0]['output'] = 'inventada'],
    'missing target' => [fn (&$d) => $d['edges'][0]['target'] = 'inexistente'],
    'duplicate node' => [fn (&$d) => $d['nodes'][1]['id'] = 'inicio'],
    'duplicate output' => [fn (&$d) => $d['edges'][] = $d['edges'][0]],
    'missing Start' => [fn (&$d) => $d['nodes'][0]['type'] = 'content'],
    'duplicate dependency name' => [fn (&$d) => $d['dependencies']['fields'][] = ['id' => 'outro', 'name' => 'Nome informado', 'type' => 'text']],
    'future version' => [fn (&$d) => $d['schema_version'] = '2.0'],
    'legacy graph' => [fn (&$d) => $d['schema_version'] = 2],
    'unknown envelope' => [fn (&$d) => $d['workspace_id'] = 1],
    'too many nodes' => [fn (&$d) => $d['nodes'] = array_fill(0, 201, $d['nodes'][0])],
    'null AI media row' => [fn (&$d) => $d['nodes'][] = ['id' => 'ai', 'type' => 'ai', 'data' => ['label' => 'IA', 'ai_media' => [null]]]],
    'null AI tag row' => [fn (&$d) => $d['nodes'][] = ['id' => 'ai', 'type' => 'ai', 'data' => ['label' => 'IA', 'ai_tags' => [null]]]],
    'null AI field row' => [fn (&$d) => $d['nodes'][] = ['id' => 'ai', 'type' => 'ai', 'data' => ['label' => 'IA', 'ai_fields' => [null]]]],
]);

test('transport rejects malformed, oversized and deeply nested JSON', function () {
    $f = transportFixture();
    foreach (['{invalid', '[]', str_repeat(' ', FlowTransportContract::MAX_BYTES + 1).json_encode(transportDocument()), str_repeat('{"x":', 65).'0'.str_repeat('}', 65)] as $json) {
        $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), ['json' => $json, 'base_revision' => 1])->assertUnprocessable();
    }
    expect($f['flow']->fresh()->revision)->toBe(1);
});

test('transport requires flow editing but no AI permission or credential, and isolates workspaces', function () {
    $f = transportFixture();
    $agent = User::factory()->create();
    WorkspaceMembership::where('user_id', $agent->id)->delete();
    $agent->update(['current_workspace_id' => $f['workspace']->id]);
    $membership = WorkspaceMembership::create(['workspace_id' => $f['workspace']->id, 'user_id' => $agent->id, 'role' => 'agent', 'joined_at' => now(), 'can_manage_flows' => false, 'can_use_flow_assistant' => false]);
    $this->actingAs($agent)->postJson(transportUrl($f, '/import'), transportInput($f))->assertForbidden();
    $this->get(transportUrl($f, '/export?source=draft'))->assertForbidden();
    $this->get(transportUrl($f, '/ai-guide'))->assertForbidden();
    $membership->update(['can_manage_flows' => true]);
    $this->postJson(transportUrl($f, '/import'), transportInput($f))->assertOk();
    expect($f['workspace']->tags()->first()->created_by_user_id)->toBe($agent->id);
    $other = Workspace::factory()->create();
    $this->postJson('/workspaces/'.$other->id.'/flows/'.$f['flow']->id.'/import', transportInput($f))->assertNotFound();
    Http::assertNothingSent();
});

test('transport detects saved and preview revision conflicts before creating resources', function () {
    $f = transportFixture();
    $old = transportInput($f);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import/validate'), $old)->assertOk();
    $session = app(DraftSessionService::class)->ensure($f['flow']);
    $this->postJson(transportUrl($f, '/import'), $old)->assertConflict();
    $expected = transportInput($f);
    $session->increment('revision');
    $this->postJson(transportUrl($f, '/import'), $expected)->assertConflict();
    $expected = transportInput($f);
    $f['flow']->increment('revision');
    $this->postJson(transportUrl($f, '/import'), $expected)->assertConflict();
    expect($f['workspace']->tags()->count())->toBe(0)->and($session->fresh())->not->toBeNull();
});

test('transport blocks active AI and invalidates old plans and generation after stopping', function () {
    $f = transportFixture();
    $session = app(DraftSessionService::class)->ensure($f['flow']);
    $session->update(['name' => 'Nome da prévia']);
    $conversation = FlowAiConversation::create(['flow_id' => $f['flow']->id, 'workspace_id' => $f['workspace']->id, 'state' => 'editing']);
    $plan = FlowAiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'kind' => 'plan', 'status' => 'approved', 'approved_at' => now(), 'generation' => $session->generation, 'content' => 'Plano antigo']);
    $conversation->update(['approved_plan_id' => $plan->id]);
    $run = FlowAiRun::create(['conversation_id' => $conversation->id, 'user_id' => $f['user']->id, 'generation' => $session->generation, 'model' => 'gpt-6.1-sol', 'purpose' => 'building', 'status' => 'queued']);
    $expected = transportInput($f);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), $expected)->assertConflict();
    $run->update(['status' => 'paused']);
    $this->postJson(transportUrl($f, '/import'), $expected)->assertOk()->assertJsonPath('data.state.flow.name', 'Nome original');
    expect($conversation->fresh()->state)->toBe('briefing')->and($conversation->fresh()->approved_plan_id)->toBeNull()->and($plan->fresh()->status)->toBe('superseded')->and(FlowAiMessage::where('conversation_id', $conversation->id)->where('kind', 'session')->exists())->toBeTrue();
    $this->putJson(transportUrl($f, '/draft-session'), $expected + ['name' => 'Tardia', 'graph' => $session->graph])->assertConflict();
    expect(app(DraftSessionService::class)->current($f['flow']))->toBeNull();
    expect(fn () => app(AssistantTools::class)->execute($f['flow']->fresh(), $run->fresh(), 'late-call', 'apply_flow_patch', ['expected_draft_revision' => $session->revision, 'operations' => []]))->toThrow(HttpException::class);
    WorkspaceOpenAiIntegration::create(['workspace_id' => $f['workspace']->id, 'api_key' => 'sk-synthetic-transport-test']);
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], config('flow-assistant.models'))])]);
    $this->postJson(transportUrl($f, '/assistant/runs/'.$run->id.'/resume'), transportInput($f))->assertConflict();
    expect(FlowAiRun::where('conversation_id', $conversation->id)->count())->toBe(1);
});

test('transport preserves publication and exports only the selected persisted version', function () {
    $f = transportFixture();
    $oldGraph = $f['flow']->graph;
    $version = $f['flow']->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 1, 'graph' => $oldGraph, 'published_by_user_id' => $f['user']->id, 'published_at' => now()]);
    $f['flow']->update(['published_version_id' => $version->id, 'is_active' => true]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $f['workspace']->id]);
    DB::table('flow_trigger_settings')->insert(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $connection->id, 'welcome_flow_id' => $f['flow']->id, 'default_flow_id' => $f['flow']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('flow_keyword_triggers')->insert(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $connection->id, 'flow_id' => $f['flow']->id, 'term' => 'oi', 'operator' => 'exact', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $contact = Contact::factory()->create(['workspace_id' => $f['workspace']->id]);
    $thread = Thread::factory()->create(['workspace_id' => $f['workspace']->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id]);
    $execution = FlowExecution::create(['workspace_id' => $f['workspace']->id, 'flow_id' => $f['flow']->id, 'flow_version_id' => $version->id, 'contact_id' => $contact->id, 'thread_id' => $thread->id, 'uazapi_connection_id' => $connection->id, 'status' => 'running', 'trigger_source' => 'keyword', 'current_node_id' => 'original_start', 'started_at' => now()]);
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f))->assertOk();
    $session = app(DraftSessionService::class)->ensure($f['flow']->fresh());
    $session->update(['name' => 'Não salvo']);
    $draft = $this->get(transportUrl($f, '/export?source=draft'))->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8')->json();
    $published = $this->get(transportUrl($f, '/export?source=published'))->assertOk()->json();
    expect($draft['name'])->toBe('Nome original')->and($draft['nodes'])->toHaveCount(5)->and($published['nodes'])->toHaveCount(1)
        ->and($version->fresh()->graph)->toBe($oldGraph)->and($f['flow']->fresh()->is_active)->toBeTrue()->and($f['flow']->fresh()->published_version_id)->toBe($version->id);
    expect(DB::table('flow_trigger_settings')->where('uazapi_connection_id', $connection->id)->value('welcome_flow_id'))->toBe($f['flow']->id)
        ->and(DB::table('flow_keyword_triggers')->where('uazapi_connection_id', $connection->id)->value('term'))->toBe('oi')
        ->and($execution->fresh()->status)->toBe('running')->and($execution->fresh()->flow_version_id)->toBe($version->id);
});

test('transport sharing associates only resources in the destination workspace', function () {
    $source = transportFixture();
    $this->actingAs($source['user'])->postJson(transportUrl($source, '/import'), transportInput($source))->assertOk();
    $export = $this->get(transportUrl($source, '/export?source=draft'))->assertOk()->json();
    $target = transportFixture();
    $tag = $target['workspace']->tags()->create(['name' => 'Contato identificado', 'color' => '#112233']);
    $field = $target['workspace']->contactCustomFields()->create(['name' => 'Nome informado', 'type' => 'text']);
    $this->actingAs($target['user'])->postJson(transportUrl($target, '/import'), transportInput($target, $export))->assertOk();
    $graph = $target['flow']->fresh()->graph;
    expect($graph['nodes'][2]['data']['field_id'])->toBe($field->id)->and($graph['nodes'][3]['data']['actions'][0]['tag_id'])->toBe($tag->id)
        ->and($target['workspace']->tags()->count())->toBe(1)->and($source['workspace']->tags()->count())->toBe(1);
    expect($field->id)->not->toBe($source['workspace']->contactCustomFields()->first()->id);
});

test('transport retains media slots, menu option identities and incomplete destinations', function () {
    $f = transportFixture();
    $document = app(FlowTransportGuide::class)->examples()[1];
    $document['nodes'][] = ['id' => 'jump', 'type' => 'jump_flow', 'data' => ['label' => 'Destino pendente']];
    $document['nodes'][] = ['id' => 'notificar', 'type' => 'operations', 'data' => ['label' => 'Notificar', 'actions' => [['id' => 'notificacao', 'kind' => 'notify_member', 'text' => 'Ajude este contato.'], ['id' => 'sequencia', 'kind' => 'add_to_sequence']]]];
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertOk();
    $graph = $f['flow']->fresh()->graph;
    expect($graph['nodes'][2]['data']['items'][0]['original_name'])->toBe('Catálogo de produtos.pdf')->and($graph['nodes'][2]['data']['items'][0])->not->toHaveKey('asset_id')
        ->and($graph['nodes'][1]['data']['options'][0]['id'])->toBe('vendas')->and($graph['edges'][1]['sourceHandle'])->toBe('option:vendas');
    $this->postJson(transportUrl($f, '/publish'), ['revision' => 2])->assertUnprocessable();
    $export = $this->get(transportUrl($f, '/export?source=draft'))->assertOk()->json();
    $this->postJson(transportUrl($f, '/import'), transportInput($f, $export))->assertOk();
    expect($f['flow']->fresh()->graph['nodes'])->toHaveCount(6);
});

test('transport preserves text, condition and button settings and allows terminal unconnected outputs', function () {
    $f = transportFixture();
    $document = transportDocument();
    $document['nodes'][1]['data']['items'][] = ['id' => 'button', 'kind' => 'button', 'text' => 'Acesse nosso site', 'button' => ['kind' => 'url', 'label' => 'Site', 'url' => 'https://example.com']];
    $document['nodes'][] = ['id' => 'condicao', 'type' => 'condition', 'data' => ['label' => 'Interesse', 'condition_operator' => 'any', 'checks' => [['kind' => 'tag', 'tag_ref' => 'identificado', 'operator' => 'has'], ['kind' => 'field', 'field_key' => 'email', 'operator' => 'contains', 'value' => '@']]]];
    $this->actingAs($f['user'])->postJson(transportUrl($f, '/import'), transportInput($f, $document))->assertOk();
    $export = $this->get(transportUrl($f, '/export?source=draft'))->assertOk()->json();
    expect($export['nodes'][1]['data']['items'][1]['button'])->toBe($document['nodes'][1]['data']['items'][1]['button'])->and($export['nodes'][5]['data']['checks'][1]['field_key'])->toBe('email');
    $simple = ['format' => 'chait-flow', 'schema_version' => '1.0', 'name' => 'Terminal', 'dependencies' => ['tags' => [], 'fields' => []], 'nodes' => [$document['nodes'][0], $document['nodes'][4]], 'edges' => [['source' => 'inicio', 'output' => 'next', 'target' => 'prazo']]];
    $this->postJson(transportUrl($f, '/import'), transportInput($f, $simple))->assertOk();
    $this->postJson(transportUrl($f, '/publish'), ['revision' => 3])->assertOk();
});

test('transport exports media hints without private identifiers and resets only declared AI options', function () {
    $f = transportFixture();
    $tag = $f['workspace']->tags()->create(['name' => 'VIP', 'color' => '#16a34a']);
    $field = $f['workspace']->contactCustomFields()->create(['name' => 'Interesse', 'type' => 'text']);
    $asset = FlowAsset::create(['workspace_id' => $f['workspace']->id, 'flow_id' => $f['flow']->id, 'disk' => 'local', 'path' => 'private/secret.pdf', 'original_name' => 'Catálogo.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
    $graph = $f['flow']->graph;
    $graph['nodes'][] = ['id' => 'ai', 'type' => 'ai', 'position' => ['x' => 0, 'y' => 500], 'data' => ['label' => 'Consultor', ...FlowCapabilities::aiDefaults(), 'ai_prompt' => 'Atenda com cuidado.', 'ai_model' => 'gpt-6-astra', 'ai_inactivity_seconds' => 900, 'ai_expiry_action' => 'human', 'ai_max_messages' => 5, 'ai_limit_action' => 'continue',
        'ai_media' => [['id' => 'catalogo', 'asset_id' => $asset->id, 'instruction' => 'Envie quando solicitado.']],
        'ai_tags' => [['id' => 'vip', 'tag_id' => $tag->id, 'instruction' => 'Clientes prioritários.']],
        'ai_fields' => [['id' => 'interesse', 'field_id' => $field->id, 'instruction' => 'Registre o interesse.', 'allow_overwrite' => true]],
        'api_key' => 'must-not-export',
    ]];
    $f['flow']->update(['graph' => $graph]);
    $export = $this->actingAs($f['user'])->get(transportUrl($f, '/export?source=draft'))->assertOk()->json();
    $encoded = json_encode($export);
    foreach (['asset_id', 'tag_id', 'field_id', 'api_key', 'must-not-export', 'private/secret.pdf'] as $private) {
        expect($encoded)->not->toContain($private);
    }
    expect($export['nodes'][1]['data']['ai_model'])->toBe('gpt-6-astra')->and($export['nodes'][1]['data']['ai_media'][0]['original_name'])->toBe('Catálogo.pdf');
    $this->postJson(transportUrl($f, '/import'), transportInput($f, $export))->assertOk();
    $ai = $f['flow']->fresh()->graph['nodes'][1]['data'];
    expect($ai['ai_prompt'])->toBe('Atenda com cuidado.')->and($ai['ai_model'])->toBe('gpt-6.1-sol')->and($ai['ai_inactivity_seconds'])->toBe(900)->and($ai['ai_max_messages'])->toBe(5)
        ->and($ai['ai_expiry_action'])->toBe('end')->and($ai['ai_limit_action'])->toBe('human')->and($ai['ai_fields'][0]['allow_overwrite'])->toBeFalse()
        ->and($ai['ai_fields'][0]['field_id'])->toBe($field->id)->and($ai['ai_tags'][0]['tag_id'])->toBe($tag->id)->and($ai['ai_media'][0]['instruction'])->toBe('Envie quando solicitado.');
    expect($asset->fresh())->not->toBeNull();
});

test('transport keeps valid positions and deterministically lays out all nodes otherwise', function () {
    $f = transportFixture();
    $document = transportDocument();
    foreach ($document['nodes'] as $index => &$node) {
        $node['position'] = ['x' => 100 + $index, 'y' => 500 + $index];
    }
    unset($node);
    $service = app(FlowTransport::class);
    $parsed = $service->decode(json_encode($document));
    $graph = $service->graph($parsed, $f['workspace']);
    expect($graph['nodes'][0]['position'])->toBe(['x' => 100.0, 'y' => 500.0]);
    $parsed['nodes'][0]['position'] = 'inutilizável';
    $one = $service->graph($parsed, $f['workspace']);
    $two = $service->graph($parsed, $f['workspace']);
    expect(array_column($one['nodes'], 'position'))->toBe(array_column($two['nodes'], 'position'))->and(array_unique(array_map('json_encode', array_column($one['nodes'], 'position'))))->toHaveCount(5);
});

test('transport guide examples are importable and guide excludes AI construction and positioning', function () {
    $f = transportFixture();
    $guide = app(FlowTransportGuide::class);
    foreach ($guide->examples() as $document) {
        $this->actingAs($f['user'])->postJson(transportUrl($f, '/import/validate'), transportInput($f, $document))->assertOk();
    }
    $response = $this->get(transportUrl($f, '/ai-guide'))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    expect($response->getContent())->not->toContain('"const": "ai"', 'ai_prompt', 'position', 'sourceHandle', 'viewport')->toContain('field_ref', 'option:', 'button', 'start');
    $schema = app(FlowTransportContract::class)->schema();
    expect($schema['properties']['nodes']['items']['oneOf'])->toHaveCount(count(FlowCapabilities::FIELDS));
    expect(json_decode(file_get_contents(base_path('docs/modulos/chait-flow.schema.json')), true))->toBe(json_decode(json_encode($schema), true));
    $this->get(transportUrl($f, '/export?source=published'))->assertNotFound();
});

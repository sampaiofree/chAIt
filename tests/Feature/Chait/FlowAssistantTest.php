<?php

use App\Models\Contact;
use App\Models\ContactCustomFieldValue;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiOperation;
use App\Models\FlowAiRun;
use App\Models\FlowDraftSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\FlowAssistant\AssistantOpenAiClient;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\FlowAssistant\FlowAssistantService;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowCondition;
use App\Services\Flows\FlowMenu;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function builderFixture(?array $availableModels = null): array
{
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Cursos', 'revision' => 1, 'graph' => [
        'schema_version' => 2, 'nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início', 'description' => '']]],
        'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ]]);
    WorkspaceOpenAiIntegration::create(['workspace_id' => $workspace->id, 'api_key' => 'sk-private-builder']);
    $tag = $workspace->tags()->create(['name' => 'Interesse']);
    $field = $workspace->contactCustomFields()->create(['name' => 'Curso', 'type' => 'text']);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $availableModels ?? config('flow-assistant.models'))])]);

    return compact('user', 'workspace', 'flow', 'tag', 'field');
}

function builderUrl(array $f, string $path): string
{
    return '/workspaces/'.$f['workspace']->id.'/flows/'.$f['flow']->id.$path;
}

function builderRevision(array $f): array
{
    $session = FlowDraftSession::where('flow_id', $f['flow']->id)->first();

    return ['base_revision' => $f['flow']->fresh()->revision, 'generation' => $session?->generation, 'draft_revision' => $session?->revision];
}

function builderCall(string $name, array $args, string $id = 'call-1'): array
{
    return ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => json_encode($args)];
}

function builderResponse(array $output): array
{
    return ['status' => 'completed', 'output' => $output];
}

function builderText(string $text = 'Revise a prévia e clique Salvar.'): array
{
    return ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
}

function builderPlan(array $f): FlowAiMessage
{
    app(DraftSessionService::class)->locked($f['flow'], fn ($flow) => app(DraftSessionService::class)->ensure($flow));
    $conversation = FlowAiConversation::create(['workspace_id' => $f['workspace']->id, 'flow_id' => $f['flow']->id, 'state' => 'awaiting_plan_approval']);

    return FlowAiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'kind' => 'plan', 'status' => 'pending', 'generation' => builderRevision($f)['generation'], 'content' => 'Enviar boas-vindas e abrir atendimento humano.']);
}

function builderBuild(array $f): FlowAiRun
{
    $plan = builderPlan($f);
    app(FlowAssistantService::class)->start($f['flow'], $f['user'], builderRevision($f), $plan->id);

    return FlowAiRun::latest('id')->first();
}

function builderPatch(int $revision = 1): array
{
    return ['expected_draft_revision' => $revision, 'operations' => [
        ['op' => 'add_node', 'client_ref' => 'welcome', 'node_type' => 'content', 'data' => ['label' => 'Boas-vindas', 'items' => [['id' => 'greeting', 'kind' => 'text', 'text' => 'Olá!']]], 'position' => ['x' => 0, 'y' => 180]],
        ['op' => 'connect', 'source_ref' => 'start', 'source_handle' => 'next', 'target_ref' => 'welcome'],
    ]];
}

function builderTriageOperations(array $f): array
{
    $content = fn ($ref, $label, $text) => ['op' => 'add_node', 'client_ref' => $ref, 'node_type' => 'content', 'data' => ['label' => $label, 'items' => [['id' => $ref.'-text', 'kind' => 'text', 'text' => $text]]], 'position' => ['x' => 0, 'y' => 200]];
    $connect = fn ($from, $handle, $to) => ['op' => 'connect', 'source_ref' => $from, 'source_handle' => $handle, 'target_ref' => $to];
    $condition = fn ($ref, $value) => ['op' => 'add_node', 'client_ref' => $ref, 'node_type' => 'condition', 'data' => ['label' => $value.'?', 'condition_operator' => 'all', 'checks' => [['kind' => 'field', 'field_id' => $f['field']->id, 'operator' => 'equals', 'value' => $value]]], 'position' => ['x' => 0, 'y' => 400]];

    return [
        $content('intro', 'Escolha', 'Responda suporte ou compra.'),
        ['op' => 'add_node', 'client_ref' => 'collect', 'node_type' => 'wait_reply', 'data' => ['label' => 'Coletar', 'field_id' => $f['field']->id, 'timeout_seconds' => 300], 'position' => ['x' => 0, 'y' => 300]],
        $connect('start', 'next', 'intro'), $connect('intro', 'next', 'collect'),
        $condition('is-support', 'suporte'), $content('support', 'Suporte', 'Caminho de suporte.'),
        $connect('collect', 'replied', 'is-support'), $connect('is-support', 'yes', 'support'),
        $condition('is-purchase', 'compra'), $content('purchase', 'Compra', 'Caminho de compra.'),
        $content('unknown', 'Opção não reconhecida', 'Não foi possível identificar sua opção. A triagem foi encerrada.'),
        $content('expired', 'Prazo vencido', 'O prazo terminou. A triagem foi encerrada.'),
        $connect('is-support', 'no', 'is-purchase'), $connect('is-purchase', 'yes', 'purchase'),
        $connect('is-purchase', 'no', 'unknown'), $connect('collect', 'not_replied', 'expired'),
    ];
}

test('the operational guide and complete catalog reach every model turn and triage handles every outcome', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $operations = builderTriageOperations($f);
    $responses = [
        builderCall('get_editor_capabilities', [], 'capabilities'),
        builderCall('get_current_flow', [], 'current'),
        builderCall('list_custom_fields', [], 'fields'),
        builderCall('apply_flow_patch', ['expected_draft_revision' => 1, 'operations' => array_slice($operations, 0, 4)], 'collect'),
        builderCall('apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => array_slice($operations, 4, 4)], 'support'),
        builderCall('apply_flow_patch', ['expected_draft_revision' => 3, 'operations' => array_slice($operations, 8)], 'remaining'),
        builderCall('validate_flow', [], 'validate'), builderText(),
    ];
    $turn = 0;
    Http::fake(['api.openai.com/v1/responses' => function ($request) use (&$turn, $responses) {
        expect($request['instructions'])->toBe(file_get_contents(resource_path('prompts/flow-assistant.md')));
        if ($turn === 1) {
            $output = collect($request['input'])->firstWhere('type', 'function_call_output');
            $catalog = json_decode($output['output'], true, flags: JSON_THROW_ON_ERROR);
            expect($catalog['graph_rules'])->not->toBeEmpty()
                ->and(array_column($catalog['nodes'], 'type'))->toBe(array_keys(FlowCapabilities::OUTPUTS));
            foreach ($catalog['nodes'] as $node) {
                expect($node['usage'])->not->toBeEmpty();
                foreach ($node['outputs'] as $exit) {
                    expect($exit['meaning'])->not->toBeEmpty();
                }
                if ($node['dynamic_outputs']) {
                    expect($node['dynamic_outputs']['meaning'])->not->toBeEmpty();
                }
            }
        }

        return Http::response(builderResponse([$responses[$turn++]]));
    }]);
    app(FlowAssistantService::class)->process($run->id);
    $graph = FlowDraftSession::sole()->graph;
    expect($run->fresh()->status)->toBe('completed')->and($turn)->toBe(count($responses))
        ->and($graph['nodes'])->toHaveCount(9)->and($graph['edges'])->toHaveCount(8)
        ->and(FlowAiOperation::where('name', 'validate_flow')->sole()->result['pending'])->toBe([])
        ->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1)
        ->and($f['flow']->fresh()->published_version_id)->toBeNull();

    $contact = Contact::factory()->create(['workspace_id' => $f['workspace']->id]);
    $nodes = collect($graph['nodes'])->keyBy('id');
    foreach ([[' SUPORTE ', 'replied', 'Suporte'], ['compra', 'replied', 'Compra'], ['banana', 'replied', 'Opção não reconhecida'], ['suporte', 'not_replied', 'Prazo vencido']] as [$answer, $waitHandle, $expected]) {
        ContactCustomFieldValue::updateOrCreate(['contact_id' => $contact->id, 'field_id' => $f['field']->id], ['workspace_id' => $f['workspace']->id, 'value' => $answer]);
        $node = $nodes['start'];
        $visited = [];
        while (true) {
            expect($visited)->not->toContain($node['id']);
            $visited[] = $node['id'];
            $handle = match ($node['type']) {
                'wait_reply' => $waitHandle,
                'condition' => app(FlowCondition::class)->evaluate($node['data'], $f['workspace'], $contact->id) ? 'yes' : 'no',
                default => 'next',
            };
            $edge = collect($graph['edges'])->first(fn ($edge) => $edge['source'] === $node['id'] && $edge['sourceHandle'] === $handle);
            if (! $edge) {
                break;
            }
            $node = $nodes[$edge['target']];
        }
        expect($node['data']['label'])->toBe($expected);
    }
});

test('cyclic connection batches roll back and resume connects existing triage nodes without duplication', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $operations = builderTriageOperations($f);
    $initial = array_values(array_filter($operations, fn ($op) => $op['op'] === 'add_node'));
    $initial = [...$initial, ...array_slice($operations, 2, 2)];
    $tools = app(AssistantTools::class);
    $built = $tools->execute($f['flow'], $run, 'initial', 'apply_flow_patch', ['expected_draft_revision' => 1, 'operations' => $initial]);
    $graph = FlowDraftSession::sole()->graph;
    $connections = array_values(array_filter(array_slice($operations, 4), fn ($op) => $op['op'] === 'connect'));
    $cyclic = [...$connections, ['op' => 'connect', 'source_ref' => 'unknown', 'source_handle' => 'next', 'target_ref' => 'collect']];
    try {
        $tools->execute($f['flow'], $run, 'cycle', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => $cyclic]);
        $this->fail('A return to the collection must be rejected.');
    } catch (ValidationException $error) {
        expect($error->errors()['graph.edges'])->toContain('As conexões não podem formar ciclos.');
    }
    expect(FlowDraftSession::sole()->graph)->toBe($graph)
        ->and(FlowDraftSession::sole()->revision)->toBe(2)->and(FlowAiOperation::count())->toBe(1)
        ->and(FlowAiMessage::where('kind', 'operation')->count())->toBe(1);

    app(FlowAssistantService::class)->pause($run->id, 'Lote inválido.');
    $resumed = app(FlowAssistantService::class)->resume($f['flow'], $f['user'], $run->id, builderRevision($f));
    $connections = array_map(fn ($op) => array_replace($op, ['source_ref' => $built['refs'][$op['source_ref']], 'target_ref' => $built['refs'][$op['target_ref']]]), $connections);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(builderResponse([builderCall('get_current_flow', [], 'reread')]))
        ->push(builderResponse([builderCall('apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => $connections], 'connect-existing')]))
        ->push(builderResponse([builderCall('validate_flow', [], 'check')]))
        ->push(builderResponse([builderText()]))]);
    app(FlowAssistantService::class)->process($resumed->id);
    $final = FlowDraftSession::sole()->graph;
    expect($resumed->fresh()->status)->toBe('completed')->and($final['nodes'])->toBe($graph['nodes'])
        ->and($final['edges'])->toHaveCount(8)->and(FlowDraftSession::sole()->revision)->toBe(3)
        ->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1);
});

test('database failures pause safely without exposing SQL or bindings', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $exception = new QueryException('pgsql', 'UPDATE flow_ai_runs SET provider_items = ?', ['private-context'], new PDOException('database is locked'));
    $this->partialMock(AssistantOpenAiClient::class, fn ($mock) => $mock->shouldReceive('respond')->once()->andThrow($exception));
    Log::spy();
    app(FlowAssistantService::class)->process($run->id);
    expect($run->fresh()->status)->toBe('paused')
        ->and($run->fresh()->error)->toContain('lotes confirmados')->not->toContain('UPDATE', 'provider_items', 'private-context', 'SQLSTATE');
    Log::shouldHaveReceived('warning')->once()->with('Flow assistant database failure', Mockery::on(fn ($context) => array_keys($context) === ['run_id', 'flow_id', 'sqlstate']));
});

test('provider context stays in memory between tool cycles and resume never calls models inside a transaction', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    Http::fake(['api.openai.com/v1/responses' => function () use ($run) {
        expect($run->fresh()->provider_items)->toBeNull();

        return Http::response(builderResponse([builderText()]));
    }]);
    app(FlowAssistantService::class)->process($run->id);
    $run->update(['status' => 'paused']);
    Cache::flush();
    Http::fake(['api.openai.com/v1/models' => function () {
        // RefreshDatabase provides the outer test transaction; resume must add none here.
        expect(DB::transactionLevel())->toBe(1);

        return Http::response(['data' => [['id' => 'gpt-6.1-sol']]]);
    }]);
    $resumed = app(FlowAssistantService::class)->resume($f['flow'], $f['user'], $run->id, builderRevision($f));
    expect($resumed->id)->not->toBe($run->id)->and($resumed->purpose)->toBe('editing');
});

test('briefing stores shared history and a plan without changing the saved graph or preview', function () {
    $f = builderFixture();
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-6.1-sol']]]), 'api.openai.com/v1/responses' => Http::response(builderResponse([builderCall('propose_plan', ['briefing' => 'Cursos e suporte.', 'plan' => 'Enviar boas-vindas e abrir atendimento humano.'])]))]);
    $this->actingAs($f['user'])->postJson(builderUrl($f, '/assistant/messages'), builderRevision($f) + ['content' => 'Crie um atendimento para cursos.'])->assertAccepted();
    $run = FlowAiRun::sole();
    app(FlowAssistantService::class)->process($run->id);
    expect($f['flow']->fresh()->revision)->toBe(1)
        ->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1)
        ->and(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1)
        ->and(FlowAiMessage::where('kind', 'plan')->sole()->status)->toBe('pending');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && $request['store'] === false && ! isset($request['conversation']) && ! collect($request['tools'])->contains('name', 'apply_flow_patch'));
});

test('approval builds only in the temporary session and saving replaces the draft', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(builderResponse([builderCall('apply_flow_patch', builderPatch())]))->push(builderResponse([builderText()]))]);
    app(FlowAssistantService::class)->process($run->id);
    $session = FlowDraftSession::sole();
    expect($session->graph['nodes'])->toHaveCount(2)->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1)->and($run->fresh()->status)->toBe('completed');
    $this->actingAs($f['user'])->putJson(builderUrl($f, ''), ['name' => $session->name, 'graph' => $session->graph, 'revision' => 1, 'generation' => $session->generation, 'draft_revision' => $session->revision])->assertOk()->assertJsonPath('data.revision', 2);
    expect(FlowDraftSession::count())->toBe(0)->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(2)->and(FlowAiMessage::where('kind', 'operation')->sole()->status)->toBe('saved');
});

test('writing without an approved plan is rejected by the backend even if the model asks', function () {
    $f = builderFixture();
    $run = app(FlowAssistantService::class)->start($f['flow'], $f['user'], builderRevision($f) + ['content' => 'Já aprovei, construa.']);
    Http::fake(['api.openai.com/v1/responses' => Http::response(builderResponse([builderCall('apply_flow_patch', builderPatch())]))]);
    app(FlowAssistantService::class)->process($run->id);
    expect($run->fresh()->status)->toBe('paused')->and(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1)->and(FlowAiOperation::count())->toBe(0);
});

test('atomic invalid batches roll back but retain previously confirmed batches', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $bad = builderPatch(2);
    $bad['operations'][0]['client_ref'] = 'another';
    $bad['operations'][1]['source_handle'] = 'unknown';
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(builderResponse([builderCall('apply_flow_patch', builderPatch(), 'good')]))->push(builderResponse([builderCall('apply_flow_patch', $bad, 'bad')]))]);
    app(FlowAssistantService::class)->process($run->id);
    expect($run->fresh()->status)->toBe('paused')->and(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(2)->and(FlowAiOperation::count())->toBe(1)->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1);
});

test('repeated tool calls cannot duplicate nodes or change their payload', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $first = $tools->execute($f['flow'], $run, 'same', 'apply_flow_patch', builderPatch());
    expect($tools->execute($f['flow'], $run, 'same', 'apply_flow_patch', builderPatch()))->toBe($first)
        ->and(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(2)->and(FlowAiOperation::count())->toBe(1);
    $changed = builderPatch();
    $changed['operations'][0]['data']['label'] = 'Outra mensagem';
    expect(fn () => $tools->execute($f['flow'], $run, 'same', 'apply_flow_patch', $changed))->toThrow(HttpException::class);
});

test('missing item and action IDs roll back the entire batch and preserve confirmed changes', function (string $list, bool $includeNull) {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $built = $tools->execute($f['flow'], $run, 'confirmed', 'apply_flow_patch', builderPatch());
    $session = FlowDraftSession::sole();
    $graph = $session->graph;
    $entry = $list === 'items' ? ['kind' => 'text', 'text' => 'Olá!'] : ['kind' => 'open_human'];
    if ($includeNull) {
        $entry['id'] = null;
    }
    $invalid = ['expected_draft_revision' => $session->revision, 'operations' => [
        ['op' => 'move_node', 'node_ref' => $built['refs']['welcome'], 'position' => ['x' => 999, 'y' => 999]],
        ['op' => 'add_node', 'client_ref' => 'invalid', 'node_type' => $list === 'items' ? 'content' : 'operations', 'data' => ['label' => 'Inválido', $list => [$entry]], 'position' => ['x' => 0, 'y' => 360]],
    ]];

    try {
        $tools->execute($f['flow'], $run, 'invalid', 'apply_flow_patch', $invalid);
        $this->fail('The batch must reject an entry without an ID.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('nodes.2.data.'.$list.'.0.id');
    }

    expect($session->fresh()->graph)->toBe($graph)
        ->and($session->fresh()->revision)->toBe($built['revision'])
        ->and(FlowAiOperation::count())->toBe(1)
        ->and(FlowAiMessage::where('kind', 'operation')->count())->toBe(1)
        ->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1);
})->with([
    'missing item ID' => ['items', false],
    'null item ID' => ['items', true],
    'missing action ID' => ['actions', false],
    'null action ID' => ['actions', true],
]);

test('manual shared sessions recover and detect conflicting edits and legacy saves', function () {
    $f = builderFixture();
    $graph = $f['flow']->graph;
    $graph['nodes'][0]['position']['x'] = 80;
    $data = builderRevision($f) + ['name' => 'Cursos atualizados', 'graph' => $graph];
    $this->actingAs($f['user'])->putJson(builderUrl($f, '/draft-session'), $data)->assertOk();
    $this->getJson(builderUrl($f, '/assistant'))->assertOk()->assertJsonPath('data.session.name', 'Cursos atualizados')->assertJsonPath('data.flow.name', 'Cursos');
    $this->putJson(builderUrl($f, '/draft-session'), $data)->assertConflict();
    $this->putJson(builderUrl($f, ''), ['name' => 'Cursos', 'graph' => $graph, 'revision' => 1])->assertConflict();
    $this->postJson(builderUrl($f, '/publish'), ['revision' => 1])->assertConflict();
    $this->deleteJson(builderUrl($f, '/draft-session'), builderRevision($f))->assertOk()->assertJsonPath('data.session', null)->assertJsonPath('data.flow.name', 'Cursos');
});

test('discard cancels active work and rejects late tool results with an invalidated generation', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $this->actingAs($f['user'])->deleteJson(builderUrl($f, '/draft-session'), builderRevision($f))->assertOk();
    expect(fn () => app(AssistantTools::class)->execute($f['flow'], $run, 'late', 'apply_flow_patch', builderPatch()))->toThrow(HttpException::class);
    expect($run->fresh()->status)->toBe('cancelled')->and(FlowDraftSession::count())->toBe(0)->and(FlowAiConversation::sole()->state)->toBe('briefing');
});

test('manual editors read shared history and stop a run without permission to invoke AI', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $agent = User::factory()->create(['current_workspace_id' => $f['workspace']->id]);
    WorkspaceMembership::create(['workspace_id' => $f['workspace']->id, 'user_id' => $agent->id, 'role' => 'agent', 'joined_at' => now(), 'can_manage_flows' => true]);
    $this->actingAs($agent)->getJson(builderUrl($f, '/assistant'))->assertOk()->assertJsonPath('data.can_use_ai', false);
    $this->postJson(builderUrl($f, '/assistant/messages'), builderRevision($f) + ['content' => 'Editar'])->assertForbidden();
    $this->postJson(builderUrl($f, '/assistant/runs/'.$run->id.'/stop'))->assertOk();
    expect($run->fresh()->status)->toBe('paused');
});

test('active AI blocks manual updates and saving and revoked permissions block worker operations', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $this->actingAs($f['user'])->putJson(builderUrl($f, '/draft-session'), builderRevision($f) + ['name' => 'Outro', 'graph' => $f['flow']->graph])->assertConflict();
    $session = FlowDraftSession::sole();
    $this->putJson(builderUrl($f, ''), ['name' => $session->name, 'graph' => $session->graph, 'revision' => 1, 'generation' => $session->generation, 'draft_revision' => $session->revision])->assertConflict();
    $f['user']->membershipFor($f['workspace'])->update(['role' => 'agent', 'can_manage_flows' => true, 'can_use_flow_assistant' => false]);
    app(FlowAssistantService::class)->process($run->id);
    expect($run->fresh()->status)->toBe('paused');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/responses'));
});

test('assistant access requires the active workspace and prevents cross-flow ids', function () {
    $f = builderFixture();
    $other = Workspace::factory()->create();
    WorkspaceMembership::create(['workspace_id' => $other->id, 'user_id' => $f['user']->id, 'role' => 'admin', 'joined_at' => now()]);
    $foreign = $other->flows()->create(['name' => 'Outro', 'graph' => $f['flow']->graph]);
    $this->actingAs($f['user'])->getJson('/workspaces/'.$other->id.'/flows/'.$foreign->id.'/assistant')->assertForbidden();
    $this->getJson('/workspaces/'.$f['workspace']->id.'/flows/'.$foreign->id.'/assistant')->assertNotFound();
});

test('metadata tools reveal definitions only and credential-shaped text is removed', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    expect($tools->execute($f['flow'], $run, 'tags', 'list_tags', []))->toBe([['id' => $f['tag']->id, 'name' => 'Interesse']]);
    expect($tools->execute($f['flow'], $run, 'fields', 'list_custom_fields', []))->toBe([['id' => $f['field']->id, 'name' => 'Curso', 'type' => 'text']]);
    expect($tools->sanitize(['api_key' => 'sk-secret', 'text' => 'Bearer secret-token sk-private token=some-secret', 'target_flow_id' => 45]))->toBe(['text' => '[credencial removida] [credencial removida] token=[removido]', 'target_flow_id' => null]);
});

test('patches reject invented resources, forbidden selections, start changes and invalid operations', function (string $invalid) {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $patch = match ($invalid) {
        'resource' => ['operations' => [['op' => 'add_node', 'client_ref' => 'wait', 'node_type' => 'wait_reply', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Pergunta', 'field_id' => 999999]]]],
        'selection' => ['operations' => [['op' => 'add_node', 'client_ref' => 'jump', 'node_type' => 'jump_flow', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Destino', 'target_flow_id' => 123]]]],
        'start' => ['operations' => [['op' => 'remove_node', 'node_ref' => 'start']]],
        'unknown' => ['operations' => [['op' => 'publish_flow']]],
    };
    try {
        app(AssistantTools::class)->execute($f['flow'], $run, 'invalid', 'apply_flow_patch', ['expected_draft_revision' => 1] + $patch);
        $this->fail('Invalid patch was applied');
    } catch (ValidationException|HttpException $e) {
        expect(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1)->and(FlowAiOperation::count())->toBe(0);
    }
})->with(['resource', 'selection', 'start', 'unknown']);

test('provider failure pauses without losing history and explicit resume uses current preview', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'sk-secret-error']], 401)]);
    app(FlowAssistantService::class)->process($run->id);
    expect($run->fresh()->status)->toBe('paused')->and($run->fresh()->error)->not->toContain('sk-secret');
    $this->actingAs($f['user'])->postJson(builderUrl($f, '/assistant/runs/'.$run->id.'/resume'), builderRevision($f))->assertAccepted();
    expect(FlowAiRun::count())->toBe(2)->and(FlowDraftSession::sole()->generation)->toBe($run->generation);
});

test('manual unavailable model is never silently replaced', function () {
    $f = builderFixture(['gpt-6.1-sol']);
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-6.1-sol']]])]);
    $this->actingAs($f['user'])->postJson(builderUrl($f, '/assistant/messages'), builderRevision($f) + ['content' => 'Criar', 'model' => 'gpt-6-astra'])->assertUnprocessable();
    expect(FlowAiRun::count())->toBe(0)->and(FlowDraftSession::count())->toBe(0);
});

test('delete and reconnect are temporary and never alter a published version', function () {
    $f = builderFixture();
    $published = $f['flow']->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 1, 'graph' => $f['flow']->graph, 'published_at' => now(), 'published_by_user_id' => $f['user']->id]);
    $f['flow']->update(['published_version_id' => $published->id, 'is_active' => true]);
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $built = $tools->execute($f['flow'], $run, 'add', 'apply_flow_patch', builderPatch());
    $tools->execute($f['flow'], $run, 'remove', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [['op' => 'remove_node', 'node_ref' => $built['refs']['welcome']]]]);
    expect(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1)->and(FlowDraftSession::sole()->graph['edges'])->toBe([])->and($published->fresh()->graph)->toBe($f['flow']->graph);
});

test('changed credential and expired execution cannot apply late batches', function (string $change) {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    if ($change === 'credential') {
        WorkspaceOpenAiIntegration::sole()->update(['api_key' => 'sk-replaced']);
    } else {
        $run->update(['started_at' => now()->subSeconds(151)]);
    }
    expect(fn () => app(AssistantTools::class)->execute($f['flow'], $run, 'late', 'apply_flow_patch', builderPatch()))->toThrow(HttpException::class);
    expect(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1);
})->with(['credential', 'deadline']);

test('strict tools encode empty properties as objects and require every declared property', function () {
    $schemas = app(AssistantTools::class)->schemas(true);
    $encoded = json_decode(json_encode($schemas));
    expect($encoded[0]->parameters->properties)->toBeInstanceOf(stdClass::class);
    $inspect = function ($schema) use (&$inspect) {
        if (isset($schema->properties)) {
            expect($schema->additionalProperties)->toBeFalse();
            expect($schema->required)->toBe(array_keys((array) $schema->properties));
            foreach ($schema->properties as $value) {
                $inspect($value);
            }
        }
        foreach ($schema->anyOf ?? [] as $value) {
            $inspect($value);
        }
        if (isset($schema->items)) {
            $inspect($schema->items);
        }
    };
    foreach ($encoded as $tool) {
        $inspect($tool->parameters);
    }
});

test('tool schemas require non-null item and action IDs while optional fields remain nullable', function (string $list, string $optional) {
    $schema = app(AssistantTools::class)->dataSchema();
    $entry = $schema['properties'][$list]['anyOf'][0]['items'];

    expect($entry['properties']['id'])->toBe(['type' => 'string', 'maxLength' => 64])
        ->and($entry['properties']['kind'])->toHaveKey('enum')->not->toHaveKey('anyOf')
        ->and($entry['required'])->toContain('id', 'kind', $optional)
        ->and($entry['properties'][$optional]['anyOf'][1])->toBe(['type' => 'null'])
        ->and($schema['properties']['label']['anyOf'][1])->toBe(['type' => 'null'])
        ->and($schema['properties'][$list]['anyOf'][1])->toBe(['type' => 'null']);
})->with([
    'content items' => ['items', 'text'],
    'operation actions' => ['actions', 'tag_id'],
]);

test('a revised plan supersedes previous pending approval without creating nodes', function () {
    $f = builderFixture();
    $plan = builderPlan($f);
    $run = app(FlowAssistantService::class)->start($f['flow'], $f['user'], builderRevision($f) + ['content' => 'Mude o objetivo antes de construir.']);
    expect($plan->fresh()->status)->toBe('superseded');
    $this->actingAs($f['user'])->postJson(builderUrl($f, '/assistant/plans/'.$plan->id.'/approve'), builderRevision($f))->assertConflict();
    expect(FlowDraftSession::sole()->graph['nodes'])->toHaveCount(1)->and($run->purpose)->toBe('briefing');
});

test('saving commits the authoritative temporary revision and rejects a stale saved revision', function () {
    $f = builderFixture();
    $graph = $f['flow']->graph;
    $graph['nodes'][0]['position']['x'] = 400;
    $session = app(DraftSessionService::class)->update($f['flow'], builderRevision($f) + ['name' => 'Prévia', 'graph' => $graph]);
    $this->actingAs($f['user'])->putJson(builderUrl($f, ''), ['name' => 'Snapshot antigo', 'graph' => $f['flow']->graph, 'revision' => 1, 'generation' => $session->generation, 'draft_revision' => $session->revision])->assertOk()->assertJsonPath('data.name', 'Prévia')->assertJsonPath('data.graph.nodes.0.position.x', 400);
    $this->putJson(builderUrl($f, ''), ['name' => 'Outro', 'graph' => $graph, 'revision' => 1])->assertConflict();
});

test('updates can clear optional configuration but reject unknown nested fields', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $built = $tools->execute($f['flow'], $run, 'build', 'apply_flow_patch', builderPatch());
    $nodeId = $built['refs']['welcome'];
    $tools->execute($f['flow'], $run, 'clear', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [['op' => 'update_node', 'node_ref' => $nodeId, 'data' => ['description' => 'Interna'], 'clear_fields' => ['description']]]]);
    expect(collect(FlowDraftSession::sole()->graph['nodes'])->firstWhere('id', $nodeId)['data'])->not->toHaveKey('description');
    $bad = ['expected_draft_revision' => 3, 'operations' => [['op' => 'update_node', 'node_ref' => $nodeId, 'data' => ['items' => [['id' => 'bad', 'kind' => 'text', 'text' => 'Olá', 'script' => 'not-allowed']]]]]];
    expect(fn () => $tools->execute($f['flow'], $run, 'bad-field', 'apply_flow_patch', $bad))->toThrow(HttpException::class);
    expect(FlowDraftSession::sole()->revision)->toBe(3);
});

test('discarded authorization cannot be recovered from historical saved approvals after a briefing failure', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'completed']);
    $session = FlowDraftSession::sole();
    app(DraftSessionService::class)->save($f['flow'], ['name' => $session->name, 'graph' => $session->graph, 'revision' => 1, 'generation' => $session->generation, 'draft_revision' => $session->revision]);
    expect(FlowAiConversation::sole()->approved_plan_id)->not->toBeNull();
    $savedPlan = FlowAiMessage::where('kind', 'plan')->sole();
    app(DraftSessionService::class)->update($f['flow']->fresh(), builderRevision($f) + ['name' => 'Nova prévia', 'graph' => $f['flow']->fresh()->graph]);
    app(DraftSessionService::class)->discard($f['flow']->fresh(), builderRevision($f));
    expect($savedPlan->fresh()->status)->toBe('saved')->and(FlowAiConversation::sole()->approved_plan_id)->toBeNull();
    $briefing = app(FlowAssistantService::class)->start($f['flow']->fresh(), $f['user'], builderRevision($f) + ['content' => 'Crie outro atendimento.']);
    app(FlowAssistantService::class)->pause($briefing->id, 'OpenAI indisponível');
    $next = app(FlowAssistantService::class)->start($f['flow']->fresh(), $f['user'], builderRevision($f) + ['content' => 'Continue.']);
    expect($next->purpose)->toBe('briefing');
});

test('editor assistant builds and edits mixed conditions and connects both branches', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $checks = [
        ['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'not_has'],
        ['kind' => 'field', 'field_id' => $f['field']->id, 'operator' => 'contains', 'value' => 'inglês'],
        ['kind' => 'field', 'field_key' => 'email', 'operator' => 'equals', 'value' => 'ana@example.test'],
    ];
    $operations = [
        ['op' => 'add_node', 'client_ref' => 'condition', 'node_type' => 'condition', 'position' => ['x' => 100, 'y' => 200], 'data' => ['label' => 'Interesse', 'condition_operator' => 'all', 'checks' => $checks]],
        ['op' => 'add_node', 'client_ref' => 'yes', 'node_type' => 'content', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'Sim', 'items' => [['id' => 'yes-text', 'kind' => 'text', 'text' => 'Sim']]]],
        ['op' => 'add_node', 'client_ref' => 'no', 'node_type' => 'content', 'position' => ['x' => 300, 'y' => 400], 'data' => ['label' => 'Não', 'items' => [['id' => 'no-text', 'kind' => 'text', 'text' => 'Não']]]],
        ['op' => 'connect', 'source_ref' => 'start', 'source_handle' => 'next', 'target_ref' => 'condition'],
        ['op' => 'connect', 'source_ref' => 'condition', 'source_handle' => 'yes', 'target_ref' => 'yes'],
        ['op' => 'connect', 'source_ref' => 'condition', 'source_handle' => 'no', 'target_ref' => 'no'],
    ];
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(builderResponse([builderCall('get_editor_capabilities', [], 'capabilities')]))
        ->push(builderResponse([builderCall('list_tags', [], 'tags')]))
        ->push(builderResponse([builderCall('list_custom_fields', [], 'fields')]))
        ->push(builderResponse([builderCall('apply_flow_patch', ['expected_draft_revision' => 1, 'operations' => $operations], 'build')]))
        ->push(builderResponse([builderCall('validate_flow', [], 'validate')]))
        ->push(builderResponse([builderText()]))]);
    app(FlowAssistantService::class)->process($run->id);
    $session = FlowDraftSession::sole();
    $condition = collect($session->graph['nodes'])->firstWhere('type', 'condition');
    expect($condition['data']['checks'])->toBe($checks)->and($session->graph['edges'])->toHaveCount(3)
        ->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1)
        ->and(FlowAiOperation::where('name', 'validate_flow')->sole()->result['pending'])->toBe([]);
    Http::assertSent(fn ($request) => str_contains($request->body(), 'checks') && str_contains($request->body(), 'not_has'));
    $run->update(['status' => 'running']);
    $checks[0]['operator'] = 'has';
    $result = app(AssistantTools::class)->execute($f['flow'], $run, 'edit', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [
        ['op' => 'update_node', 'node_ref' => $condition['id'], 'data' => ['condition_operator' => 'any', 'checks' => $checks]],
    ]]);
    expect($result['validation']['pending'])->toBe([])->and(FlowDraftSession::sole()->graph['edges'])->toBe($session->graph['edges']);
    $this->actingAs($f['user'])->get(builderUrl($f, '/assistant'))->assertOk()->assertJsonPath('data.session.graph.nodes.1.data.condition_operator', 'any');
});

test('condition tool rejects foreign IDs unsupported custom fields and arbitrary standard keys', function (string $case) {
    $f = builderFixture();
    $run = builderBuild($f);
    $other = Workspace::factory()->create();
    $field = $other->contactCustomFields()->create(['name' => 'Privado', 'type' => 'text']);
    $date = $f['workspace']->contactCustomFields()->create(['name' => 'Data', 'type' => 'date']);
    $check = match ($case) {
        'foreign-field' => ['kind' => 'field', 'field_id' => $field->id, 'operator' => 'equals', 'value' => 'a'],
        'foreign-tag' => ['kind' => 'tag', 'tag_id' => $other->tags()->create(['name' => 'Privada'])->id, 'operator' => 'has'],
        'unsupported-field' => ['kind' => 'field', 'field_id' => $date->id, 'operator' => 'equals', 'value' => 'a'],
        'column' => ['kind' => 'field', 'field_key' => 'notes', 'operator' => 'contains', 'value' => 'a'],
    };
    expect(fn () => app(AssistantTools::class)->execute($f['flow'], $run, 'invalid', 'apply_flow_patch', ['expected_draft_revision' => 1, 'operations' => [
        ['op' => 'add_node', 'client_ref' => 'condition', 'node_type' => 'condition', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Verificar', 'condition_operator' => 'all', 'checks' => [$check]]],
    ]]))->toThrow(HttpException::class);
    expect(FlowDraftSession::sole()->revision)->toBe(1);
})->with(['foreign-field', 'foreign-tag', 'unsupported-field', 'column']);

test('approved assistant creates a menu and connects its dynamic options idempotently', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $data = array_replace(FlowMenu::defaults(), ['label' => 'Escolher atendimento', 'question' => 'Como ajudar?', 'options' => [['id' => 'sales', 'label' => 'Vendas'], ['id' => 'support', 'label' => 'Suporte']]]);
    $args = ['expected_draft_revision' => 1, 'operations' => [
        ['op' => 'add_node', 'client_ref' => 'menu', 'node_type' => 'menu', 'data' => $data, 'position' => ['x' => 0, 'y' => 200]],
        ['op' => 'add_node', 'client_ref' => 'sales', 'node_type' => 'content', 'data' => ['label' => 'Vendas', 'items' => [['id' => 'welcome', 'kind' => 'text', 'text' => 'Olá!']]], 'position' => ['x' => 0, 'y' => 400]],
        ['op' => 'connect', 'source_ref' => 'start', 'source_handle' => 'next', 'target_ref' => 'menu'],
        ['op' => 'connect', 'source_ref' => 'menu', 'source_handle' => 'option:sales', 'target_ref' => 'sales'],
    ]];
    $tools = app(AssistantTools::class);
    $result = $tools->execute($f['flow'], $run, 'menu-create', 'apply_flow_patch', $args);
    expect($tools->execute($f['flow'], $run, 'menu-create', 'apply_flow_patch', $args))->toBe($result);
    $session = FlowDraftSession::sole();
    expect($session->graph['nodes'])->toHaveCount(3)->and($session->graph['edges'])->toHaveCount(2)->and($f['flow']->fresh()->graph['nodes'])->toHaveCount(1);
    $menuId = $result['refs']['menu'];
    $options = array_reverse($data['options']);
    $options[1]['label'] = 'Comercial';
    $tools->execute($f['flow'], $run, 'menu-reorder', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [
        ['op' => 'update_node', 'node_ref' => $menuId, 'data' => ['options' => $options, 'menu_format' => 'list']],
    ]]);
    $session->refresh();
    expect(collect($session->graph['nodes'])->firstWhere('id', $menuId)['data']['options'][1])->toBe(['id' => 'sales', 'label' => 'Comercial']);
    expect($session->graph['edges'][1]['sourceHandle'])->toBe('option:sales');
    expect($tools->validate($session->graph, $f['workspace'])['pending'])->toBe([]);
});

test('assistant menu option removal rolls back unless its edge is disconnected in the same batch', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $result = $tools->execute($f['flow'], $run, 'setup-menu', 'apply_flow_patch', ['expected_draft_revision' => 1, 'operations' => [
        ['op' => 'add_node', 'client_ref' => 'menu', 'node_type' => 'menu', 'data' => ['label' => 'Menu', ...FlowMenu::defaults(), 'question' => 'Escolha', 'options' => [['id' => 'a', 'label' => 'A']]], 'position' => ['x' => 0, 'y' => 200]],
        ['op' => 'add_node', 'client_ref' => 'target', 'node_type' => 'content', 'data' => ['label' => 'Destino', 'items' => []], 'position' => ['x' => 0, 'y' => 400]],
        ['op' => 'connect', 'source_ref' => 'menu', 'source_handle' => 'option:a', 'target_ref' => 'target'],
    ]]);
    $update = ['op' => 'update_node', 'node_ref' => $result['refs']['menu'], 'data' => ['options' => []]];
    expect(fn () => $tools->execute($f['flow'], $run, 'bad-removal', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [$update]]))->toThrow(ValidationException::class);
    $session = FlowDraftSession::sole();
    expect($session->revision)->toBe(2)->and($session->graph['edges'])->toHaveCount(1);
    $tools->execute($f['flow'], $run, 'good-removal', 'apply_flow_patch', ['expected_draft_revision' => 2, 'operations' => [$update, ['op' => 'disconnect', 'edge_id' => $session->graph['edges'][0]['id']]]]);
    expect($session->fresh()->graph['edges'])->toBe([])->and($session->fresh()->graph['nodes'])->toHaveCount(3);
});

test('approved assistant can read create edit reorder change kinds and remove a content button', function () {
    $f = builderFixture();
    $run = builderBuild($f);
    $run->update(['status' => 'running', 'started_at' => now()]);
    $tools = app(AssistantTools::class);
    $items = [
        ['id' => 'message', 'kind' => 'text', 'text' => 'Olá!'],
        ['id' => 'stable-button', 'kind' => 'button', 'text' => 'Conheça os cursos.', 'button' => ['kind' => 'url', 'label' => 'Ver cursos', 'url' => 'https://example.com/cursos']],
    ];
    $args = ['expected_draft_revision' => 1, 'operations' => [
        ['op' => 'add_node', 'client_ref' => 'content', 'node_type' => 'content', 'data' => ['label' => 'Conteúdo', 'items' => $items], 'position' => ['x' => 0, 'y' => 200]],
        ['op' => 'connect', 'source_ref' => 'start', 'source_handle' => 'next', 'target_ref' => 'content'],
    ]];
    $result = $tools->execute($f['flow'], $run, 'button-create', 'apply_flow_patch', $args);
    expect($tools->execute($f['flow'], $run, 'button-create', 'apply_flow_patch', $args))->toBe($result);
    $nodeId = $result['refs']['content'];
    $current = $tools->execute($f['flow'], $run, 'button-read', 'get_current_flow', []);
    expect(collect($current['graph']['nodes'])->firstWhere('id', $nodeId)['data']['items'])->toBe($items);
    $capabilities = $tools->execute($f['flow'], $run, 'button-capabilities', 'get_editor_capabilities', []);
    expect($capabilities['content_button']['kinds'])->toBe(['url', 'reply', 'copy']);
    expect($result['validation']['pending'])->toBe([]);
    $revision = 2;
    foreach (['reply', 'copy'] as $kind) {
        $items = array_reverse($items);
        foreach ($items as &$item) {
            if ($item['kind'] === 'button') {
                $item['text'] = 'Mensagem atualizada';
                $item['button'] = ['kind' => $kind, 'label' => 'Atualizado', ...($kind === 'copy' ? ['copy_text' => 'CUPOM10'] : [])];
            }
        }
        unset($item);
        $tools->execute($f['flow'], $run, 'button-'.$kind, 'apply_flow_patch', ['expected_draft_revision' => $revision++, 'operations' => [
            ['op' => 'update_node', 'node_ref' => $nodeId, 'data' => ['items' => $items]],
        ]]);
        expect(collect(FlowDraftSession::sole()->graph['nodes'])->firstWhere('id', $nodeId)['data']['items'])->toBe($items);
    }
    $snapshot = FlowDraftSession::sole()->graph;
    expect(fn () => $tools->execute($f['flow'], $run, 'duplicate-button', 'apply_flow_patch', ['expected_draft_revision' => $revision, 'operations' => [
        ['op' => 'move_node', 'node_ref' => $nodeId, 'position' => ['x' => 999, 'y' => 999]],
        ['op' => 'update_node', 'node_ref' => $nodeId, 'data' => ['items' => [...$items, ['id' => 'duplicate', 'kind' => 'button']]]],
    ]]))->toThrow(ValidationException::class);
    expect(FlowDraftSession::sole()->graph)->toBe($snapshot);
    $tools->execute($f['flow'], $run, 'button-remove', 'apply_flow_patch', ['expected_draft_revision' => $revision, 'operations' => [
        ['op' => 'update_node', 'node_ref' => $nodeId, 'data' => ['items' => [$items[0]]]],
    ]]);
    expect(collect(FlowDraftSession::sole()->graph['nodes'])->firstWhere('id', $nodeId)['data']['items'])->toBe([['id' => 'message', 'kind' => 'text', 'text' => 'Olá!']]);
    expect($f['flow']->fresh()->graph['nodes'])->toHaveCount(1);
});

test('content button strict tool schema exposes every configurable field without an editable reply identity', function () {
    $schema = app(AssistantTools::class)->dataSchema();
    $item = $schema['properties']['items']['anyOf'][0]['items'];
    expect($item['properties']['kind']['enum'])->toContain('button');
    $button = $item['properties']['button']['anyOf'][0];
    expect(array_keys($button['properties']))->toBe(['kind', 'label', 'url', 'copy_text']);
    expect($button['required'])->toBe(['kind', 'label', 'url', 'copy_text']);
    expect($button['additionalProperties'])->toBeFalse();
});

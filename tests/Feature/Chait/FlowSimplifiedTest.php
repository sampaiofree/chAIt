<?php

use App\Models\AiSession;
use App\Models\Contact;
use App\Models\FlowAiConversation;
use App\Models\FlowAiRun;
use App\Models\FlowDraftSession;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\Flows\FlowRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function simpleFlowGraph(bool $complete = true): array
{
    $graph = [
        'schema_version' => 2,
        'nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Start', 'description' => '']]],
        'edges' => [],
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];
    if ($complete) {
        $graph['nodes'][] = ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Mensagem', 'description' => '', 'items' => [['id' => 'item', 'kind' => 'text', 'text' => 'Olá']]]];
        $graph['edges'][] = ['id' => 'edge', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input'];
    }

    return $graph;
}

test('one tab holds the lease and expired tokens cannot edit', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Fluxo', 'graph' => simpleFlowGraph(false)]);
    $token = (string) str()->uuid();
    $other = (string) str()->uuid();
    $url = route('workspaces.flows.edit-lock.acquire', [$workspace, $flow]);

    $this->actingAs($user)->postJson($url, ['token' => $token])->assertOk()->assertJsonPath('data.acquired', true);
    $this->postJson($url, ['token' => $other])->assertOk()->assertJsonPath('data.acquired', false);
    $this->withHeader('X-Flow-Edit-Token', $other)->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => 'Outro', 'graph' => simpleFlowGraph(), 'revision' => 1,
    ])->assertConflict();
    $this->travel(61)->seconds();
    $this->postJson($url, ['token' => $other])->assertJsonPath('data.acquired', true);
    $this->withHeader('X-Flow-Edit-Token', $token)->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => 'Antigo', 'graph' => simpleFlowGraph(), 'revision' => 1,
    ])->assertConflict();
    expect($flow->fresh()->name)->toBe('Fluxo');
});

test('incomplete flows save but require validation and an exclusive lease to activate', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Fluxo', 'graph' => simpleFlowGraph(false)]);
    $token = (string) str()->uuid();
    $this->actingAs($user)->postJson(route('workspaces.flows.edit-lock.acquire', [$workspace, $flow]), ['token' => $token])->assertOk();
    $this->withHeader('X-Flow-Edit-Token', $token)->postJson(route('workspaces.flows.activate', [$workspace, $flow]), ['revision' => 1])->assertUnprocessable();
    $this->putJson(route('workspaces.flows.editor-state.update', [$workspace, $flow]), [
        'name' => 'Novo nome', 'graph' => simpleFlowGraph(), 'base_revision' => 1, 'generation' => null, 'draft_revision' => null,
    ])->assertOk()->assertJsonPath('data.flow.revision', 2);
    expect($flow->fresh()->name)->toBe('Novo nome');
    $this->postJson(route('workspaces.flows.activate', [$workspace, $flow]), ['revision' => 2])->assertOk()->assertJsonPath('data.is_active', true);
    $this->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => 'Não pode', 'graph' => simpleFlowGraph(), 'revision' => 2,
    ])->assertConflict();
});

test('deactivation interrupts executions and cancels their waits', function (string $status) {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Fluxo', 'graph' => simpleFlowGraph()]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $flow->graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id, 'status' => 'bot', 'human_opened_at' => null]);
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'test');
    $execution->update(['status' => $status]);
    if ($status === 'waiting_reply') {
        $field = $workspace->contactCustomFields()->create(['name' => 'Resposta', 'type' => 'text']);
        DB::table('flow_waits')->insert(['workspace_id' => $workspace->id, 'flow_execution_id' => $execution->id,
            'node_id' => 'content', 'field_id' => $field->id, 'status' => 'waiting', 'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
    } elseif ($status === 'waiting_menu') {
        DB::table('flow_menu_waits')->insert(['workspace_id' => $workspace->id, 'flow_execution_id' => $execution->id,
            'node_id' => 'content', 'selection_token' => (string) str()->uuid(), 'status' => 'waiting', 'effective_format' => 'text',
            'retry_queue' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    } elseif ($status === 'waiting_ai') {
        AiSession::create(['workspace_id' => $workspace->id, 'flow_execution_id' => $execution->id, 'flow_version_id' => $version->id,
            'contact_id' => $contact->id, 'thread_id' => $thread->id, 'uazapi_connection_id' => $connection->id,
            'node_id' => 'content', 'status' => 'active', 'model' => 'test', 'expires_at' => now()->addMinute()]);
    }
    $this->actingAs($user)->postJson(route('workspaces.flows.deactivate', [$workspace, $flow]))->assertOk();
    expect($execution->fresh()->status)->toBe('interrupted')->and($execution->fresh()->terminal_reason)->toBe('flow_deactivated');
    if ($status === 'waiting_reply') {
        expect(DB::table('flow_waits')->value('status'))->toBe('cancelled');
    } elseif ($status === 'waiting_menu') {
        expect(DB::table('flow_menu_waits')->value('status'))->toBe('cancelled');
    } elseif ($status === 'waiting_ai') {
        expect(AiSession::sole()->status)->toBe('interrupted');
    }
    expect(app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'test'))->toBeNull();
})->with(['running', 'waiting_reply', 'waiting_menu', 'waiting_ai']);

test('moving a flow requires the same exclusive edit lease and current revision', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Fluxo', 'graph' => simpleFlowGraph(false)]);
    $folder = $workspace->flowFolders()->create(['name' => 'Pasta']);
    $token = (string) str()->uuid();
    $this->actingAs($user)->postJson(route('workspaces.flows.edit-lock.acquire', [$workspace, $flow]), ['token' => $token])->assertOk();
    $url = route('workspaces.flows.folder', [$workspace, $flow]);
    $this->withHeader('X-Flow-Edit-Token', (string) str()->uuid())->patchJson($url, ['folder_id' => $folder->id, 'revision' => 1])->assertConflict();
    $this->withHeader('X-Flow-Edit-Token', $token)->patchJson($url, ['folder_id' => $folder->id, 'revision' => 99])->assertConflict();
    $this->patchJson($url, ['folder_id' => $folder->id, 'revision' => 1])->assertOk();
    expect($flow->fresh()->folder_id)->toBe($folder->id);
    $flow->update(['is_active' => true]);
    $this->patchJson($url, ['folder_id' => null, 'revision' => 1])->assertConflict();
});

test('transition preserves active behavior and inactive saved work while discarding pending previews', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $activeGraph = simpleFlowGraph();
    $active = $workspace->flows()->create(['name' => 'Ativo', 'graph' => $activeGraph]);
    $version = $active->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $activeGraph, 'published_at' => now()]);
    $active->update(['is_active' => true, 'published_version_id' => $version->id, 'graph' => simpleFlowGraph(false), 'revision' => 3]);
    $inactiveGraph = simpleFlowGraph(false);
    $inactive = $workspace->flows()->create(['name' => 'Inativo', 'graph' => $inactiveGraph, 'revision' => 1]);
    $session = app(DraftSessionService::class)->ensure($inactive);
    $session->update(['graph' => $activeGraph]);
    $conversation = FlowAiConversation::create(['workspace_id' => $workspace->id, 'flow_id' => $inactive->id, 'state' => 'editing']);
    $run = FlowAiRun::create(['conversation_id' => $conversation->id, 'user_id' => $user->id,
        'generation' => $session->generation, 'model' => 'test', 'status' => 'running', 'purpose' => 'editing']);

    $migration = require database_path('migrations/2026_11_12_000001_simplify_flow_editing.php');
    $migration->transitionExistingFlows();

    expect($active->fresh()->graph)->toBe($activeGraph)
        ->and($active->fresh()->is_active)->toBeTrue()
        ->and($active->fresh()->revision)->toBe(4)
        ->and($inactive->fresh()->graph)->toBe($inactiveGraph)
        ->and($run->fresh()->status)->toBe('cancelled')
        ->and($conversation->fresh()->state)->toBe('briefing')
        ->and(FlowDraftSession::count())->toBe(0);
});

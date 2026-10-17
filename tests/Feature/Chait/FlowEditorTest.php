<?php

use App\Models\Flow;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function validFlowGraph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'start-1', 'type' => 'start', 'position' => ['x' => 20, 'y' => 40], 'data' => ['label' => 'Start', 'description' => 'Início']],
            ['id' => 'content-1', 'type' => 'content', 'position' => ['x' => 20, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'description' => '', 'messages' => [['id' => 'text-1', 'text' => 'Olá']]]],
        ],
        'edges' => [['id' => 'edge-1', 'source' => 'start-1', 'sourceHandle' => 'next', 'target' => 'content-1', 'targetHandle' => 'input']],
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];
}

function addWorkspaceMember(Workspace $workspace, string $role = 'agent', array $attributes = []): User
{
    $user = User::factory()->create();
    $user->currentWorkspace()->associate($workspace)->save();
    WorkspaceMembership::query()->where('user_id', $user->id)->delete();
    WorkspaceMembership::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'joined_at' => now(),
    ], $attributes));

    return $user->fresh();
}

test('workspace owner creates a flow with one start node and edits its graph', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;

    $created = $this->actingAs($owner)->postJson(route('workspaces.flows.store', $workspace), ['name' => 'Boas-vindas'])
        ->assertCreated()->assertJsonPath('data.name', 'Boas-vindas')->assertJsonPath('data.revision', 1);

    $flow = Flow::query()->sole();
    expect($flow->graph['nodes'])->toHaveCount(1)->and($flow->graph['nodes'][0]['type'])->toBe('start');

    $this->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => 'Boas-vindas', 'graph' => validFlowGraph(), 'revision' => 1,
    ])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.graph.nodes.1.data.items.0.text', 'Olá');
});

test('flow editor access follows workspace roles and the explicit team permission', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = addWorkspaceMember($workspace);

    $this->actingAs($agent)->get(route('workspaces.flows.index', $workspace))->assertForbidden();

    $this->actingAs($owner)->patch(route('workspaces.members.permissions', [$workspace, $agent->membershipFor($workspace)->id]), ['can_manage_flows' => true])->assertRedirect();
    $this->actingAs($agent->fresh())->get(route('workspaces.flows.index', $workspace))->assertOk();

    $this->actingAs($owner)->patch(route('workspaces.members.permissions', [$workspace, $agent->membershipFor($workspace)->id]), ['can_manage_flows' => false])->assertRedirect();
    $this->actingAs($agent->fresh())->get(route('workspaces.flows.index', $workspace))->assertForbidden();
});

test('flow data is isolated to the requested workspace', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create();
    $flow = $workspace->flows()->create(['name' => 'Privado', 'graph' => ['schema_version' => 1, 'nodes' => [], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]]);

    $this->actingAs($owner)->get(route('workspaces.flows.edit', [$otherWorkspace, $flow]))->assertForbidden();
});

test('invalid connections, duplicate outputs and cycles are rejected', function (array $graph) {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Rascunho', 'graph' => validFlowGraph()]);

    $this->actingAs($owner)->putJson(route('workspaces.flows.update', [$workspace, $flow]), [
        'name' => $flow->name, 'graph' => $graph, 'revision' => 1,
    ])->assertUnprocessable();
})->with([
    'unknown handle' => [array_replace_recursive(validFlowGraph(), ['edges' => [['id' => 'edge-1', 'source' => 'start-1', 'sourceHandle' => 'wrong', 'target' => 'content-1', 'targetHandle' => 'input']]])],
    'duplicate output' => [array_replace_recursive(validFlowGraph(), ['nodes' => [['id' => 'start-1', 'type' => 'start', 'position' => ['x' => 20, 'y' => 40], 'data' => ['label' => 'Start']], ['id' => 'content-1', 'type' => 'content', 'position' => ['x' => 20, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'messages' => []]], ['id' => 'content-2', 'type' => 'content', 'position' => ['x' => 300, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'messages' => []]]], 'edges' => [['id' => 'edge-1', 'source' => 'start-1', 'sourceHandle' => 'next', 'target' => 'content-1', 'targetHandle' => 'input'], ['id' => 'edge-2', 'source' => 'start-1', 'sourceHandle' => 'next', 'target' => 'content-2', 'targetHandle' => 'input']]])],
    'cycle' => [array_replace_recursive(validFlowGraph(), ['edges' => [['id' => 'edge-1', 'source' => 'start-1', 'sourceHandle' => 'next', 'target' => 'content-1', 'targetHandle' => 'input'], ['id' => 'edge-2', 'source' => 'content-1', 'sourceHandle' => 'next', 'target' => 'start-1', 'targetHandle' => 'input']]])],
]);

test('concurrent flow updates return a conflict and preserve the winning revision', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $flow = $workspace->flows()->create(['name' => 'Rascunho', 'graph' => validFlowGraph()]);

    $this->actingAs($owner)->putJson(route('workspaces.flows.update', [$workspace, $flow]), ['name' => 'Primeira aba', 'graph' => validFlowGraph(), 'revision' => 1])->assertOk();
    $this->putJson(route('workspaces.flows.update', [$workspace, $flow]), ['name' => 'Segunda aba', 'graph' => validFlowGraph(), 'revision' => 1])->assertConflict();

    expect($flow->fresh()->name)->toBe('Primeira aba')->and($flow->fresh()->revision)->toBe(2);
});

<?php

use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function exclusiveFlowFixture(): array
{
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null]);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []];
    $flow = $workspace->flows()->create(['name' => 'Exclusivo', 'graph' => $graph]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);

    return compact('user', 'workspace', 'connection', 'contact', 'thread', 'flow', 'version');
}

test('all active flow states retain the exclusive reservation', function (string $status) {
    extract(exclusiveFlowFixture());
    $runtime = app(FlowRuntime::class);
    $first = $runtime->start($version, $contact->id, $thread->id, $connection, 'test');
    $first->update(['status' => $status]);
    expect($runtime->start($version, $contact->id, $thread->id, $connection, 'test'))->toBeNull()
        ->and(FlowExecution::count())->toBe(1)->and(AutomationContext::sole()->active_execution_id)->toBe($first->id);
})->with(FlowExecutionManager::ACTIVE);

test('finishing twice creates one terminal event and releases the reservation', function () {
    extract(exclusiveFlowFixture());
    $runtime = app(FlowRuntime::class);
    $execution = $runtime->start($version, $contact->id, $thread->id, $connection, 'test');
    $runtime->finish($execution, 'completed');
    $runtime->finish($execution, 'failed');
    expect($execution->fresh()->status)->toBe('completed')->and(AutomationContext::sole()->active_execution_id)->toBeNull()
        ->and(DB::table('flow_events')->where('event_type', 'completed')->count())->toBe(1);
    expect($runtime->start($version, $contact->id, $thread->id, $connection, 'test'))->not->toBeNull();
});

test('flow jumps transfer the reservation without a terminal completion notification', function () {
    extract(exclusiveFlowFixture());
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'test');
    app(FlowExecutionManager::class)->handoff($execution, $version);
    $next = FlowExecution::latest('id')->first();
    expect($next->continued_from_id)->toBe($execution->id)->and($next->status)->toBe('running')
        ->and(AutomationContext::sole()->active_execution_id)->toBe($next->id)
        ->and(DB::table('flow_events')->where('event_type', 'completed')->count())->toBe(0);
});

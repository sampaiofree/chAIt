<?php

use App\Jobs\ProcessFlowExecution;
use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Flows\FlowAutomationControl;
use App\Services\Flows\FlowRuntime;
use App\Services\Sequences\SequenceDefinitionService;
use App\Services\Sequences\SequenceEnrollmentService;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function sequenceFixture(array $steps = []): array
{
    test()->travelTo(now()->startOfSecond());
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $workspace->update(['timezone' => 'America/Sao_Paulo']);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null]);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []];
    $flow = $workspace->flows()->create(['name' => 'Etapa', 'graph' => $graph]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
    $steps = $steps ?: [['schedule_type' => 'interval', 'delay_seconds' => 60, 'flow_id' => $flow->id],
        ['schedule_type' => 'interval', 'delay_seconds' => 7200, 'flow_id' => $flow->id]];
    $sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => 'Pós-venda',
        'draft' => ['name' => 'Pós-venda', 'timezone' => $workspace->timezone, 'steps' => $steps]]);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 1);
    $sequence->refresh();

    return compact('user', 'workspace', 'connection', 'contact', 'thread', 'flow', 'version', 'sequence');
}

function sequenceEnroll(array $fixture, ?Sequence $sequence = null, ?string $key = null): SequenceEnrollment
{
    return app(SequenceEnrollmentService::class)->enroll($fixture['thread'], $sequence ?? $fixture['sequence'], 'manual', $key ?? (string) str()->uuid(), $fixture['user'])['enrollment'];
}

test('sequence reentry does not reset state and replacement preserves the running flow', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'test');
    $a = sequenceEnroll($fixture);
    $a->update(['remaining_seconds' => 37]);
    $repeat = app(SequenceEnrollmentService::class)->enroll($thread, $sequence, 'manual', 'repeat');
    expect($repeat['result'])->toBe('already_enrolled')->and($a->fresh()->remaining_seconds)->toBe(37);
    $other = Sequence::create(['workspace_id' => $workspace->id, 'name' => 'B', 'draft' => $sequence->draft]);
    app(SequenceDefinitionService::class)->publish($other, $workspace, $user, 1);
    $b = sequenceEnroll($fixture, $other->refresh());
    expect($a->fresh()->status)->toBe('replaced')->and($a->fresh()->replaced_by_id)->toBe($b->id)
        ->and($execution->fresh()->status)->toBe('running')->and($b->status)->toBe('waiting_current_flow')
        ->and(AutomationContext::sole()->current_enrollment_id)->toBe($b->id);
});

test('published sequence versions preserve their schedules and flow dependencies', function () {
    extract(sequenceFixture());
    $oldVersion = $sequence->published_version_id;
    $draft = $sequence->draft;
    $draft['steps'][0]['delay_seconds'] = 120;
    $sequence = app(SequenceDefinitionService::class)->save($sequence, $workspace, $draft, 1);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 2);
    expect($sequence->versions()->count())->toBe(2)
        ->and(DB::table('sequence_steps')->where('sequence_version_id', $oldVersion)->orderBy('position')->value('delay_seconds'))->toBe(60)
        ->and(DB::table('sequence_version_flows')->count())->toBe(2);
});

test('enrollment request replay cannot restore a replaced participation', function () {
    $fixture = sequenceFixture();
    $a = sequenceEnroll($fixture, key: 'once');
    $other = Sequence::create(['workspace_id' => $fixture['workspace']->id, 'name' => 'B', 'draft' => $fixture['sequence']->draft]);
    app(SequenceDefinitionService::class)->publish($other, $fixture['workspace'], $fixture['user'], 1);
    $b = sequenceEnroll($fixture, $other->refresh());
    $replayed = sequenceEnroll($fixture, key: 'once');
    expect($replayed->id)->toBe($a->id)->and($replayed->status)->toBe('replaced')
        ->and(AutomationContext::sole()->current_enrollment_id)->toBe($b->id);
});

test('drafts may be incomplete but publishing rejects missing schedules and foreign flows', function () {
    extract(sequenceFixture());
    $draft = ['name' => 'Incompleta', 'timezone' => 'UTC', 'steps' => [['schedule_type' => 'interval', 'flow_id' => null]]];
    expect(app(SequenceDefinitionService::class)->validate($workspace, $draft)['steps'][0]['flow_id'])->toBeNull();
    expect(fn () => app(SequenceDefinitionService::class)->validate($workspace, $draft, true))->toThrow(ValidationException::class);
    $other = sequenceFixture();
    $draft['steps'][0]['flow_id'] = $other['flow']->id;
    expect(fn () => app(SequenceDefinitionService::class)->validate($workspace, $draft))->toThrow(ValidationException::class);
});

test('relative clocks start after the previous flow and preserve remaining time while occupied', function () {
    $this->travelTo(now()->startOfDay());
    $fixture = sequenceFixture();
    extract($fixture);
    $enrollment = sequenceEnroll($fixture);
    expect($enrollment->status)->toBe('waiting_time')->and($enrollment->due_at->equalTo(now()->addMinute()))->toBeTrue();
    $this->travel(1)->minutes();
    app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    $enrollment->refresh();
    expect($enrollment->status)->toBe('running_step');
    $execution = FlowExecution::findOrFail($enrollment->current_step_execution_id);
    $execution->update(['status' => 'waiting_reply']);
    $this->travel(2)->days();
    app(SequenceScheduler::class)->tick();
    expect($enrollment->fresh()->status)->toBe('running_step')->and($enrollment->fresh()->due_at)->toBeNull();
    app(FlowRuntime::class)->finish($execution, 'completed');
    expect($enrollment->fresh()->due_at->equalTo(now()->addHours(2)))->toBeTrue();
    $this->travel(30)->minutes();
    $other = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'keyword');
    expect($enrollment->fresh()->remaining_seconds)->toBe(5400)->and($enrollment->fresh()->due_at)->toBeNull();
    $this->travel(20)->minutes();
    app(FlowRuntime::class)->finish($other, 'completed');
    expect($enrollment->fresh()->due_at->equalTo(now()->addMinutes(90)))->toBeTrue();
});

test('overlapping human and manual blockers preserve the clock until both are released', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $enrollment = sequenceEnroll($fixture);
    $this->travel(20)->seconds();
    app(FlowAutomationControl::class)->pause($thread, $user->id, null);
    expect($enrollment->fresh()->remaining_seconds)->toBe(40);
    $thread->update(['status' => 'open', 'human_opened_at' => now()]);
    app(FlowAutomationControl::class)->humanOpened($thread);
    $this->travel(2)->hours();
    app(FlowAutomationControl::class)->resume($thread);
    expect($enrollment->fresh()->status)->toBe('blocked')->and($enrollment->fresh()->remaining_seconds)->toBe(40);
    $thread->update(['status' => 'completed']);
    app(FlowAutomationControl::class)->humanFinished($thread);
    expect($enrollment->fresh()->due_at->equalTo(now()->addSeconds(40)))->toBeTrue();
});

test('fixed past dates wait for their position and human availability without changing the date', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $draft = $sequence->draft;
    $draft['steps'] = [['schedule_type' => 'specific_datetime', 'scheduled_at' => '2026-01-01T09:00', 'flow_id' => $flow->id]];
    $sequence = app(SequenceDefinitionService::class)->save($sequence, $workspace, $draft, 1);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 2);
    $thread->update(['status' => 'open', 'human_opened_at' => now()]);
    app(FlowAutomationControl::class)->humanOpened($thread);
    $enrollment = sequenceEnroll($fixture, $sequence->refresh());
    expect($enrollment->status)->toBe('blocked')->and($enrollment->due_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
    app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    expect(FlowExecution::count())->toBe(0);
    $thread->update(['status' => 'completed']);
    app(FlowAutomationControl::class)->humanFinished($thread);
    expect($enrollment->fresh()->status)->toBe('ready')->and($enrollment->fresh()->due_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
});

test('old jobs and repeated due jobs cannot start duplicate or replaced steps', function () {
    $fixture = sequenceFixture();
    $enrollment = sequenceEnroll($fixture);
    $generation = $enrollment->generation;
    $this->travel(1)->minutes();
    $scheduler = app(SequenceScheduler::class);
    $scheduler->execute($enrollment->id, $generation);
    $scheduler->execute($enrollment->id, $generation);
    expect(FlowExecution::count())->toBe(1);
    $execution = FlowExecution::sole();
    $other = Sequence::create(['workspace_id' => $fixture['workspace']->id, 'name' => 'B', 'draft' => $fixture['sequence']->draft]);
    app(SequenceDefinitionService::class)->publish($other, $fixture['workspace'], $fixture['user'], 1);
    $b = sequenceEnroll($fixture, $other->refresh());
    $scheduler->execute($enrollment->id, $enrollment->fresh()->generation);
    expect(FlowExecution::count())->toBe(1)->and($execution->fresh()->status)->toBe('running');
    app(FlowRuntime::class)->finish($execution, 'completed');
    app(FlowRuntime::class)->finish($execution, 'completed');
    expect($b->fresh()->due_at->equalTo(now()->addMinute()))->toBeTrue()->and($enrollment->fresh()->status)->toBe('replaced');
});

test('interruptions advance after availability and definitive failure ends only its own participation', function (string $outcome) {
    $fixture = sequenceFixture();
    $enrollment = sequenceEnroll($fixture);
    $this->travel(1)->minutes();
    app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    $execution = FlowExecution::sole();
    app(FlowRuntime::class)->finish($execution, $outcome, 'Motivo operacional', $outcome === 'failed' ? 'flow_failed' : 'keyword');
    $enrollment->refresh();
    expect($enrollment->status)->toBe($outcome === 'failed' ? 'failed' : 'waiting_time');
    if ($outcome === 'failed') {
        expect(AutomationContext::sole()->current_enrollment_id)->toBeNull();
    } else {
        expect($enrollment->due_at->equalTo(now()->addHours(2)))->toBeTrue();
    }
})->with(['interrupted', 'failed']);

test('last flow conclusion releases the participation and a future entry starts a new one', function () {
    $fixture = sequenceFixture();
    $enrollment = sequenceEnroll($fixture);
    $scheduler = app(SequenceScheduler::class);
    foreach ([60, 7200] as $seconds) {
        $this->travel($seconds)->seconds();
        $enrollment->refresh();
        $scheduler->execute($enrollment->id, $enrollment->generation);
        app(FlowRuntime::class)->finish(FlowExecution::findOrFail($enrollment->fresh()->current_step_execution_id), 'completed');
    }
    expect($enrollment->fresh()->status)->toBe('completed')->and($enrollment->fresh()->next_step_id)->toBeNull();
    $again = sequenceEnroll($fixture);
    expect($again->id)->not->toBe($enrollment->id)->and($again->nextStep->position)->toBe(0);
});

test('scheduler reconciles a terminal execution even when the notification was missed', function () {
    $fixture = sequenceFixture();
    $enrollment = sequenceEnroll($fixture);
    $this->travel(60)->seconds();
    $scheduler = app(SequenceScheduler::class);
    $scheduler->execute($enrollment->id, $enrollment->generation);
    FlowExecution::sole()->update(['status' => 'completed', 'terminal_reason' => 'completed', 'finished_at' => now()]);
    $this->travel(10)->minutes();
    $scheduler->tick();
    expect($enrollment->fresh()->due_at->equalTo(now()->addMinutes(110)))->toBeTrue();
});

test('pinned flow jumps keep the stage occupied through the final destination', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $target = $workspace->flows()->create(['name' => 'Destino', 'graph' => $flow->graph]);
    $targetVersion = $target->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $flow->graph, 'published_at' => now()]);
    $target->update(['is_active' => true, 'published_version_id' => $targetVersion->id]);
    $jump = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []],
        ['id' => 'jump', 'type' => 'jump_flow', 'data' => ['target_flow_id' => $target->id]]],
        'edges' => [['source' => 'start', 'sourceHandle' => 'next', 'target' => 'jump']]];
    $jumpVersion = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 2, 'graph' => $jump, 'published_at' => now()]);
    $flow->update(['published_version_id' => $jumpVersion->id]);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 1);
    $newTargetVersion = $target->versions()->create(['workspace_id' => $workspace->id, 'version' => 2, 'graph' => $target->graph, 'published_at' => now()]);
    $target->update(['published_version_id' => $newTargetVersion->id]);
    $enrollment = sequenceEnroll($fixture, $sequence->refresh());
    $this->travel(60)->seconds();
    app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    app(FlowRuntime::class)->run($enrollment->fresh()->current_step_execution_id);
    $destination = FlowExecution::findOrFail($enrollment->fresh()->current_step_execution_id);
    expect($destination->flow_version_id)->toBe($targetVersion->id)->and($enrollment->fresh()->status)->toBe('running_step');
    app(FlowRuntime::class)->run($destination->id);
    expect($enrollment->fresh()->status)->toBe('waiting_time')->and($enrollment->fresh()->nextStep->position)->toBe(1);
});

test('sequence HTTP lifecycle checks revisions, enrollment permissions and visibility', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $this->actingAs($user)->get(route('workspaces.sequences.index', $workspace))->assertOk();
    $this->postJson(route('workspaces.sequences.store', $workspace), ['name' => 'Nova'])->assertCreated();
    $this->putJson(route('workspaces.sequences.update', [$workspace, $sequence]), [...$sequence->draft, 'revision' => 99])->assertConflict();
    $key = (string) str()->uuid();
    $this->postJson(route('workspaces.contacts.sequence-enroll', [$workspace, $contact]), ['sequence_id' => $sequence->id, 'idempotency_key' => $key])
        ->assertOk()->assertJsonPath('result', 'enrolled')->assertJsonPath('data.status', 'waiting_time');
    $this->getJson(route('workspaces.contacts.sequence-enrollment', [$workspace, $contact]))->assertOk()->assertJsonPath('data.name', 'Pós-venda');
    $otherUser = User::factory()->create();
    $this->actingAs($otherUser)->get(route('workspaces.sequences.index', $workspace))->assertForbidden();
    $agent = User::factory()->create();
    WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'joined_at' => now(), 'can_manage_flows' => true]);
    $this->actingAs($agent)->getJson(route('workspaces.contacts.sequence-enrollment', [$workspace, $contact]))->assertNotFound();
    $thread->update(['assigned_to_user_id' => $agent->id]);
    $this->getJson(route('workspaces.contacts.sequence-enrollment', [$workspace, $contact]))->assertOk()->assertJsonPath('can_enroll', true);
    $membership = $agent->membershipFor($workspace);
    $membership->update(['can_manage_flows' => false]);
    $this->actingAs($agent->fresh())->postJson(route('workspaces.contacts.sequence-enroll', [$workspace, $contact]), ['sequence_id' => $sequence->id, 'idempotency_key' => (string) str()->uuid()])->assertForbidden();
});

test('deactivating blocks new enrollment but preserves existing versions and flow dependencies', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $enrollment = sequenceEnroll($fixture);
    $this->actingAs($user)->postJson(route('workspaces.flows.deactivate', [$workspace, $flow]))->assertConflict();
    $this->postJson(route('workspaces.sequences.deactivate', [$workspace, $sequence]))->assertOk();
    expect($enrollment->fresh()->status)->toBe('waiting_time');
    $this->postJson(route('workspaces.flows.deactivate', [$workspace, $flow]))->assertConflict();
    $otherContact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $otherThread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $otherContact->id, 'status' => 'bot', 'human_opened_at' => null]);
    $this->postJson(route('workspaces.contacts.sequence-enroll', [$workspace, $otherContact]), ['sequence_id' => $sequence->id, 'idempotency_key' => (string) str()->uuid()])->assertUnprocessable();
    $this->travel(1)->minutes();
    app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    expect(FlowExecution::sole()->flow_version_id)->toBe($version->id);
    $this->postJson(route('workspaces.sequences.activate', [$workspace, $sequence]))->assertOk();
});

test('operations enroll immediately and leave the current flow running until it ends', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []],
        ['id' => 'operations', 'type' => 'operations', 'data' => ['actions' => [['id' => 'enroll', 'kind' => 'add_to_sequence', 'sequence_id' => $sequence->id]]]],
        ['id' => 'content', 'type' => 'content', 'data' => ['items' => [['id' => 'delay', 'kind' => 'delay', 'seconds' => 5]]]]],
        'edges' => [['source' => 'start', 'sourceHandle' => 'next', 'target' => 'operations'], ['source' => 'operations', 'sourceHandle' => 'next', 'target' => 'content']]];
    $operationVersion = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 2, 'graph' => $graph, 'published_at' => now()]);
    $execution = app(FlowRuntime::class)->start($operationVersion, $contact->id, $thread->id, $connection, 'test');
    app(FlowRuntime::class)->run($execution->id);
    $enrollment = SequenceEnrollment::sole();
    expect($enrollment->status)->toBe('waiting_current_flow')->and($enrollment->due_at)->toBeNull()->and($execution->fresh()->status)->toBe('running');
    app(FlowRuntime::class)->finish($execution, 'completed');
    expect($enrollment->fresh()->due_at->equalTo(now()->addMinute()))->toBeTrue();
});

test('publication fixes nested dependencies and rejects empty or inactive steps', function () {
    extract(sequenceFixture());
    $this->actingAs($user)->get(route('workspaces.sequences.edit', [$workspace, $sequence]))->assertOk();
    $draft = $sequence->draft;
    $draft['steps'] = [];
    $this->putJson(route('workspaces.sequences.update', [$workspace, $sequence]), [...$draft, 'revision' => 1])->assertOk();
    $this->postJson(route('workspaces.sequences.publish', [$workspace, $sequence]), ['revision' => 2])->assertUnprocessable();
    $this->get(route('workspaces.sequences.index', $workspace).'?search=inexistente&status=active')->assertOk();
});

test('queued sends reconcile by tracking identifier even when the provider normalizes the Brazilian phone', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []],
        ['id' => 'content', 'type' => 'content', 'data' => ['items' => [['id' => 'text', 'kind' => 'text', 'text' => 'Teste']]]]],
        'edges' => [['source' => 'start', 'sourceHandle' => 'next', 'target' => 'content']]];
    $messageVersion = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 2, 'graph' => $graph, 'published_at' => now()]);
    Http::fake([
        'api.uazapi.test/send/text' => Http::response(['id' => 'queued-1', 'status' => 'queued']),
        'api.uazapi.test/message/find' => fn ($request) => Http::response(['messages' => isset($request['chatid']) ? [] : [['id' => 'delivered-1', 'status' => 'Delivered', 'chatid' => 'normalized@s.whatsapp.net']]]),
    ]);
    $execution = app(FlowRuntime::class)->start($messageVersion, $contact->id, $thread->id, $connection, 'test');
    foreach (range(1, 3) as $attempt) {
        app(FlowRuntime::class)->run($execution->id);
    }
    expect($execution->fresh()->status)->toBe('completed');
    $sends = collect(Http::recorded())->filter(fn ($call) => str_ends_with($call[0]->url(), '/send/text'));
    expect($sends)->toHaveCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/message/find') && isset($request['track_id']) && ! isset($request['chatid']));
});

test('reconciliation recovers lost execution jobs and respects durable content delays', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []],
        ['id' => 'delay', 'type' => 'content', 'data' => ['items' => [['id' => 'wait', 'kind' => 'delay', 'seconds' => 5]]]]],
        'edges' => [['source' => 'start', 'sourceHandle' => 'next', 'target' => 'delay']]];
    $version->update(['graph' => $graph]);
    $enrollment = sequenceEnroll($fixture);
    $this->travel(1)->minutes();
    $scheduler = app(SequenceScheduler::class);
    $scheduler->execute($enrollment->id, $enrollment->generation);
    $execution = FlowExecution::sole();
    $this->travel(3)->minutes();
    Queue::fake();
    $scheduler->tick();
    Queue::assertPushed(ProcessFlowExecution::class, fn ($job) => $job->executionId === $execution->id);
    app(FlowRuntime::class)->run($execution->id);
    app(FlowRuntime::class)->run($execution->id);
    expect($execution->fresh()->status)->toBe('running')->and($enrollment->fresh()->status)->toBe('running_step');
    $this->travel(5)->seconds();
    app(FlowRuntime::class)->run($execution->id);
    expect($enrollment->fresh()->status)->toBe('waiting_time');
});

test('absolute next stages wait for the preceding flow even after their date has passed', function () {
    $fixture = sequenceFixture();
    extract($fixture);
    $draft = $sequence->draft;
    $draft['steps'][1] = ['schedule_type' => 'specific_datetime', 'flow_id' => $flow->id, 'scheduled_at' => now($workspace->timezone)->subDay()->format('Y-m-d\TH:i')];
    $sequence = app(SequenceDefinitionService::class)->save($sequence, $workspace, $draft, 1);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 2);
    $enrollment = sequenceEnroll($fixture, $sequence->refresh());
    $this->travel(1)->minutes();
    $scheduler = app(SequenceScheduler::class);
    $scheduler->execute($enrollment->id, $enrollment->generation);
    $first = FlowExecution::sole();
    $first->update(['status' => 'waiting_reply']);
    $this->travel(2)->days();
    $scheduler->tick();
    expect(FlowExecution::count())->toBe(1)->and($enrollment->fresh()->status)->toBe('running_step');
    app(FlowRuntime::class)->finish($first, 'completed');
    expect($enrollment->fresh()->status)->toBe('ready');
    $scheduler->execute($enrollment->id, $enrollment->fresh()->generation);
    expect(FlowExecution::count())->toBe(2);
});

test('a persisted uncertain send intent only queries the provider and never repeats transport', function () {
    extract(sequenceFixture());
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []],
        ['id' => 'content', 'type' => 'content', 'data' => ['items' => [['id' => 'text', 'kind' => 'text', 'text' => 'Teste']]]]],
        'edges' => [['source' => 'start', 'sourceHandle' => 'next', 'target' => 'content']]];
    $version->update(['graph' => $graph]);
    $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'test');
    $execution->update(['current_node_id' => 'content', 'context' => ['pending_item_id' => 'text', 'pending_track_id' => 'persisted-intent', 'pending_attempts' => 1]]);
    Http::fake(['api.uazapi.test/message/find' => Http::response(['messages' => []])]);
    app(FlowRuntime::class)->run($execution->id);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/send/text'));
    expect($execution->fresh()->status)->toBe('running')->and($execution->fresh()->context['pending_attempts'])->toBe(2);
});

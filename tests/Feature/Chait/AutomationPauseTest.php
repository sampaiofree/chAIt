<?php

use App\Jobs\CleanupAiSession;
use App\Jobs\ProcessFlowExecution;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\ContactCustomField;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Flows\FlowInboundProcessor;
use App\Services\Flows\FlowRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function automationPauseFixture(): array
{
    Queue::fake();
    Http::fake(['*/chat/find' => Http::response(['chats' => []])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $workspace->update(['timezone' => 'America/Sao_Paulo']);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null,
        'assigned_to_user_id' => null,
    ]);
    $graph = ['schema_version' => 2, 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'data' => []],
    ], 'edges' => []];
    $flow = $workspace->flows()->create(['name' => 'Teste de pausa', 'graph' => $graph]);
    $version = $flow->versions()->create([
        'workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now(),
    ]);
    $flow->update(['is_active' => true, 'published_version_id' => $version->id]);

    return compact('user', 'workspace', 'connection', 'contact', 'thread', 'flow', 'version');
}

function startAutomationPauseExecution(array $fixture, string $status = 'running'): FlowExecution
{
    $execution = app(FlowRuntime::class)->start($fixture['version'], $fixture['contact']->id,
        $fixture['thread']->id, $fixture['connection'], 'test');
    $execution->update(['status' => $status]);

    return $execution;
}

test('a timed pause converts workspace time to UTC and can be resumed early without restarting a flow', function () {
    $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
    $fixture = automationPauseFixture();
    extract($fixture);
    $execution = startAutomationPauseExecution($fixture);

    $this->actingAs($user)->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), [
        'mode' => 'timed', 'expires_at' => '2026-10-13T14:30',
    ])->assertOk()->assertJsonPath('data.automation.state', 'paused')
        ->assertJsonPath('data.automation.expires_at', '2026-10-13T17:30:00+00:00')
        ->assertJsonPath('data.automation.can_pause', false)
        ->assertJsonPath('data.automation.can_resume', true);

    $pause = DB::table('automation_pauses')->sole();
    expect($pause->expires_at)->toBe('2026-10-13 17:30:00')
        ->and($pause->created_by_user_id)->toBe($user->id)
        ->and($execution->fresh()->status)->toBe('interrupted');
    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))
        ->assertOk()->assertJsonPath('data.automation.state', 'allowed')
        ->assertJsonPath('data.automation.can_pause', true)
        ->assertJsonPath('data.automation.expires_at', null);
    expect(FlowExecution::count())->toBe(1)->and($execution->fresh()->status)->toBe('interrupted');
    expect(DB::table('automation_pauses')->value('released_at'))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.automation_paused', 'user_id' => $user->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.automation_resumed', 'user_id' => $user->id]);
});

test('an indefinite pause stays active and duplicate requests do not change it', function () {
    $fixture = automationPauseFixture();
    extract($fixture);
    $url = route('workspaces.conversations.automations.pause', [$workspace, $thread]);
    $this->actingAs($user)->postJson($url, ['mode' => 'indefinite'])->assertOk()
        ->assertJsonPath('data.automation.expires_at', null);
    $this->postJson($url, ['mode' => 'timed', 'expires_at' => now($workspace->timezone)->addDay()->format('Y-m-d\TH:i')])
        ->assertConflict();
    expect(DB::table('automation_pauses')->count())->toBe(1)
        ->and(DB::table('automation_pauses')->value('expires_at'))->toBeNull();
    $this->travel(2)->days();
    $this->getJson(route('workspaces.conversations.index', $workspace).'?thread_id='.$thread->id)
        ->assertOk()->assertJsonPath('selected_thread.automation.state', 'paused')
        ->assertJsonPath('selected_thread.automation.can_resume', true);
    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))
        ->assertOk()->assertJsonPath('data.automation.state', 'allowed');
});

test('pausing interrupts an exclusive execution and only affects the selected contact and connection', function (string $status) {
    $fixture = automationPauseFixture();
    extract($fixture);
    $execution = startAutomationPauseExecution($fixture, $status);
    $reply = $execution;
    $ai = $execution;
    $field = ContactCustomField::create(['workspace_id' => $workspace->id, 'name' => 'Resposta']);
    DB::table('flow_waits')->insert([
        'workspace_id' => $workspace->id, 'flow_execution_id' => $reply->id, 'node_id' => 'reply',
        'field_id' => $field->id, 'status' => 'waiting', 'expires_at' => now()->addHour(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $session = AiSession::create([
        'workspace_id' => $workspace->id, 'flow_execution_id' => $ai->id, 'flow_version_id' => $version->id,
        'contact_id' => $contact->id, 'thread_id' => $thread->id, 'uazapi_connection_id' => $connection->id,
        'node_id' => 'ai', 'status' => 'active', 'model' => 'test', 'expires_at' => now()->addHour(),
    ]);
    $otherContact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $otherThread = Thread::factory()->create([
        'workspace_id' => $workspace->id, 'contact_id' => $otherContact->id, 'uazapi_connection_id' => $connection->id,
    ]);
    $otherContactExecution = startAutomationPauseExecution(array_replace($fixture, ['contact' => $otherContact, 'thread' => $otherThread]));
    $otherWorkspaceFixture = automationPauseFixture();
    $otherConnectionExecution = startAutomationPauseExecution($otherWorkspaceFixture);

    $this->actingAs($user)->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), ['mode' => 'indefinite'])
        ->assertOk()->assertJsonPath('data.automation.execution_status', null);
    foreach ([$execution] as $execution) {
        expect($execution->fresh()->status)->toBe('interrupted');
        app(FlowRuntime::class)->run($execution->id);
        expect($execution->fresh()->status)->toBe('interrupted');
    }
    expect(DB::table('flow_waits')->value('status'))->toBe('cancelled')
        ->and($session->fresh()->status)->toBe('interrupted')
        ->and($otherContactExecution->fresh()->status)->toBe('running')
        ->and($otherConnectionExecution->fresh()->status)->toBe('running');
    Queue::assertPushed(CleanupAiSession::class, fn ($job) => $job->sessionId === $session->id);
})->with(['running', 'waiting_reply', 'waiting_ai']);

test('expiration enables only new triggers and does not replay messages received while paused', function () {
    $fixture = automationPauseFixture();
    extract($fixture);
    $execution = startAutomationPauseExecution($fixture);
    DB::table('flow_trigger_settings')->insert([
        'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'default_flow_id' => $flow->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deadline = now($workspace->timezone)->addHour()->startOfMinute();
    $this->actingAs($user)->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), [
        'mode' => 'timed', 'expires_at' => $deadline->format('Y-m-d\TH:i'),
    ])->assertOk();
    app(FlowInboundProcessor::class)->handle($connection, $thread, ['id' => 'during-pause', 'text' => 'Olá']);
    expect(FlowExecution::count())->toBe(1);
    $this->travelTo($deadline->copy()->utc());
    $this->getJson(route('workspaces.conversations.index', $workspace).'?thread_id='.$thread->id)
        ->assertOk()->assertJsonPath('selected_thread.automation.state', 'allowed')
        ->assertJsonPath('selected_thread.automation.expires_at', null);
    expect(FlowExecution::count())->toBe(1)->and($execution->fresh()->status)->toBe('interrupted');
    app(FlowInboundProcessor::class)->handle($connection, $thread, ['id' => 'after-pause', 'text' => 'Olá novamente']);
    expect(FlowExecution::count())->toBe(2)
        ->and(DB::table('automation_pauses')->value('released_at'))->not->toBeNull();
    Queue::assertPushed(ProcessFlowExecution::class, 2);
});

test('human attendance stays blocked after a manual pause expires and finishing does not clear an indefinite pause', function () {
    $fixture = automationPauseFixture();
    extract($fixture);
    $thread->update(['status' => 'in_progress', 'assigned_to_user_id' => $user->id, 'human_opened_at' => now()]);
    $deadline = now($workspace->timezone)->addHour()->startOfMinute();
    $this->actingAs($user)->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), [
        'mode' => 'timed', 'expires_at' => $deadline->format('Y-m-d\TH:i'),
    ])->assertOk()->assertJsonPath('data.automation.can_resume', false);
    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))->assertConflict();
    $this->travelTo($deadline->copy()->addMinute()->utc());
    $this->getJson(route('workspaces.conversations.index', $workspace).'?thread_id='.$thread->id)
        ->assertOk()->assertJsonPath('selected_thread.automation.state', 'paused')
        ->assertJsonPath('selected_thread.automation.pause_reasons', ['human']);
    $this->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), ['mode' => 'indefinite'])->assertOk();
    $this->postJson(route('workspaces.conversations.finish', [$workspace, $thread]))
        ->assertOk()->assertJsonPath('data.automation.pause_reasons', ['manual'])
        ->assertJsonPath('data.automation.can_resume', true);
});

test('manual pause summaries prefer indefinite pauses and otherwise use the latest unexpired deadline', function () {
    $this->travelTo(now()->startOfSecond());
    $fixture = automationPauseFixture();
    extract($fixture);
    foreach ([now()->addHour(), now()->addHours(3), now()->subHour()] as $deadline) {
        DB::table('automation_pauses')->insert([
            'workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id,
            'thread_id' => $thread->id, 'reason' => 'manual', 'expires_at' => $deadline,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $url = route('workspaces.conversations.index', $workspace).'?thread_id='.$thread->id;
    $this->actingAs($user)->getJson($url)->assertOk()
        ->assertJsonPath('selected_thread.automation.expires_at', now()->addHours(3)->startOfSecond()->toIso8601String());
    DB::table('automation_pauses')->insert([
        'workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id,
        'thread_id' => $thread->id, 'reason' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->getJson($url)->assertOk()->assertJsonPath('selected_thread.automation.expires_at', null);
});

test('invalid pause deadlines and modes are rejected without creating a pause', function (array $payload) {
    $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
    $fixture = automationPauseFixture();
    extract($fixture);
    $this->actingAs($user)->postJson(route('workspaces.conversations.automations.pause', [$workspace, $thread]), $payload)
        ->assertUnprocessable();
    expect(DB::table('automation_pauses')->count())->toBe(0);
})->with([
    'missing date' => [['mode' => 'timed']],
    'invalid date' => [['mode' => 'timed', 'expires_at' => '2026-02-30T14:30']],
    'past workspace time' => [['mode' => 'timed', 'expires_at' => '2026-10-13T08:59']],
    'exact current time' => [['mode' => 'timed', 'expires_at' => '2026-10-13T09:00']],
    'unknown mode' => [['mode' => 'unknown']],
    'date for indefinite pause' => [['mode' => 'indefinite', 'expires_at' => '2026-10-14T14:30']],
]);

test('pause permissions match resume and require a connection and workspace access', function () {
    $fixture = automationPauseFixture();
    extract($fixture);
    $agent = User::factory()->create();
    $membership = WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => false,
    ]);
    $url = route('workspaces.conversations.automations.pause', [$workspace, $thread]);
    $this->actingAs($agent)->postJson($url, ['mode' => 'indefinite'])->assertNotFound();
    $thread->update(['assigned_to_user_id' => $agent->id]);
    $this->postJson($url, ['mode' => 'indefinite'])->assertOk();
    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))->assertOk();
    $thread->update(['assigned_to_user_id' => null]);
    $membership->update(['can_view_unassigned' => true]);
    $this->actingAs($agent->fresh())->postJson($url, ['mode' => 'indefinite'])->assertOk();
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->postJson($url, ['mode' => 'indefinite'])->assertForbidden();
    $thread->update(['uazapi_connection_id' => null]);
    $this->actingAs($user)->postJson($url, ['mode' => 'indefinite'])->assertConflict();
});

<?php

use App\Events\ChatUpdate;
use App\Jobs\Chat\ProcessChatOperation;
use App\Models\Contact;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Chat\ChatOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('applies each combination of conversation visibility to queries and individual threads', function (bool $own, bool $unassigned, bool $all) {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    $member = WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_own' => $own, 'can_view_unassigned' => $unassigned, 'can_view_all' => $all]);
    $threads = collect([
        Thread::factory()->create(['workspace_id' => $workspace->id, 'assigned_to_user_id' => $agent->id]),
        Thread::factory()->create(['workspace_id' => $workspace->id, 'assigned_to_user_id' => null]),
        Thread::factory()->create(['workspace_id' => $workspace->id, 'assigned_to_user_id' => $owner->id]),
    ]);
    $expected = [$all || $own, $all || $unassigned, $all];
    $query = Thread::where('workspace_id', $workspace->id);
    $ids = $member->scopeVisibleThreads($query)->pluck('id')->all();
    foreach ($threads as $i => $thread) {
        expect($member->canSeeThread($thread))->toBe($expected[$i]);
        expect(in_array($thread->id, $ids))->toBe($expected[$i]);
    }
})->with([[false, false, false], [true, false, false], [false, true, false], [true, true, false], [false, false, true], [true, false, true], [false, true, true], [true, true, true]]);

function visibilityFixture(array $permissions = []): array
{
    config(['chat.enabled' => false]);
    Queue::fake();
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $member = WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'joined_at' => now(), 'can_view_own' => true, 'can_view_all' => false, 'can_view_unassigned' => false, ...$permissions]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    Cache::put('chat-access:'.$connection->id, ['allowed' => true, 'status' => 'connected'], 15);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'assigned_to_user_id' => null]);

    return compact('owner', 'workspace', 'agent', 'member', 'connection', 'contact', 'thread');
}

it('rejects inaccessible history before consulting the provider', function (bool $realtime) {
    $f = visibilityFixture();
    config(['chat.enabled' => $realtime]);
    Cache::forget('chat-access:'.$f['connection']->id);
    Http::fake();
    $this->actingAs($f['agent'])->getJson(route('workspaces.conversations.messages', [$f['workspace'], $f['thread']]))->assertNotFound();
    Http::assertNothingSent();
})->with([false, true]);

it('keeps a queue-only attendant from claiming replying opening or pausing', function (bool $realtime) {
    $f = visibilityFixture(['can_view_own' => false, 'can_view_unassigned' => true]);
    config(['chat.enabled' => $realtime]);
    $this->actingAs($f['agent']);
    $this->getJson(route('workspaces.conversations.index', $f['workspace']))->assertOk()->assertJsonPath('counts.unassigned', 1);
    foreach (['claim', 'open', 'send', 'automations.pause'] as $action) {
        $this->postJson(route('workspaces.conversations.'.$action, [$f['workspace'], $f['thread']]), ['text' => 'não enviar', 'mode' => 'indefinite'])->assertForbidden();
    }
    expect($f['thread']->fresh()->assigned_to_user_id)->toBeNull();
})->with([false, true]);

it('redirects a user without chat to the first permitted area', function (string $area) {
    $f = visibilityFixture(['can_view_own' => false, 'can_view_leads' => $area === 'leads', 'can_manage_flows' => $area === 'flows']);
    $target = match ($area) {
        'leads' => route('workspaces.leads.index', $f['workspace']),
        'flows' => route('workspaces.flows.index', $f['workspace']),
        default => url('/settings/profile'),
    };
    $this->actingAs($f['agent'])->get('/dashboard')->assertRedirect($target);
    Http::fake();
    $this->getJson('/workspaces/'.$f['workspace']->id.'/chat-access')->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('status', 'forbidden');
    Http::assertNothingSent();
})->with(['leads', 'flows', 'profile']);

it('cannot assign a conversation to a queue-only member', function () {
    $f = visibilityFixture(['can_view_own' => false, 'can_view_unassigned' => true]);
    $this->actingAs($f['owner'])->postJson(route('workspaces.conversations.assign', [$f['workspace'], $f['thread']]), ['user_id' => $f['agent']->id])->assertUnprocessable();
});

it('rechecks permissions when a queued history job starts', function () {
    $f = visibilityFixture(['can_view_all' => true]);
    config(['chat.enabled' => true, 'services.uazapi.base_url' => 'https://provider.test', 'broadcasting.default' => 'null']);
    Event::fake([ChatUpdate::class]);
    $operation = app(ChatOperations::class)->create($f['thread'], $f['agent']->id, 'history', []);
    $f['member']->update(['can_view_all' => false, 'can_view_own' => false]);
    Cache::forget('chat-access:'.$f['connection']->id);
    Http::fake();
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('isolates visibility queries even for administrators', function () {
    $f = visibilityFixture();
    $other = User::factory()->create();
    $foreign = Thread::factory()->create(['workspace_id' => $other->fresh()->currentWorkspace->id]);
    $member = $f['owner']->membershipFor($f['workspace']);
    expect($member->scopeVisibleThreads(Thread::query())->pluck('id')->all())->not->toContain($foreign->id);
});

it('preserves existing access when migrating and defaults new members to own conversations', function () {
    $f = visibilityFixture();
    $migration = require database_path('migrations/2026_11_21_000001_add_chat_visibility_permissions.php');
    $migration->down();
    DB::table('workspace_memberships')->where('id', $f['member']->id)->update(['can_view_unassigned' => true]);
    $migration->up();
    expect($f['member']->fresh()->can_view_all)->toBeTrue()->and($f['member']->fresh()->can_view_own)->toBeTrue();
    $other = User::factory()->create();
    $member = WorkspaceMembership::create(['workspace_id' => $f['workspace']->id, 'user_id' => $other->id, 'role' => 'agent']);
    expect($member->fresh()->can_view_own)->toBeTrue()->and($member->fresh()->can_view_all)->toBeFalse()->and($member->fresh()->can_view_unassigned)->toBeFalse();
});

it('reports only inaccessible draft ids and changes the permission signature on revocation', function () {
    $f = visibilityFixture(['can_view_all' => true]);
    $this->actingAs($f['agent']);
    $url = '/workspaces/'.$f['workspace']->id.'/chat-access?draft_ids[]='.$f['thread']->id;
    $previous = $this->getJson($url)->assertOk()->assertJsonPath('revoked_draft_ids', [])->json('permissions_signature');
    $f['member']->update(['can_view_all' => false, 'can_view_own' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('revoked_draft_ids', [$f['thread']->id])->assertJsonPath('allowed', false)->assertJsonMissing(['permissions_signature' => $previous]);
});

it('filters assignment counts before pagination through the API', function (bool $own, bool $unassigned, bool $all) {
    $f = visibilityFixture(['can_view_own' => $own, 'can_view_unassigned' => $unassigned, 'can_view_all' => $all]);
    Thread::factory()->create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'contact_id' => $f['contact']->id, 'assigned_to_user_id' => $f['agent']->id]);
    Thread::factory()->create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'contact_id' => $f['contact']->id, 'assigned_to_user_id' => $f['owner']->id]);
    $response = $this->actingAs($f['agent'])->getJson(route('workspaces.conversations.index', $f['workspace']));
    if (! $own && ! $unassigned && ! $all) {
        $response->assertForbidden();
    } else {
        $response->assertOk()->assertJsonPath('counts.mine', (int) ($own || $all))->assertJsonPath('counts.unassigned', (int) ($unassigned || $all))->assertJsonPath('counts.all', $all ? 3 : (int) $own + (int) $unassigned);
    }
})->with([[false, false, false], [true, false, false], [false, true, false], [true, true, false], [false, false, true], [true, false, true], [false, true, true], [true, true, true]]);

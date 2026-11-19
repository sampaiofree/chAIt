<?php

use App\Jobs\Chat\ProcessChatOperation;
use App\Jobs\ProcessAiTurn;
use App\Jobs\ProcessFlowExecution;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Chat\ChatOperations;
use App\Services\Chat\ChatProjection;
use App\Services\Flows\FlowInboundProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('admin creates verified leads and edits and lists them without WhatsApp', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    config(['services.uazapi.base_url' => 'https://uazapi.test', 'chat.enabled' => false]);
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    Http::fake(['*/chat/check' => Http::response([['query' => '5511987654321', 'isInWhatsapp' => true]])]);
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/leads", ['phone' => '+55 (11) 98765-4321', 'display_name' => 'Maria'])->assertSessionHasNoErrors()->assertRedirect();
    $lead = Contact::where('workspace_id', $workspace->id)->sole();
    UazapiConnection::where('workspace_id', $workspace->id)->delete();
    expect($lead->phone)->toBe('5511987654321');
    $this->get("/workspaces/{$workspace->id}/leads")->assertOk();
    $this->get("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertOk();
    $this->patch("/workspaces/{$workspace->id}/leads/{$lead->id}", ['observations' => 'Cliente interessado'])->assertRedirect();
    expect($lead->fresh()->observations)->toBe('Cliente interessado');
    $this->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321'])->assertSessionHasErrors('phone');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

test('lead routes cannot expose another workspace', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = $other->fresh()->currentWorkspace;
    $this->actingAs($user)->get("/workspaces/{$workspace->id}/leads")->assertForbidden();
});

test('disconnected chat is blocked at API while leads remain available', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $this->actingAs($user)->getJson("/workspaces/{$workspace->id}/conversations")->assertStatus(409);
    $this->getJson("/workspaces/{$workspace->id}/chat-access")->assertOk()->assertJsonPath('allowed', false);
    $this->get("/workspaces/{$workspace->id}/leads")->assertOk();
});

test('provider failures block access even if last status was connected', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    Http::fake(fn () => Http::response([], 503));
    $this->actingAs($user)->getJson("/workspaces/{$workspace->id}/conversations")->assertStatus(503);
});

test('manual lead creation requires positive verification and preserves submitted fields on failure', function ($payload, $status, $message) {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    config(['services.uazapi.base_url' => 'https://uazapi.test', 'chat.enabled' => false]);
    Http::fake(['*' => Http::response($payload, $status)]);
    $this->actingAs($user)->from("/workspaces/{$workspace->id}/leads")
        ->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321', 'display_name' => 'Maria'])
        ->assertRedirect()->assertSessionHasErrors(['phone' => $message])->assertSessionHas('_old_input.display_name', 'Maria');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(0);
})->with([
    'not registered' => [[['query' => '5511987654321', 'isInWhatsapp' => false]], 200, 'Este número não está cadastrado no WhatsApp.'],
    'provider unavailable' => [[], 503, 'Não foi possível verificar este número. Confira a conexão do WhatsApp e tente novamente.'],
    'invalid response' => [[], 200, 'Não foi possível verificar este número. Confira a conexão do WhatsApp e tente novamente.'],
]);

test('unauthorized invalid and duplicate lead submissions do not query the provider', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    Http::fake();
    $this->actingAs($other)->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321'])->assertForbidden();
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/leads", ['phone' => 'abc'])->assertSessionHasErrors('phone');
    Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321']);
    $this->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321'])->assertSessionHasErrors('phone');
    Http::assertNothingSent();
});

test('manual creation never uses a connection from another workspace', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    UazapiConnection::factory()->create();
    Http::fake();
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321'])->assertSessionHasErrors('phone');
    Http::assertNothingSent();
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('a contact arriving during verification is not overwritten or duplicated', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    config(['services.uazapi.base_url' => 'https://uazapi.test', 'chat.enabled' => false]);
    Http::fake(function () use ($workspace) {
        Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321', 'display_name' => 'Webhook']);

        return Http::response([['query' => '5511987654321', 'isInWhatsapp' => true]]);
    });
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321', 'display_name' => 'Manual'])->assertSessionHasErrors('phone');
    expect(Contact::where('workspace_id', $workspace->id)->sole()->display_name)->toBe('Webhook');
});

test('a lead conversation is created on demand and reused without starting attendance', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    config(['services.uazapi.base_url' => 'https://uazapi.test']);
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    Http::fake(['*/instance/status' => Http::response(['status' => 'connected'])]);
    $lead = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321']);
    $url = "/workspaces/{$workspace->id}/conversations/leads/{$lead->id}/open";
    $id = $this->actingAs($user)->postJson($url)->assertOk()->json('data.thread_id');
    $this->postJson($url)->assertOk()->assertJsonPath('data.thread_id', $id);
    $thread = Thread::findOrFail($id);
    expect($thread->status)->toBe('bot')->and($thread->assigned_to_user_id)->toBeNull()->and($thread->human_opened_at)->toBeNull();
    expect($lead->threads()->count())->toBe(1);
});

test('deleting a lead resets its identity and rejects old messages while allowing a new contact', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $lead = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321']);
    $projection = app(ChatProjection::class);
    $jid = $lead->phone.'@s.whatsapp.net';
    $thread = $projection->sync($connection, ['wa_chatid' => $jid], null, true);
    $this->actingAs($user)->delete("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertRedirect();
    $this->assertDatabaseMissing('contacts', ['id' => $lead->id]);
    $this->assertDatabaseMissing('threads', ['id' => $thread->id]);
    expect($projection->sync($connection, [], ['chatid' => $jid, 'messageTimestamp' => now()->subMinute()->valueOf()], true))->toBeNull();
    $this->travel(1)->seconds();
    $new = $projection->sync($connection, [], ['chatid' => $jid, 'messageTimestamp' => now()->valueOf(), 'fromMe' => false], true);
    expect($new->contact_id)->not->toBe($lead->id)->and($new->status)->toBe('bot');
    Queue::fake();
    $flow = $workspace->flows()->create(['name' => 'Boas-vindas', 'is_active' => true, 'graph' => ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []]]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $flow->graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id]);
    DB::table('flow_trigger_settings')->insert(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'welcome_flow_id' => $flow->id]);
    app(FlowInboundProcessor::class)->handle($connection, $new, ['id' => 'fresh-message', 'chatid' => $jid, 'text' => 'Olá', 'fromMe' => false]);
    expect(FlowExecution::where('contact_id', $new->contact_id)->sole()->trigger_source)->toBe('welcome');

});

test('deletion cannot remove a contact in another workspace', function () {
    $user = User::factory()->create();
    $lead = Contact::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $this->actingAs($user)->delete("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertNotFound();
    $this->assertDatabaseHas('contacts', ['id' => $lead->id]);
});

test('deletion removes active execution and waits and queued operations', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $lead = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $lead->id, 'uazapi_connection_id' => $connection->id]);
    $flow = $workspace->flows()->create(['name' => 'Teste', 'graph' => ['nodes' => [], 'edges' => []]]);
    $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $flow->graph, 'published_at' => now()]);
    $execution = FlowExecution::create(['workspace_id' => $workspace->id, 'flow_id' => $flow->id, 'flow_version_id' => $version->id, 'contact_id' => $lead->id, 'thread_id' => $thread->id, 'uazapi_connection_id' => $connection->id, 'status' => 'waiting_ai', 'trigger_source' => 'welcome', 'current_node_id' => 'ai', 'started_at' => now()]);
    $session = AiSession::create(['workspace_id' => $workspace->id, 'flow_execution_id' => $execution->id, 'flow_version_id' => $version->id, 'contact_id' => $lead->id, 'thread_id' => $thread->id, 'uazapi_connection_id' => $connection->id, 'node_id' => 'ai', 'status' => 'active', 'model' => 'test', 'expires_at' => now()->addMinute()]);
    $sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => 'Sequência', 'draft' => []]);
    $sequenceVersion = $sequence->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'name' => 'Sequência', 'timezone' => 'UTC', 'published_at' => now()]);
    $enrollment = SequenceEnrollment::create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $lead->id, 'thread_id' => $thread->id, 'sequence_id' => $sequence->id, 'sequence_version_id' => $sequenceVersion->id, 'status' => 'waiting_time', 'source' => 'test', 'enrolled_at' => now(), 'due_at' => now()->addMinute()]);
    $execution->update(['sequence_enrollment_id' => $enrollment->id]);
    DB::table('flow_first_contacts')->insert(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $lead->id, 'first_inbound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $operation = app(ChatOperations::class)->create($thread, $user->id, 'send', ['text' => 'Não enviar']);
    $this->actingAs($user)->delete("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertRedirect();
    $this->assertDatabaseMissing('sequence_enrollments', ['id' => $enrollment->id]);
    $this->assertDatabaseMissing('flow_first_contacts', ['contact_id' => $lead->id]);
    $this->assertDatabaseHas('sequences', ['id' => $sequence->id]);
    $this->assertDatabaseMissing('ai_sessions', ['id' => $session->id]);
    $this->assertDatabaseMissing('flow_executions', ['id' => $execution->id]);
    $this->assertDatabaseMissing('chat_operations', ['id' => $operation->id]);
    Http::fake();
    app()->call([new ProcessFlowExecution($execution->id), 'handle']);
    app()->call([new ProcessAiTurn($session->id), 'handle']);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    Http::assertNothingSent();
});

test('reset history excludes messages from before deletion', function () {
    config(['chat.enabled' => false, 'services.uazapi.base_url' => 'https://uazapi.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    $lead = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321']);
    $jid = $lead->phone.'@s.whatsapp.net';
    DB::table('contact_resets')->insert(['workspace_id' => $workspace->id, 'external_id' => $jid, 'reset_at' => now()->subMinute()]);
    $thread = app(ChatProjection::class)->sync($connection, ['wa_chatid' => $jid], null, true);
    Http::fake([
        '*/instance/status' => Http::response(['status' => 'connected']),
        '*/message/find' => Http::response(['messages' => [
            ['id' => 'new', 'chatid' => $jid, 'messageTimestamp' => now()->valueOf(), 'text' => 'Novo'],
            ['id' => 'old', 'chatid' => $jid, 'messageTimestamp' => now()->subHour()->valueOf(), 'text' => 'Antigo'],
        ]]),
    ]);
    $this->actingAs($user)->getJson("/workspaces/{$workspace->id}/conversations/{$thread->id}/messages")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'new')->assertJsonPath('next_offset', null);
});

test('lead editors can delete but cannot bypass chat visibility to create a conversation', function () {
    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    $member = WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'can_view_leads' => true, 'can_edit_leads' => false, 'can_view_unassigned' => false]);
    $lead = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5511987654321']);
    $this->actingAs($agent)->delete("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertForbidden();
    UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    config(['services.uazapi.base_url' => 'https://uazapi.test']);
    Http::fake(['*/instance/status' => Http::response(['status' => 'connected'])]);
    $this->postJson("/workspaces/{$workspace->id}/conversations/leads/{$lead->id}/open")->assertForbidden();
    $member->update(['can_edit_leads' => true]);
    $this->actingAs($agent->fresh())->delete("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertRedirect();
    $this->assertDatabaseMissing('contacts', ['id' => $lead->id]);
});

<?php

use App\Events\ChatUpdate;
use App\Jobs\Chat\ImportChats;
use App\Jobs\Chat\PrepareAiInput;
use App\Jobs\Chat\ProcessChatOperation;
use App\Jobs\Chat\ProcessConnectionOperation;
use App\Jobs\Chat\ProcessInboundEvent;
use App\Jobs\ProcessAiTurn;
use App\Models\AiSession;
use App\Models\ChatOperation;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceOpenAiIntegration;
use App\Queue\Middleware\WorkspaceCapacity;
use App\Services\Chat\ChatMaintenance;
use App\Services\Chat\ChatOperations;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatPublisher;
use App\Services\Chat\TransportContext;
use App\Services\Flows\FlowInboundProcessor;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDeferredException;
use App\Services\Uazapi\UazapiGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['chat.enabled' => true, 'services.uazapi.base_url' => 'https://provider.test', 'broadcasting.default' => 'null']);
    Queue::fake();
    Event::fake([ChatUpdate::class]);
});

function runtimeChat(): array
{
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected', 'activated_at' => now()->subMinute()]);
    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
    $connection->update(['webhook_secret_hash' => hash('sha256', $secret)]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '5527999999999']);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '5527999999999@s.whatsapp.net', 'status' => 'in_progress', 'assigned_to_user_id' => $user->id, 'unread_count' => 0]);

    return compact('user', 'workspace', 'connection', 'thread', 'secret');
}

function runtimeWebhook(array $f, string $id = 'message-1', array $extra = []): array
{
    return array_replace_recursive(['EventType' => 'messages', 'token' => $f['connection']->instance_token, 'message' => ['id' => $id, 'messageid' => $id, 'chatid' => $f['thread']->external_chat_id, 'messageType' => 'text', 'text' => 'private message body', 'fromMe' => false, 'messageTimestamp' => now()->timestamp * 1000]], $extra);
}

function receiveRuntimeWebhook(array $f, array $payload)
{
    return test()->postJson(route('webhooks.uazapi', ['connection' => $f['connection'], 'secret' => $f['secret']]), $payload);
}

test('local list and connection never call the provider', function () {
    $f = runtimeChat();
    Http::fake();
    $this->actingAs($f['user'])->getJson(route('workspaces.conversations.index', $f['workspace']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(route('workspaces.uazapi.status', $f['workspace']))->assertOk()->assertJsonPath('data.status', 'connected');
    Http::assertNothingSent();
});

test('contact tag filters combine with search, assignment and open status in the local list', function () {
    $f = runtimeChat();
    $first = $f['workspace']->tags()->create(['name' => 'Primeira', 'color' => '#16a34a']);
    $second = $f['workspace']->tags()->create(['name' => 'Segunda', 'color' => '#0f766e']);
    $foreign = User::factory()->create()->fresh()->currentWorkspace->tags()->create(['name' => 'Privada', 'color' => '#16a34a']);
    $f['thread']->contact->update(['display_name' => 'Alfa']);
    $f['thread']->contact->tags()->attach($first->id, ['workspace_id' => $f['workspace']->id]);
    $otherContact = Contact::factory()->create(['workspace_id' => $f['workspace']->id, 'display_name' => 'Beta']);
    $otherContact->tags()->attach($second->id, ['workspace_id' => $f['workspace']->id]);
    $other = Thread::factory()->create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'contact_id' => $otherContact->id, 'status' => 'completed', 'assigned_to_user_id' => null]);
    $url = route('workspaces.conversations.index', $f['workspace']);

    $both = $this->actingAs($f['user'])->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id]]))->assertOk();
    expect(collect($both->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$f['thread']->id, $other->id])->sort()->values()->all());
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id], 'assignment' => 'mine', 'status' => 'open', 'search' => 'Alfa']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['thread']->id);
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$foreign->id]]))->assertUnprocessable();
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['workspace']->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => false]);
    $this->actingAs($agent)->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id]]))->assertOk()->assertJsonCount(0, 'data');
});

test('an empty workspace lists no provider chats in either mode', function (bool $realtime) {
    config(['chat.enabled' => $realtime]);
    $f = runtimeChat();
    $f['thread']->delete();
    $f['connection']->update(['chat_import_status' => 'not_started', 'chat_last_reconciled_at' => null]);
    Http::fake();

    $this->actingAs($f['user'])->getJson(route('workspaces.conversations.index', $f['workspace']))
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonMissingPath('import');
    Queue::assertNotPushed(ImportChats::class);
    Http::assertNothingSent();
})->with([true, false]);

test('existing conversations remain local after stale reconciliation state and disconnection', function (bool $realtime) {
    config(['chat.enabled' => $realtime]);
    $f = runtimeChat();
    $f['connection']->update(['status' => 'disconnected', 'chat_import_status' => 'failed', 'chat_last_reconciled_at' => now()->subDays(2)]);
    Http::fake();

    $this->actingAs($f['user'])->getJson(route('workspaces.conversations.index', $f['workspace']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['thread']->id);
    app(ChatMaintenance::class)->recover();
    Queue::assertNotPushed(ImportChats::class);
    Http::assertNothingSent();
})->with([true, false]);

test('retired import endpoint and jobs never scan the provider', function () {
    $f = runtimeChat();
    Http::fake();
    $this->actingAs($f['user'])->postJson(route('workspaces.uazapi.import', $f['workspace']))->assertStatus(410);
    app()->call([(new ImportChats($f['connection']->id, 0)), 'handle']);
    Queue::assertNotPushed(ImportChats::class);
    Http::assertNothingSent();
});

test('only new inbound messages discover a conversation in either chat mode', function (bool $realtime) {
    config(['chat.enabled' => $realtime]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'activated_at' => now()->subMinute()]);
    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
    $connection->update(['webhook_secret_hash' => hash('sha256', $secret)]);
    $url = route('webhooks.uazapi', [$connection, $secret]);
    $chatId = '5527999999999@s.whatsapp.net';
    $message = fn (string $id, array $changes = []) => ['EventType' => 'messages', 'token' => $connection->instance_token,
        'message' => ['id' => $id, 'messageid' => $id, 'chatid' => $chatId, 'fromMe' => false, 'messageType' => 'text', 'text' => 'Olá', 'messageTimestamp' => now()->timestamp * 1000, ...$changes]];
    Http::fake();

    $this->postJson($url, ['EventType' => 'chats', 'chat' => ['wa_chatid' => $chatId, 'wa_lastMsgTimestamp' => now()->timestamp]])->assertOk();
    $this->postJson($url, $message('sent', ['fromMe' => true]))->assertOk();
    $this->postJson($url, $message('old', ['messageTimestamp' => now()->subDay()->timestamp * 1000]))->assertOk();
    $this->postJson($url, $message('seconds', ['messageTimestamp' => now()->timestamp]))->assertOk();
    $this->postJson($url, $message('group', ['isGroup' => true]))->assertOk();
    $this->postJson($url, $message('lid', ['chatid' => '12345@lid']))->assertOk();
    $this->postJson($url, $message('invalid-phone', ['chatid' => '123@s.whatsapp.net']))->assertOk();
    $this->postJson($url, [...$message('wrong-chat'), 'chat' => ['chatid' => '5527888888888@s.whatsapp.net']])->assertUnprocessable();
    foreach (UazapiInbox::orderBy('id')->get() as $inbox) {
        app()->call([new ProcessInboundEvent($inbox->id), 'handle']);
    }
    expect(Thread::count())->toBe(0)->and(Contact::count())->toBe(0);
    expect(UazapiInbox::orderBy('id')->pluck('decision')->all())->toBe(['chat_unknown', 'outbound_unknown', 'before_activation', 'invalid_timestamp', 'group_message', 'unsupported_jid', 'invalid_phone']);

    $this->postJson($url, $message('new'))->assertOk();
    $this->postJson($url, $message('provider-variant', ['messageid' => 'new']))->assertOk();
    expect(UazapiInbox::where('event_type', 'messages')->count())->toBe(7);
    expect(UazapiInbox::latest('id')->first()->delivery_count)->toBe(2);
    app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    expect(UazapiInbox::latest('id')->first()->status)->toBe('processed');
    expect(UazapiInbox::latest('id')->first()->decision)->toBe('inbound_projected');
    expect(Contact::count())->toBe(1)->and(Thread::count())->toBe(1)
        ->and(Thread::sole()->status)->toBe('bot')
        ->and(Thread::sole()->last_message_preview)->toBe('Olá')
        ->and(Thread::sole()->unread_count)->toBe(1);
    $this->postJson($url, $message('new'))->assertOk();
    expect(UazapiInbox::latest('id')->first()->delivery_count)->toBe(3)
        ->and(Thread::sole()->unread_count)->toBe(1);

    $this->postJson($url, $message('second', ['text' => 'Outra']))->assertOk();
    app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    expect(Contact::count())->toBe(1)->and(Thread::count())->toBe(1)->and(Thread::sole()->unread_count)->toBe(2);
    $this->actingAs($user)->getJson(route('workspaces.conversations.index', $workspace))->assertOk()->assertJsonCount(1, 'data');
    Http::assertNothingSent();
})->with([true, false]);

test('message JID takes precedence over a provider-local chat record ID', function (bool $realtime) {
    config(['chat.enabled' => $realtime]);
    $f = runtimeChat();
    $f['thread']->delete();
    $f['thread']->contact->delete();
    $chatId = '5527999999998@s.whatsapp.net';
    receiveRuntimeWebhook($f, runtimeWebhook($f, 'local-chat-id', [
        'message' => ['chatid' => $chatId],
        'chat' => ['id' => 'provider-local-chat-record'],
    ]))->assertOk();
    $inbox = UazapiInbox::sole();
    app()->call([new ProcessInboundEvent($inbox->id), 'handle']);

    expect($inbox->fresh()->decision)->toBe('inbound_projected')
        ->and(Thread::sole()->external_chat_id)->toBe($chatId)
        ->and(Thread::sole()->last_inbound_at)->not->toBeNull();
})->with([true, false]);

test('reconnection preserves the original activation cutoff', function () {
    $f = runtimeChat();
    $original = $f['connection']->activated_at;
    foreach (['disconnected', 'connected'] as $state) {
        receiveRuntimeWebhook($f, ['EventType' => 'connection', 'instance' => ['status' => $state]])->assertOk();
        app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    }
    expect($f['connection']->fresh()->activated_at->equalTo($original))->toBeTrue();
    expect(Thread::count())->toBe(1);
});

test('first connection confirmation records an activation cutoff only once', function () {
    $f = runtimeChat();
    $f['connection']->update(['status' => 'disconnected', 'activated_at' => null]);
    receiveRuntimeWebhook($f, ['EventType' => 'connection', 'instance' => ['status' => 'connected']])->assertOk();
    app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    $original = $f['connection']->fresh()->activated_at;
    expect($original)->not->toBeNull();

    receiveRuntimeWebhook($f, ['EventType' => 'connection', 'instance' => ['status' => 'disconnected']])->assertOk();
    app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    receiveRuntimeWebhook($f, ['EventType' => 'connection', 'instance' => ['status' => 'connected']])->assertOk();
    app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
    expect($f['connection']->fresh()->activated_at->equalTo($original))->toBeTrue();
});

test('webhook acceptance encrypts content and queues only a reference', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f))->assertOk();
    $inbox = UazapiInbox::sole();
    expect($inbox->payload['message']['text'])->toBe('private message body')
        ->and($inbox->payload)->not->toHaveKey('token')
        ->and(DB::table('uazapi_inbox')->value('payload'))->not->toContain('private message body')
        ->and(serialize(new ProcessInboundEvent($inbox->id)))->not->toContain('private message body')
        ->and($f['thread']->fresh()->unread_count)->toBe(0);
    Queue::assertPushedOn('inbound', ProcessInboundEvent::class);
});

test('duplicate messages have one durable receipt and one state transition', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f))->assertOk();
    receiveRuntimeWebhook($f, runtimeWebhook($f))->assertOk();
    expect(UazapiInbox::count())->toBe(1);
    $job = new ProcessInboundEvent(UazapiInbox::sole()->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($f['thread']->fresh()->unread_count)->toBe(1)->and(UazapiInbox::sole()->payload)->toBeNull()->and(UazapiInbox::sole()->status)->toBe('processed')
        ->and($f['connection']->fresh()->chat_last_webhook_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('failed inbound domain work rolls back projections and publishes no message', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f))->assertOk();
    Event::fake([ChatUpdate::class]);
    $this->mock(FlowInboundProcessor::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('simulated domain failure')));
    app()->call([new ProcessInboundEvent(UazapiInbox::sole()->id), 'handle']);
    expect($f['thread']->fresh()->unread_count)->toBe(0)
        ->and($f['thread']->fresh()->last_message_preview)->toBeNull()
        ->and(UazapiInbox::sole()->status)->toBe('failed')
        ->and(UazapiInbox::sole()->payload['message']['text'])->toBe('private message body');
    Event::assertNotDispatched(ChatUpdate::class);
});

test('newer jobs wait for previously accepted events of the same conversation', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f, 'first'))->assertOk();
    receiveRuntimeWebhook($f, runtimeWebhook($f, 'second'))->assertOk();
    $second = UazapiInbox::latest('id')->first();
    app()->call([new ProcessInboundEvent($second->id), 'handle']);
    expect($second->fresh()->status)->toBe('pending')->and($f['thread']->fresh()->unread_count)->toBe(0);
    app()->call([new ProcessInboundEvent(UazapiInbox::oldest('id')->first()->id), 'handle']);
    app()->call([new ProcessInboundEvent($second->id), 'handle']);
    expect($f['thread']->fresh()->unread_count)->toBe(2);
});

test('older events do not replace a recent preview and media never stores a text preview', function () {
    $f = runtimeChat();
    $projection = app(ChatProjection::class);
    $projection->sync($f['connection'], [], ['chatid' => $f['thread']->external_chat_id, 'id' => 'new', 'messageType' => 'text', 'text' => str_repeat('á', 250), 'messageTimestamp' => now()->timestamp]);
    $projection->sync($f['connection'], [], ['chatid' => $f['thread']->external_chat_id, 'id' => 'old', 'messageType' => 'text', 'text' => 'old', 'messageTimestamp' => now()->subMinute()->timestamp]);
    expect($f['thread']->fresh()->last_message_id)->toBe('new')->and(mb_strlen($f['thread']->fresh()->last_message_preview))->toBe(200);
    $projection->sync($f['connection'], [], ['chatid' => $f['thread']->external_chat_id, 'id' => 'image', 'messageType' => 'image', 'text' => 'private caption', 'messageTimestamp' => now()->addMinute()->timestamp]);
    expect($f['thread']->fresh()->last_message_type)->toBe('image')->and($f['thread']->fresh()->last_message_preview)->toBeNull();
});

test('connection events update known state without provider calls', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, ['EventType' => 'connection', 'event_id' => 'disconnect', 'instance' => ['status' => 'disconnected']])->assertOk();
    app()->call([new ProcessInboundEvent(UazapiInbox::sole()->id), 'handle']);
    expect($f['connection']->fresh()->status)->toBe('disconnected');
    Http::assertNothingSent();
});

test('identical connection snapshots can represent a later reconnection', function () {
    $f = runtimeChat();
    foreach (['connected', 'disconnected', 'connected'] as $state) {
        receiveRuntimeWebhook($f, ['EventType' => 'connection', 'instance' => ['status' => $state]])->assertOk();
        app()->call([new ProcessInboundEvent(UazapiInbox::latest('id')->first()->id), 'handle']);
        expect($f['connection']->fresh()->status)->toBe($state);
    }
    expect(UazapiInbox::count())->toBe(3);
    Http::assertNothingSent();
});

test('receipt deduplication treats affected message IDs as a set', function () {
    $f = runtimeChat();
    foreach ([['a', 'b'], ['b', 'a', 'a']] as $ids) {
        receiveRuntimeWebhook($f, ['EventType' => 'messages_update', 'state' => 'Read', 'event' => ['Chat' => $f['thread']->external_chat_id, 'MessageIDs' => $ids, 'Type' => 'Read']])->assertOk();
    }
    expect(UazapiInbox::count())->toBe(1);
});

test('malformed webhook structures are rejected before durable acceptance', function () {
    $f = runtimeChat();
    foreach ([['EventType' => 'messages', 'message' => 'invalid'], ['EventType' => 'messages', 'message' => ['id' => ['invalid']]], ['EventType' => 'messages', 'message' => ['id' => 'without-chat']], ['EventType' => 'messages_update', 'event' => ['MessageIDs' => 'invalid']], ['EventType' => 'chats', 'chat' => ['wa_chatid' => ['invalid']]], ['EventType' => 'connection']] as $payload) {
        receiveRuntimeWebhook($f, $payload)->assertStatus(422);
    }
    expect(UazapiInbox::count())->toBe(0);
});

test('delivery and read notifications for the same message are distinct events', function () {
    $f = runtimeChat();
    foreach (['Delivered', 'Read'] as $state) {
        receiveRuntimeWebhook($f, ['EventType' => 'messages_update', 'state' => $state, 'event' => ['Chat' => $f['thread']->external_chat_id, 'MessageIDs' => ['a', 'b'], 'Type' => $state]])->assertOk();
    }
    expect(UazapiInbox::count())->toBe(2);
});

test('oversize and unauthorized webhooks are rejected before storing content', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f, extra: ['message' => ['text' => str_repeat('x', 262145)]]))->assertStatus(413);
    receiveRuntimeWebhook($f, runtimeWebhook($f, extra: ['token' => 'wrong']))->assertNotFound();
    expect(UazapiInbox::count())->toBe(0);
});

test('opening history queues one read and clears its temporary payload on completion', function () {
    $f = runtimeChat();
    Http::fake(['*/message/find' => Http::response(['messages' => [['id' => 'history-message', 'chatid' => $f['thread']->external_chat_id, 'text' => 'history content', 'messageType' => 'text']]])]);
    $response = $this->actingAs($f['user'])->getJson(route('workspaces.conversations.messages', [$f['workspace'], $f['thread']]))->assertStatus(202);
    $operation = ChatOperation::findOrFail($response->json('data.operation_id'));
    Http::assertNothingSent();
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('completed')->and($operation->fresh()->payload)->toBeNull();
    Http::assertSentCount(1);
    expect(DB::table('chat_operations')->first()->payload)->toBeNull();
});

test('queued sends revalidate ownership and never send after transfer', function () {
    $f = runtimeChat();
    $operation = app(ChatOperations::class)->create($f['thread'], $f['user']->id, 'send', ['text' => 'do not send', 'track_id' => (string) Str::uuid()]);
    $f['thread']->update(['assigned_to_user_id' => null]);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    Http::assertNothingSent();
    expect($operation->fresh()->status)->toBe('failed');
});

test('first realtime send opens, assigns and queues each message type', function (string $kind) {
    $f = runtimeChat();
    $f['thread']->update(['status' => 'completed', 'assigned_to_user_id' => null, 'human_opened_at' => null, 'finished_at' => now()]);
    $payload = match ($kind) {
        'text' => ['text' => 'Primeira resposta', 'track_id' => (string) Str::uuid()],
        'image' => ['file' => UploadedFile::fake()->image('foto.jpg'), 'track_id' => (string) Str::uuid()],
        'voice' => ['file' => UploadedFile::fake()->create('voz.webm', 10, 'audio/webm'), 'media_type' => 'voice', 'track_id' => (string) Str::uuid()],
    };

    $this->actingAs($f['user'])->postJson(route('workspaces.conversations.send', [$f['workspace'], $f['thread']]), $payload)
        ->assertStatus(202)->assertJsonPath('data.status', 'pending');

    expect($f['thread']->fresh()->status)->toBe('in_progress')
        ->and($f['thread']->fresh()->assigned_to_user_id)->toBe($f['user']->id)
        ->and(ChatOperation::sole()->kind)->toBe($kind === 'text' ? 'send' : 'send_file')
        ->and(DB::table('automation_pauses')->where('thread_id', $f['thread']->id)->where('reason', 'human')->whereNull('released_at')->exists())->toBeTrue();
    Http::assertNothingSent();
})->with(['text', 'image', 'voice']);

test('realtime send cannot silently take a conversation from another user', function () {
    $f = runtimeChat();
    $other = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['workspace']->id, 'user_id' => $other->id, 'role' => 'agent', 'can_view_unassigned' => true]);

    $this->actingAs($other)->postJson(route('workspaces.conversations.send', [$f['workspace'], $f['thread']]), ['text' => 'não tomar'])->assertConflict();
    expect(ChatOperation::count())->toBe(0);
    Http::assertNothingSent();
});

test('uncertain send is never replayed by operation recovery', function () {
    $f = runtimeChat();
    Http::fake(fn () => throw new ConnectionException('timeout with sensitive body'));
    $operation = app(ChatOperations::class)->create($f['thread'], $f['user']->id, 'send', ['text' => 'sensitive text', 'track_id' => (string) Str::uuid()]);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('confirmation_pending');
    app(ChatMaintenance::class)->recover();
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    // A fake which throws is not recorded as a response; the terminal state proves no replay.
    expect($operation->fresh()->status)->toBe('confirmation_pending');
});

test('temporary content expires without retaining plaintext or file references', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f))->assertOk();
    UazapiInbox::query()->update(['expires_at' => now()->subSecond()]);
    $operation = app(ChatOperations::class)->create($f['thread'], $f['user']->id, 'send', ['text' => 'temporary']);
    $operation->update(['expires_at' => now()->subSecond(), 'status' => 'failed']);
    app(ChatMaintenance::class)->prune();
    expect(UazapiInbox::sole()->payload)->toBeNull()->and(UazapiInbox::sole()->status)->toBe('expired')->and($operation->fresh()->payload)->toBeNull();
});

test('rate gate shares spacing and concurrent slots and releases capacity on errors', function () {
    $gate = app(UazapiGate::class);
    $bucket = 'connection:test-'.random_int(1, 999999);
    $first = $gate->acquire($bucket);
    expect(fn () => $gate->acquire($bucket))->toThrow(UazapiDeferredException::class);
    Cache::forget('chat-provider:'.$bucket.':pace');
    $second = $gate->acquire($bucket);
    Cache::forget('chat-provider:'.$bucket.':pace');
    expect(fn () => $gate->acquire($bucket))->toThrow(UazapiDeferredException::class);
    $first();
    $second();
    $release = $gate->acquire($bucket);
    $release();
});

test('429 defers until Retry-After without disconnecting the WhatsApp instance', function () {
    $f = runtimeChat();
    Http::fake(['*/message/find' => Http::response(['error' => 'rate limit exceeded'], 429, ['Retry-After' => '7'])]);
    try {
        app(UazapiClient::class)->messages($f['connection'], $f['thread']->external_chat_id);
        $this->fail('Expected deferral.');
    } catch (UazapiDeferredException $error) {
        expect($error->delay)->toBe(7);
    }
    expect($f['connection']->fresh()->status)->toBe('connected');
});

test('Retry-After accepts an HTTP date without retrying earlier than requested', function () {
    $f = runtimeChat();
    $this->freezeTime();
    Http::fake(['*/message/find' => Http::response([], 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()])]);
    try {
        app(UazapiClient::class)->messages($f['connection'], $f['thread']->external_chat_id);
        $this->fail('Expected deferral.');
    } catch (UazapiDeferredException $error) {
        expect($error->delay)->toBe(90);
    }
    Http::assertSentCount(1);
});

test('an agent without thread access cannot queue history or see list previews', function () {
    $f = runtimeChat();
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['workspace']->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => false]);
    $this->actingAs($agent)->getJson(route('workspaces.conversations.index', $f['workspace']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('workspaces.conversations.messages', [$f['workspace'], $f['thread']]))->assertNotFound();
    expect(ChatOperation::count())->toBe(0);
});

test('list query count stays bounded as the number of conversations grows', function () {
    $f = runtimeChat();
    $this->actingAs($f['user']);
    $url = route('workspaces.conversations.index', $f['workspace']);
    DB::enableQueryLog();
    $this->getJson($url)->assertOk();
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    Thread::withoutEvents(fn () => Thread::factory()->count(20)->create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id]));
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson($url)->assertOk()->assertJsonCount(21, 'data');
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($small + 4);
    DB::disableQueryLog();
});

test('private realtime authentication accepts only the current user channel', function () {
    $f = runtimeChat();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    require base_path('routes/channels.php');
    $this->actingAs($f['user'])->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$f['user']->id])->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.($f['user']->id + 1000)])->assertForbidden();
});

test('message broadcasting recalculates current thread permissions for each recipient', function () {
    $f = runtimeChat();
    $agent = User::factory()->create();
    $membership = WorkspaceMembership::factory()->create(['workspace_id' => $f['workspace']->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => false]);
    app(ChatPublisher::class)->send($f['workspace']->id, 'message.received', ['text' => 'authorized-only'], $f['thread']->id);
    Event::assertDispatched(ChatUpdate::class, fn ($event) => (string) $event->broadcastOn()[0] === 'private-chat.user.'.$f['user']->id && $event->broadcastWith()['type'] === 'message.received');
    Event::assertNotDispatched(ChatUpdate::class, fn ($event) => (string) $event->broadcastOn()[0] === 'private-chat.user.'.$agent->id && $event->broadcastWith()['type'] === 'message.received');
    $membership->update(['can_view_unassigned' => true]);
    $membership->delete();
    app(ChatPublisher::class)->send($f['workspace']->id, 'message.received', ['text' => 'after-removal'], $f['thread']->id);
    Event::assertNotDispatched(ChatUpdate::class, fn ($event) => (string) $event->broadcastOn()[0] === 'private-chat.user.'.$agent->id && data_get($event->broadcastWith(), 'data.text') === 'after-removal');
});

test('accepted inbox survives a dispatch outage and can be recovered without calling UAZAPI', function () {
    $f = runtimeChat();
    $inbox = UazapiInbox::create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'event_type' => 'messages', 'chat_id' => $f['thread']->external_chat_id, 'dedupe_hash' => hash('sha256', 'recovery'), 'payload' => runtimeWebhook($f)['message'], 'status' => 'pending', 'expires_at' => now()->addDay()]);
    app(ChatMaintenance::class)->recover();
    Queue::assertPushed(ProcessInboundEvent::class, fn ($job) => $job->inboxId === $inbox->id);
    Http::assertNothingSent();
});

test('scheduled recovery dispatches inbound events with realtime disabled', function () {
    config(['chat.enabled' => false]);
    $f = runtimeChat();
    $inbox = UazapiInbox::create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'event_type' => 'messages', 'chat_id' => $f['thread']->external_chat_id, 'dedupe_hash' => hash('sha256', 'legacy-recovery'), 'payload' => runtimeWebhook($f)['message'], 'status' => 'pending', 'expires_at' => now()->addDay()]);
    $this->artisan('chat:recover')->assertExitCode(0);
    Queue::assertPushed(ProcessInboundEvent::class, fn ($job) => $job->inboxId === $inbox->id);
});

test('a successful human send uses its persisted tracking ID and updates the preview without a webhook', function () {
    $f = runtimeChat();
    Http::fake(['*/send/text' => Http::response(['id' => 'sent-text', 'status' => 'sent'])]);
    $operation = app(ChatOperations::class)->create($f['thread'], $f['user']->id, 'send', ['text' => 'human preview']);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('completed')->and($f['thread']->fresh()->last_message_preview)->toBe('human preview');
    Http::assertSent(fn ($request) => $request['track_id'] === $operation->track_id);
});

test('a provider quota rejection defers an accepted send without turning it into an uncertain send', function () {
    $f = runtimeChat();
    Http::fake(['*/send/text' => Http::response(['error' => 'rate limit exceeded'], 429, ['Retry-After' => '3'])]);
    $operation = app(ChatOperations::class)->create($f['thread'], $f['user']->id, 'send', ['text' => 'deferred']);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('pending')->and($operation->fresh()->payload['text'])->toBe('deferred');
});

function runtimeAi(array $f): array
{
    $data = ['ai_prompt' => 'Answer the contact.', 'ai_model' => 'gpt-6.1-sol', 'ai_inactivity_seconds' => 3600, 'ai_expiry_action' => 'end', 'ai_max_messages' => null, 'ai_media' => [], 'ai_tags' => [], 'ai_fields' => []];
    $graph = ['schema_version' => 2, 'nodes' => [['id' => 'ai', 'type' => 'ai', 'data' => $data]], 'edges' => []];
    $flow = Flow::create(['workspace_id' => $f['workspace']->id, 'name' => 'Runtime AI', 'revision' => 1, 'graph' => $graph]);
    $version = $flow->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $execution = FlowExecution::create(['workspace_id' => $f['workspace']->id, 'flow_id' => $flow->id, 'flow_version_id' => $version->id, 'contact_id' => $f['thread']->contact_id, 'thread_id' => $f['thread']->id, 'uazapi_connection_id' => $f['connection']->id, 'status' => 'waiting_ai', 'trigger_source' => 'test', 'current_node_id' => 'ai', 'context' => [], 'started_at' => now()]);
    $session = AiSession::create(['workspace_id' => $f['workspace']->id, 'flow_execution_id' => $execution->id, 'flow_version_id' => $version->id, 'contact_id' => $f['thread']->contact_id, 'thread_id' => $f['thread']->id, 'uazapi_connection_id' => $f['connection']->id, 'node_id' => 'ai', 'model' => 'gpt-6.1-sol', 'remote_conversation_id' => 'remote-test', 'expires_at' => now()->addHour()]);
    WorkspaceOpenAiIntegration::create(['workspace_id' => $f['workspace']->id, 'api_key' => 'fake-ai-key']);
    $inbox = UazapiInbox::create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'event_type' => 'messages', 'chat_id' => $f['thread']->external_chat_id, 'dedupe_hash' => hash('sha256', 'ai-'.$session->id), 'payload' => ['message' => runtimeWebhook($f)['message']], 'status' => 'processed', 'consumers' => 1, 'expires_at' => now()->addDay()]);

    return compact('flow', 'execution', 'session', 'inbox');
}

test('AI consumes the accepted text by reference without fetching it from UAZAPI again', function () {
    $f = runtimeChat();
    $a = runtimeAi($f);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'AI answer']]]]])]);
    $job = new ProcessAiTurn($a['session']->id, 'message-1', $a['inbox']->id);
    (new WorkspaceCapacity('ai'))->handle($job, fn ($job) => app()->call([$job, 'handle']));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && data_get($request->data(), 'input.0.content.0.text') === 'private message body');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'provider.test'));
    expect($a['inbox']->fresh()->payload)->toBeNull()->and(ChatOperation::where('kind', 'transport')->count())->toBe(1);
});

test('a media message for AI is scheduled on media without downloading in inbound or AI', function () {
    $f = runtimeChat();
    $a = runtimeAi($f);
    $payload = $a['inbox']->payload;
    $payload['message']['messageType'] = 'image';
    $a['inbox']->update(['payload' => $payload]);
    app()->call([new ProcessAiTurn($a['session']->id, 'message-1', $a['inbox']->id), 'handle']);
    Queue::assertPushedOn('media', PrepareAiInput::class);
    Http::assertNothingSent();
    expect($a['inbox']->fresh()->payload)->not->toBeNull();
});

test('background transports revalidate interrupted executions and never send stale content', function () {
    $f = runtimeChat();
    $a = runtimeAi($f);
    app(TransportContext::class)->executionId = $a['execution']->id;
    app(ChatOperations::class)->transport($f['connection'], 'text', ['to' => $f['thread']->external_chat_id, 'text' => 'stale', 'track_id' => 'stale-transport']);
    $operation = ChatOperation::where('kind', 'transport')->sole();
    $a['execution']->update(['status' => 'interrupted']);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('cancelled')->and($operation->fresh()->payload)->toBeNull();
    Http::assertNothingSent();
});

test('workspace capacity releases competing work while a different workspace can progress', function () {
    $make = fn ($workspace) => new class($workspace)
    {
        public ?int $released = null;

        public function __construct(private int $workspace) {}

        public function workspaceId(): int
        {
            return $this->workspace;
        }

        public function release(int $seconds): void
        {
            $this->released = $seconds;
        }
    };
    $first = $make(9001);
    $same = $make(9001);
    $other = $make(9002);
    $otherProgressed = false;
    $middleware = new WorkspaceCapacity('ai');
    $middleware->handle($first, function () use ($middleware, $same, $other, &$otherProgressed) {
        $middleware->handle($same, fn () => throw new RuntimeException('Same workspace exceeded its capacity.'));
        $middleware->handle($other, function () use (&$otherProgressed) {
            $otherProgressed = true;
        });
    });
    expect($same->released)->toBeGreaterThan(0)->and($otherProgressed)->toBeTrue();
});

test('background requests yield to human demand but get a reserved fairness opportunity', function () {
    $bucket = 'connection:priority-'.random_int(1, 999999);
    $prefix = 'chat-provider:'.$bucket;
    Cache::put($prefix.':human', true, 2);
    $gate = app(UazapiGate::class);
    expect(fn () => $gate->acquire($bucket))->toThrow(UazapiDeferredException::class);
    Cache::put($prefix.':pace', ['next' => 0, 'count' => 4], 60);
    $release = $gate->acquire($bucket);
    expect($release)->toBeCallable();
    $release();
});

test('literal text beginning with data is retained while embedded media credentials are stripped', function () {
    $f = runtimeChat();
    receiveRuntimeWebhook($f, runtimeWebhook($f, extra: ['message' => ['text' => 'data: a literal reference', 'content' => json_encode(['text' => 'literal', 'mediaKey' => 'sensitive-key', 'jpegThumbnail' => 'base64-thumbnail'])]]))->assertOk();
    $payload = UazapiInbox::sole()->payload;
    expect($payload['message']['text'])->toBe('data: a literal reference')->and($payload['message']['content'])->toBe(['text' => 'literal']);
});

test('connecting resumes the confirmed stage after quota deferral without recreating an instance', function () {
    $f = runtimeChat();
    config(['services.uazapi.admin_token' => 'fake-admin']);
    $f['connection']->update(['instance_token' => null, 'instance_id' => null]);
    Http::fake(['*/instance/create' => Http::response(['instance' => ['id' => 'new-instance', 'token' => 'new-token']]), '*/webhook' => Http::response(['ok' => true]), '*/instance/connect' => Http::response(['qrcode' => 'test-qr'])]);
    $response = $this->actingAs($f['user'])->postJson(route('workspaces.uazapi.connect', $f['workspace']))->assertStatus(202);
    $operation = ChatOperation::findOrFail($response->json('data.operation_id'));
    app()->call([new ProcessConnectionOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('pending')->and($operation->fresh()->payload['step'])->toBe('connect')->and($f['connection']->fresh()->instance_token)->toBe('new-token');
    Cache::forget('chat-provider:connection:'.$f['connection']->id.':pace');
    app()->call([new ProcessConnectionOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('completed');
    Http::assertSentCount(3);
});

test('local cursor pagination includes selected details outside the current page without UAZAPI calls', function () {
    $f = runtimeChat();
    $rows = [];
    for ($i = 0; $i < 202; $i++) {
        $rows[] = ['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'contact_id' => $f['thread']->contact_id, 'channel' => 'whatsapp', 'external_chat_id' => 'pagination-'.$i, 'status' => 'bot', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('threads')->insert($rows);
    $url = route('workspaces.conversations.index', $f['workspace']);
    $page = $this->actingAs($f['user'])->getJson($url.'?thread_id='.$f['thread']->id)->assertOk()->assertJsonCount(200, 'data')->assertJsonPath('selected_thread.id', $f['thread']->id);
    $cursor = $page->json('next_cursor');
    expect($cursor)->not->toBeNull();
    $this->getJson($url.'?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('next_cursor', null);
    $tag = $f['workspace']->tags()->create(['name' => 'Depois da primeira página', 'color' => '#16a34a']);
    $taggedContact = Contact::factory()->create(['workspace_id' => $f['workspace']->id]);
    $taggedContact->tags()->attach($tag->id, ['workspace_id' => $f['workspace']->id]);
    $taggedThread = Thread::factory()->create(['workspace_id' => $f['workspace']->id, 'uazapi_connection_id' => $f['connection']->id, 'contact_id' => $taggedContact->id, 'last_message_at' => now()->subDay()]);
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$tag->id]]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $taggedThread->id);
    Http::assertNothingSent();
});

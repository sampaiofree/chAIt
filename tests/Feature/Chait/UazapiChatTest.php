<?php

use App\Jobs\Chat\ProcessInboundEvent;
use App\Models\Contact;
use App\Models\Thread;
use App\Models\ThreadAssignment;
use App\Models\UazapiConnection;
use App\Models\UazapiInbox;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function uazapiWebhookUrl(UazapiConnection $connection): string
{
    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));

    return route('webhooks.uazapi', ['connection' => $connection->id, 'secret' => $secret]);
}

test('webhook inspection prints settings without exposing its URL or token', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $connection = UazapiConnection::factory()->create();
    $expected = uazapiWebhookUrl($connection);
    Http::fake(['*/webhook' => Http::response([[
        'enabled' => true,
        'url' => $expected,
        'events' => ['messages', 'connection'],
        'excludeMessages' => [],
        'addUrlEvents' => false,
        'addUrlTypesMessages' => false,
    ]])]);

    expect(Artisan::call('chat:inspect-webhook', ['connectionId' => $connection->id]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"url_matches_expected":true')
        ->toContain('"messages"')
        ->not->toContain($expected)
        ->not->toContain((string) $connection->instance_token);
});

test('workspace admin creates a protected UAZAPI instance and receives its QR code', function () {
    config([
        'app.url' => 'https://chait.test',
        'services.uazapi.base_url' => 'https://api.uazapi.test',
        'services.uazapi.admin_token' => 'admin-test-token',
    ]);
    Http::fake([
        '*/instance/create' => Http::response(['instance' => ['id' => 'instance-123', 'token' => 'instance-secret']], 200),
        '*/webhook' => Http::response(['success' => true], 200),
        '*/instance/connect' => Http::response(['qrcode' => 'qr-test-payload'], 200),
    ]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;

    $response = $this->actingAs($user)
        ->postJson(route('workspaces.uazapi.connect', $workspace))
        ->assertOk()
        ->assertJsonPath('data.status', 'connecting')
        ->assertJsonPath('data.qr_code', 'qr-test-payload');

    $connection = UazapiConnection::query()->sole();
    expect($connection->status)->toBe('connecting')
        ->and($connection->instance_token)->toBe('instance-secret')
        ->and(DB::table('uazapi_connections')->value('instance_token'))->not->toBe('instance-secret')
        ->and($response->getContent())->not->toContain('instance-secret');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/instance/create'
        && $request->hasHeader('admintoken', 'admin-test-token'));
});

test('WhatsApp settings are administrator-only', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent']);
    $agent->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($admin)->get('/settings/whatsapp')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/whatsapp')->where('workspace.id', $workspace->id));

    $this->actingAs($agent)->get('/settings/whatsapp')->assertForbidden();
});

test('workspace status refresh uses the instance token and updates the connected number', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake([
        '*/instance/status' => Http::response([
            'instance' => ['status' => 'connected'],
            'status' => ['connected' => true, 'jid' => ['user' => '5511998765432']],
        ], 200),
    ]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'connecting',
        'phone' => null,
        'connected_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson(route('workspaces.uazapi.status', $workspace))
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.phone', '5511998765432');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/instance/status'
        && $request->hasHeader('token', $connection->instance_token));
});

test('provider outage is reported separately from the last known connection state', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/instance/status' => Http::response(['error' => 'offline'], 503)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    UazapiConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'connected',
        'profile_name' => 'Atendimento',
    ]);

    $this->actingAs($user)->getJson(route('workspaces.uazapi.status', $workspace))
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.provider_available', false)
        ->assertJsonPath('data.profile_name', 'Atendimento')
        ->assertJsonStructure(['data' => ['last_checked_at']]);
});

test('agent status response omits WhatsApp profile details', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/instance/status' => Http::response([
        'instance' => ['status' => 'connected', 'profileName' => 'Privado', 'profilePicUrl' => 'https://cdn.test/instance.jpg'],
        'status' => ['connected' => true, 'jid' => ['user' => '5511999999999']],
    ], 200)]);

    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent']);

    $response = $this->actingAs($agent)->getJson(route('workspaces.uazapi.status', $workspace))->assertOk();
    expect($response->json('data'))->not->toHaveKey('profile_name')
        ->and($response->json('data'))->not->toHaveKey('profile_pic_url');
});

test('avatar requests are workspace-authorized and cached for fifteen minutes', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/chat/avatar' => Http::response(['url' => 'https://cdn.test/avatar.jpg'], 200)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
    ]);

    $url = route('workspaces.conversations.avatar', [$workspace, $thread]);
    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.url', 'https://cdn.test/avatar.jpg');
    $this->getJson($url)->assertOk()->assertJsonPath('data.url', 'https://cdn.test/avatar.jpg');
    expect(data_get($contact->fresh()->profile, 'imagePreview'))->toBe('https://cdn.test/avatar.jpg');
    Cache::flush();
    $this->getJson($url)->assertOk()->assertJsonPath('data.url', 'https://cdn.test/avatar.jpg');
    Http::assertSentCount(1);

    $other = User::factory()->create();
    $this->actingAs($other)->getJson($url)->assertForbidden();
});

test('avatar absence is cached and can be refreshed on request', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $avatarCalls = 0;
    Http::fake(function (ClientRequest $request) use (&$avatarCalls) {
        if (str_ends_with($request->url(), '/chat/avatar')) {
            $avatarCalls++;

            return Http::response(
                $avatarCalls === 1 ? ['url' => null] : ['url' => 'https://cdn.test/avatar.jpg'],
                200,
            );
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net']);
    $url = route('workspaces.conversations.avatar', [$workspace, $thread]);

    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.url', null);
    $this->getJson($url)->assertOk()->assertJsonPath('data.url', null);
    expect($avatarCalls)->toBe(1);

    $this->getJson($url.'?refresh=1')->assertOk()->assertJsonPath('data.url', 'https://cdn.test/avatar.jpg');
    expect($avatarCalls)->toBe(2)
        ->and(data_get($contact->fresh()->profile, 'imagePreview'))->toBe('https://cdn.test/avatar.jpg');
});

test('refresh replaces an expired persisted avatar URL', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/chat/avatar' => Http::response(['url' => 'https://cdn.test/new-avatar.jpg'])]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'profile' => ['imagePreview' => 'https://cdn.test/expired-avatar.jpg']]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net']);
    $url = route('workspaces.conversations.avatar', [$workspace, $thread]);

    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.url', 'https://cdn.test/expired-avatar.jpg');
    Http::assertNothingSent();
    $this->getJson($url.'?refresh=1')->assertOk()->assertJsonPath('data.url', 'https://cdn.test/new-avatar.jpg');
    expect(data_get($contact->fresh()->profile, 'imagePreview'))->toBe('https://cdn.test/new-avatar.jpg');
    Http::assertSentCount(1);
});

test('legacy contact notes stay in storage but are not exposed or changed by the chat profile', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/chat/find' => Http::response(['chats' => []], 200)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'connected',
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'notes' => 'Anotação antiga de contato',
    ]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'status' => 'in_progress',
        'assigned_to_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->putJson(route('workspaces.conversations.contact', [$workspace, $thread]), [
            'display_name' => 'Contato atualizado',
            'notes' => 'Tentativa de sobrescrever anotação antiga',
        ])
        ->assertOk()
        ->assertJsonMissingPath('data.notes')
        ->assertJsonPath('data.display_name', 'Contato atualizado');

    expect($contact->fresh()->notes)->toBe('Anotação antiga de contato');

    $this->getJson(route('workspaces.conversations.index', $workspace))
        ->assertOk()
        ->assertJsonMissingPath('data.0.contact.notes')
        ->assertJsonMissingPath('selected_thread.contact.notes');
});

test('chat status and assignment filters combine without excluding waiting attendance', function (string $assignment, string $status) {
    Http::fake(['*/chat/find' => Http::response(['chats' => []])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    $other = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $other->id, 'role' => 'agent']);
    $threads = collect();
    foreach (['bot', 'open', 'in_progress', 'completed'] as $threadStatus) {
        foreach ([null, $user->id, $other->id] as $assignee) {
            $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
            $threads->push(Thread::factory()->create([
                'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
                'contact_id' => $contact->id, 'status' => $threadStatus, 'assigned_to_user_id' => $assignee,
                'human_opened_at' => in_array($threadStatus, ['open', 'in_progress'], true) ? now() : null,
            ]));
        }
    }
    $expected = $threads->filter(fn (Thread $thread) => ($status === 'all' || in_array($thread->status, ['open', 'in_progress'], true))
        && ($assignment !== 'mine' || $thread->assigned_to_user_id === $user->id)
        && ($assignment !== 'unassigned' || $thread->assigned_to_user_id === null)
    )->pluck('id')->sort()->values()->all();

    $response = $this->actingAs($user)->getJson(route('workspaces.conversations.index', $workspace).'?'.http_build_query([
        'assignment' => $assignment, 'status' => $status,
    ]))->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe($expected);
})->with(['all', 'mine', 'unassigned'])->with(['all', 'open']);

test('local chat filters by workspace contact tags before limiting the list', function () {
    Http::fake(['*/chat/find' => Http::response(['chats' => []])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    $first = $workspace->tags()->create(['name' => 'Primeira', 'color' => '#16a34a']);
    $second = $workspace->tags()->create(['name' => 'Segunda', 'color' => '#0f766e']);
    $foreign = User::factory()->create()->fresh()->currentWorkspace->tags()->create(['name' => 'Privada', 'color' => '#16a34a']);
    $alpha = Contact::factory()->create(['workspace_id' => $workspace->id, 'display_name' => 'Alfa']);
    $alpha->tags()->attach($first->id, ['workspace_id' => $workspace->id]);
    $beta = Contact::factory()->create(['workspace_id' => $workspace->id, 'display_name' => 'Beta']);
    $beta->tags()->attach($second->id, ['workspace_id' => $workspace->id]);
    $alphaThread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $alpha->id, 'status' => 'open', 'assigned_to_user_id' => $user->id, 'last_message_at' => now()->subDay()]);
    $betaThread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $beta->id, 'status' => 'completed', 'assigned_to_user_id' => null]);
    $url = route('workspaces.conversations.index', $workspace);

    $both = $this->actingAs($user)->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id]]))->assertOk();
    expect(collect($both->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$alphaThread->id, $betaThread->id])->sort()->values()->all());
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id], 'assignment' => 'mine', 'status' => 'open', 'search' => 'Alfa']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alphaThread->id);
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$foreign->id]]))->assertUnprocessable();
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => false]);
    $this->actingAs($agent)->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id, $second->id]]))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($user);

    for ($i = 0; $i < 101; $i++) {
        Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => Contact::factory()->create(['workspace_id' => $workspace->id])->id, 'last_message_at' => now()]);
    }
    $this->getJson($url.'?'.http_build_query(['tag_ids' => [$first->id]]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alphaThread->id);
});

test('sending an image returns provider metadata with a correlation id without saving message content', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/send/media' => Http::response([
        'id' => 'provider-message-1', 'messageid' => 'message-1', 'messageType' => 'image', 'fromMe' => true,
        'fileURL' => 'https://cdn.test/sent-image.jpg', 'status' => 'Sent', 'text' => 'Legenda',
    ], 200)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net', 'status' => 'bot', 'assigned_to_user_id' => null]);
    $trackId = 'send-attempt-123';

    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'track_id' => $trackId,
        'caption' => 'Legenda',
        'file' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertOk()
        ->assertJsonPath('data.sent', true)
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.track_id', $trackId)
        ->assertJsonPath('data.message.is_image', true)
        ->assertJsonPath('data.message.media_url', 'https://cdn.test/sent-image.jpg');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/send/media'
        && $request['track_id'] === $trackId
        && $request['type'] === 'image');
    expect(DB::table('messages')->count())->toBe(0);
});

test('an image send timeout remains pending and is not reported as a failed resend', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $attempts = 0;
    Http::fake(['*/send/media' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('Timed out after request dispatch.');
    }]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net', 'status' => 'bot', 'assigned_to_user_id' => null]);

    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'track_id' => 'timeout-attempt-1',
        'file' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertStatus(202)
        ->assertJsonPath('data.sent', false)
        ->assertJsonPath('data.status', 'confirmation_pending')
        ->assertJsonPath('data.track_id', 'timeout-attempt-1');

    expect($attempts)->toBe(1)->and(DB::table('messages')->count())->toBe(0)
        ->and($thread->fresh()->assigned_to_user_id)->toBe($user->id)
        ->and($thread->fresh()->status)->toBe('in_progress');
});

test('history normalizes image metadata and searches the provider by send correlation id', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/message/find' => function (ClientRequest $request) {
        if ($request['track_id'] ?? null) {
            return Http::response(['messages' => [[
                'id' => 'provider-message-2', 'chatid' => '551155501234@s.whatsapp.net', 'track_id' => 'send-attempt-2', 'fromMe' => true, 'messageType' => 'image',
                'fileURL' => 'https://cdn.test/image.jpg', 'text' => 'Legenda', 'status' => 'Sent',
            ]]], 200);
        }

        return Http::response(['messages' => []], 200);
    }]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'unread_count' => 2,
        'unread_version' => 3,
    ]);

    $this->actingAs($user)->getJson(route('workspaces.conversations.messages', [$workspace, $thread]).'?track_id=send-attempt-2')
        ->assertOk()
        ->assertJsonPath('data.0.id', 'provider-message-2')
        ->assertJsonPath('data.0.track_id', 'send-attempt-2')
        ->assertJsonPath('data.0.is_image', true)
        ->assertJsonPath('data.0.caption', 'Legenda')
        ->assertJsonPath('data.0.media_url', 'https://cdn.test/image.jpg')
        ->assertJsonPath('read_version', 3);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.uazapi.test/message/find' && ($request['track_id'] ?? null) === 'send-attempt-2' && $request['chatid'] === $thread->external_chat_id);

});

test('history correlation rejects a provider result from another conversation', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/message/find' => fn (ClientRequest $request) => Http::response(['messages' => ($request['track_id'] ?? null) ? [[
        'id' => 'other-chat-message', 'chatid' => '551166601234@s.whatsapp.net', 'track_id' => 'send-attempt-2',
    ]] : []], 200)]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net']);

    $this->actingAs($user)->getJson(route('workspaces.conversations.messages', [$workspace, $thread]).'?track_id=send-attempt-2')
        ->assertOk()->assertJsonCount(0, 'data');
});

test('media URLs can be renewed only for messages in the authorized conversation', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake([
        '*/message/find' => Http::response(['messages' => [[
            'id' => 'authorized-image', 'chatid' => '551155501234@s.whatsapp.net', 'messageType' => 'image',
        ]]], 200),
        '*/message/download' => Http::response(['fileURL' => 'https://cdn.test/renewed.jpg'], 200),
    ]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net']);

    $this->actingAs($user)->getJson(route('workspaces.conversations.media', [$workspace, $thread, 'authorized-image']))
        ->assertOk()->assertJsonPath('data.url', 'https://cdn.test/renewed.jpg');

    Http::fake([
        '*/message/find' => Http::response(['messages' => [[
            'id' => 'other-chat-image', 'chatid' => '551166601234@s.whatsapp.net', 'messageType' => 'image',
        ]]], 200),
    ]);
    $this->getJson(route('workspaces.conversations.media', [$workspace, $thread, 'other-chat-image']))->assertNotFound();
});

test('UAZAPI webhooks are deduplicated and message bodies are not persisted', function () {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'activated_at' => now()->subMinute()]);
    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
    $connection->update(['webhook_secret_hash' => hash('sha256', $secret)]);
    $payload = [
        'EventType' => 'messages',
        'id' => 'event-123',
        'message' => [
            'id' => 'message-123',
            'chatid' => '551155501234@s.whatsapp.net',
            'fromMe' => false,
            'messageTimestamp' => now()->timestamp * 1000,
            'text' => 'conteúdo que não deve ser salvo',
        ],
        'chat' => [
            'wa_chatid' => '551155501234@s.whatsapp.net',
            'phone' => '551155501234',
            'name' => 'Contato de teste',
            'wa_unreadCount' => 1,
        ],
    ];

    $this->postJson(uazapiWebhookUrl($connection), $payload)->assertOk()->assertJsonPath('ok', true);
    $this->postJson(uazapiWebhookUrl($connection), $payload)->assertOk()->assertJsonPath('ok', true);
    $nextMessage = $payload;
    $nextMessage['id'] = 'event-124';
    $nextMessage['message']['id'] = 'message-124';
    unset($nextMessage['chat']['wa_unreadCount']);
    $this->postJson(uazapiWebhookUrl($connection), $nextMessage)->assertOk()->assertJsonPath('ok', true);
    foreach (UazapiInbox::orderBy('id')->get() as $inbox) {
        app()->call([new ProcessInboundEvent($inbox->id), 'handle']);
    }

    expect(UazapiInbox::count())->toBe(2)
        ->and(DB::table('contacts')->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(DB::table('threads')->where('uazapi_connection_id', $connection->id)->count())->toBe(1)
        ->and(Thread::query()->where('uazapi_connection_id', $connection->id)->value('unread_count'))->toBe(2)
        ->and(Thread::query()->where('uazapi_connection_id', $connection->id)->value('unread_version'))->toBe(2)
        ->and(DB::table('messages')->count())->toBe(0);
});

test('known conversation history is paged by its chat id without scanning chats', function () {
    config(['chat.enabled' => false, 'services.uazapi.base_url' => 'https://api.uazapi.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'external_chat_id' => '5527888888888@s.whatsapp.net']);
    Http::fake(function (ClientRequest $request) use ($thread) {
        expect($request->url())->toBe('https://api.uazapi.test/message/find')
            ->and($request['chatid'])->toBe($thread->external_chat_id);
        $offset = (int) ($request['offset'] ?? 0);
        $count = $offset === 0 ? 100 : 1;

        return Http::response(['messages' => array_map(fn ($index) => ['id' => 'message-'.$offset.'-'.$index, 'chatid' => $thread->external_chat_id, 'messageType' => 'text', 'text' => 'Olá'], range(1, $count))]);
    });
    $url = route('workspaces.conversations.messages', [$workspace, $thread]);
    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('next_offset', 100);
    $this->getJson($url.'?offset=100')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_offset', null);
    Http::assertSentCount(2);
});

test('marking a conversation read uses the provider and clears shared unread state', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/chat/read' => Http::response(['response' => 'Chat read status updated successfully'], 200)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'unread_count' => 2,
        'unread_version' => 4,
    ]);

    $this->actingAs($user)
        ->postJson(route('workspaces.conversations.read', [$workspace, $thread]), ['read_version' => 4])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.unread_version', 5);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/chat/read'
        && $request->hasHeader('token', $connection->instance_token)
        && $request['number'] === '551155501234@s.whatsapp.net'
        && $request['read'] === true);
    expect($thread->fresh()->unread_count)->toBe(0);
});

test('stale read versions and provider failures preserve unread state', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/chat/read' => Http::response(['error' => 'offline'], 503)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'unread_count' => 1,
        'unread_version' => 2,
    ]);

    $this->actingAs($user)
        ->postJson(route('workspaces.conversations.read', [$workspace, $thread]), ['read_version' => 1])
        ->assertStatus(409)
        ->assertJsonPath('data.unread_count', 1);
    Http::assertNothingSent();

    $this->postJson(route('workspaces.conversations.read', [$workspace, $thread]), ['read_version' => 2])
        ->assertStatus(503)
        ->assertJsonPath('data.unread_count', 1);
    expect($thread->fresh()->unread_count)->toBe(1);
});

test('a message received during provider read is not cleared locally', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'unread_count' => 1,
        'unread_version' => 2,
    ]);
    Http::fake(function (ClientRequest $request) use ($thread) {
        DB::table('threads')->where('id', $thread->id)->update([
            'unread_count' => 2,
            'unread_version' => 3,
        ]);

        return Http::response(['response' => 'Chat read status updated successfully'], 200);
    });

    $this->actingAs($user)
        ->postJson(route('workspaces.conversations.read', [$workspace, $thread]), ['read_version' => 2])
        ->assertStatus(409)
        ->assertJsonPath('data.unread_count', 2)
        ->assertJsonPath('data.unread_version', 3);
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/chat/read'
        && $request['read'] === false);
    expect($thread->fresh()->unread_count)->toBe(2);
});

test('mark read enforces workspace and assignment visibility', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create();
    $otherConnection = UazapiConnection::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $otherContact = Contact::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $otherThread = Thread::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'uazapi_connection_id' => $otherConnection->id,
        'contact_id' => $otherContact->id,
        'unread_count' => 1,
    ]);

    $this->actingAs($user)
        ->postJson(route('workspaces.conversations.read', [$otherWorkspace, $otherThread]), ['read_version' => 0])
        ->assertForbidden();

    $assignedUser = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $assignedUser->id,
        'role' => 'agent',
        'can_view_unassigned' => false,
    ]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'assigned_to_user_id' => $user->id,
        'unread_count' => 1,
    ]);

    $this->actingAs($assignedUser)
        ->postJson(route('workspaces.conversations.read', [$workspace, $thread]), ['read_version' => 0])
        ->assertNotFound();
});

test('first reply opens and assigns without storing message content', function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/send/text' => Http::response(['id' => 'sent-text-123', 'success' => true], 200)]);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'assigned_to_user_id' => null,
        'status' => 'bot',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspaces.conversations.send', [$workspace, $thread]), ['text' => 'resposta de teste'])
        ->assertOk()
        ->assertJsonPath('data.sent', true);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.uazapi.test/send/text'
        && $request->hasHeader('token', $connection->instance_token)
        && $request['text'] === 'resposta de teste');
    expect(DB::table('messages')->count())->toBe(0)
        ->and($thread->fresh()->assigned_to_user_id)->toBe($user->id)
        ->and($thread->fresh()->status)->toBe('in_progress');
});

test('first reply claims unassigned conversations in every human state', function (string $role, string $status) {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/send/text' => Http::response(['id' => 'should-not-send', 'success' => true], 200)]);

    $owner = User::factory()->create();
    $workspace = $owner->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'status' => $status,
        'assigned_to_user_id' => null,
    ]);
    $sender = $owner;
    if ($role === 'agent') {
        $sender = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $sender->id,
            'role' => 'agent',
            'can_view_unassigned' => true,
        ]);
    }

    $this->actingAs($sender)
        ->postJson(route('workspaces.conversations.send', [$workspace, $thread]), ['text' => 'sem assumir'])
        ->assertOk()->assertJsonPath('data.sent', true);

    Http::assertSentCount(1);
    expect($thread->fresh()->assigned_to_user_id)->toBe($sender->id)
        ->and($thread->fresh()->status)->toBe('in_progress')
        ->and($thread->fresh()->finished_at)->toBeNull();
})->with(['admin', 'agent'])->with(['bot', 'open', 'completed']);

test('visible attendants explicitly claim occupied conversations and only administrators assign others', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $agent->id,
        'role' => 'agent',
    ]);
    $otherAgent = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $otherAgent->id,
        'role' => 'agent',
        'can_view_unassigned' => true,
    ]);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'status' => 'in_progress',
        'assigned_to_user_id' => $agent->id,
    ]);
    ThreadAssignment::create([
        'workspace_id' => $workspace->id,
        'thread_id' => $thread->id,
        'assigned_to_user_id' => $agent->id,
        'assigned_by_user_id' => $admin->id,
        'state' => 'active',
    ]);

    $this->actingAs($otherAgent)
        ->postJson(route('workspaces.conversations.claim', [$workspace, $thread]))
        ->assertOk();
    expect($thread->fresh()->assigned_to_user_id)->toBe($otherAgent->id);

    $this->postJson(route('workspaces.conversations.assign', [$workspace, $thread]), ['user_id' => $agent->id])->assertForbidden();
    expect($thread->fresh()->assigned_to_user_id)->toBe($otherAgent->id);

    $outsider = User::factory()->create();
    $this->actingAs($admin)
        ->postJson(route('workspaces.conversations.assign', [$workspace, $thread]), ['user_id' => $outsider->id])
        ->assertNotFound();
    $this
        ->postJson(route('workspaces.conversations.assign', [$workspace, $thread]), ['user_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_to.id', $agent->id);

    expect($thread->fresh()->assigned_to_user_id)->toBe($agent->id)
        ->and($thread->assignments()->where('assigned_to_user_id', $otherAgent->id)->value('state'))->toBe('released')
        ->and($thread->assignments()->where('assigned_to_user_id', $agent->id)->where('state', 'active')->exists())->toBeTrue()
        ->and(json_decode(DB::table('audit_logs')->where('action', 'conversation.claimed')->latest('id')->value('payload'), true)['assigned_to_user_id'])->toBe($agent->id);
});

test('sending cannot take an occupied conversation, including for an administrator', function (string $role) {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::fake(['*/send/text' => Http::response(['id' => 'must-not-send'], 200)]);
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $owner = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'agent']);
    $actor = $admin;
    if ($role === 'agent') {
        $actor = User::factory()->create();
        WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $actor->id, 'role' => 'agent', 'can_view_unassigned' => true]);
    }
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'status' => 'in_progress', 'assigned_to_user_id' => $owner->id]);

    $this->actingAs($actor)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), ['text' => 'não tomar'])->assertConflict();
    Http::assertNothingSent();
    expect($thread->fresh()->assigned_to_user_id)->toBe($owner->id);
})->with(['admin', 'agent']);

test('opening without a reply leaves the conversation unassigned', function () {
    $admin = User::factory()->create();
    $workspace = $admin->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'status' => 'bot', 'assigned_to_user_id' => null]);

    $this->actingAs($admin)->postJson(route('workspaces.conversations.open', [$workspace, $thread]))->assertOk();
    expect($thread->fresh()->status)->toBe('open')->and($thread->fresh()->assigned_to_user_id)->toBeNull();
    $this->postJson(route('workspaces.conversations.finish', [$workspace, $thread]))->assertOk();
    expect($thread->fresh()->status)->toBe('completed');
});

test('conversation serialization reports automation state and execution separately', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_chat_id' => '551155501234@s.whatsapp.net',
        'status' => 'bot',
        'assigned_to_user_id' => null,
    ]);
    $now = now();
    $flowId = DB::table('flows')->insertGetId([
        'workspace_id' => $workspace->id,
        'name' => 'Teste',
        'graph' => json_encode([]),
        'revision' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $versionId = DB::table('flow_versions')->insertGetId([
        'workspace_id' => $workspace->id,
        'flow_id' => $flowId,
        'version' => 1,
        'graph' => json_encode([]),
        'published_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('flow_executions')->insert([
        'workspace_id' => $workspace->id,
        'flow_id' => $flowId,
        'flow_version_id' => $versionId,
        'contact_id' => $contact->id,
        'thread_id' => $thread->id,
        'uazapi_connection_id' => $connection->id,
        'status' => 'waiting_reply',
        'trigger_source' => 'keyword',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Http::fake(['*/chat/find' => Http::response(['chats' => []])]);

    $indexUrl = route('workspaces.conversations.index', $workspace).'?thread_id='.$thread->id;
    $this->actingAs($user)->getJson($indexUrl)
        ->assertOk()
        ->assertJsonPath('selected_thread.automation.state', 'allowed')
        ->assertJsonPath('selected_thread.automation.execution_status', 'waiting_reply')
        ->assertJsonPath('selected_thread.automation.can_resume', false);

    DB::table('flow_executions')->update(['status' => 'running']);
    $this->getJson($indexUrl)
        ->assertOk()
        ->assertJsonPath('selected_thread.automation.state', 'allowed')
        ->assertJsonPath('selected_thread.automation.execution_status', 'running');
    DB::table('flow_executions')->update(['status' => 'waiting_reply']);

    DB::table('automation_pauses')->insert([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'thread_id' => $thread->id,
        'reason' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('flow_executions')->update(['status' => 'interrupted', 'finished_at' => $now]);

    $this->getJson($indexUrl)
        ->assertOk()
        ->assertJsonPath('selected_thread.automation.state', 'paused')
        ->assertJsonPath('selected_thread.automation.pause_reasons', ['manual'])
        ->assertJsonPath('selected_thread.automation.execution_status', null)
        ->assertJsonPath('selected_thread.automation.can_resume', true);

    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))
        ->assertOk()
        ->assertJsonPath('data.automation.state', 'allowed')
        ->assertJsonPath('data.automation.can_resume', false);
    expect(DB::table('automation_pauses')->where('contact_id', $contact->id)->whereNull('released_at')->exists())->toBeFalse();

    $this->postJson(route('workspaces.conversations.open', [$workspace, $thread]))
        ->assertOk()
        ->assertJsonPath('data.automation.pause_reasons', ['human'])
        ->assertJsonPath('data.automation.can_resume', false);
    $this->postJson(route('workspaces.conversations.finish', [$workspace, $thread]))
        ->assertOk()
        ->assertJsonPath('data.automation.state', 'allowed');

    DB::table('automation_pauses')->insert([
        'workspace_id' => $workspace->id,
        'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'thread_id' => $thread->id,
        'reason' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $thread->update(['status' => 'in_progress', 'human_opened_at' => $now]);
    $this->getJson($indexUrl)
        ->assertOk()
        ->assertJsonPath('selected_thread.automation.pause_reasons', ['manual', 'human'])
        ->assertJsonPath('selected_thread.automation.can_resume', false);
    $this->postJson(route('workspaces.conversations.automations.resume', [$workspace, $thread]))
        ->assertStatus(409);
    expect(DB::table('automation_pauses')->where('contact_id', $contact->id)->whereNull('released_at')->exists())->toBeTrue();
});

test('a user cannot access conversations from a workspace they do not belong to', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($user)
        ->getJson(route('workspaces.conversations.index', $workspace))
        ->assertForbidden();
});

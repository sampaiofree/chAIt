<?php

use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\ContactCustomFieldValue;
use App\Models\ExternalWebhook;
use App\Models\ExternalWebhookReceipt;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contacts\InternationalPhone;
use App\Services\ExternalWebhooks\PayloadFields;
use App\Services\ExternalWebhooks\WebhookFailure;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function externalFixture(): array
{
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $webhook = ExternalWebhook::create(['workspace_id' => $workspace->id, 'name' => 'Compras', 'endpoint_key' => bin2hex(random_bytes(32)), 'status' => 'test', 'mappings' => [], 'tag_rules' => [], 'payload_fields' => []]);

    return [$user, $workspace, $webhook, "/workspaces/{$workspace->id}/webhooks/{$webhook->id}"];
}

function externalMapping(string $target, array $path, string $kind = 'native'): array
{
    return ['source_path' => $path, 'target_kind' => $kind, 'target' => $kind === 'custom' ? (int) $target : $target];
}

function externalActivate(ExternalWebhook $webhook, array $mappings = [], array $rules = []): void
{
    $webhook->update(['status' => 'active', 'mappings' => [externalMapping('phone', ['phone']), ...$mappings], 'tag_rules' => $rules,
        'payload_fields' => [['path' => ['phone'], 'label' => 'phone', 'type' => 'string']]]);
}

function externalReceiveUrl(ExternalWebhook $webhook): string
{
    return route('webhooks.external.receive', $webhook->endpoint_key);
}

test('administrators create independent webhooks in test mode with opaque stable URLs', function () {
    [$user, $workspace, $webhook, $endpoint] = externalFixture();
    $this->actingAs($user)->postJson("/workspaces/{$workspace->id}/webhooks", ['name' => 'Formulário'])->assertCreated()->assertJsonPath('data.status', 'test');
    $second = ExternalWebhook::where('name', 'Formulário')->sole();
    expect(strlen($second->endpoint_key))->toBe(64)->and($second->endpoint_key)->not->toBe($webhook->endpoint_key);
    $this->putJson($endpoint, ['name' => 'Renomeado', 'status' => 'test', 'mappings' => [], 'tag_rules' => []])->assertOk();
    expect($webhook->fresh()->endpoint_key)->toBe($webhook->endpoint_key);
    $this->get("/workspaces/{$workspace->id}/webhooks")->assertInertia(fn (Assert $page) => $page->component('webhooks/index')->has('webhooks', 2));
    expect(DB::table('audit_logs')->where('action', 'like', 'external_webhook.%')->get()->toJson())->not->toContain($webhook->endpoint_key);
});

test('every administration endpoint requires admin permissions', function (string $role) {
    [$user, $workspace, $webhook, $endpoint] = externalFixture();
    $user->membershipFor($workspace)->update(['role' => $role, 'can_manage_flows' => true]);
    $this->actingAs($user->fresh());
    foreach ([['GET', "/workspaces/{$workspace->id}/webhooks"], ['POST', "/workspaces/{$workspace->id}/webhooks"], ['GET', $endpoint], ['PUT', $endpoint], ['GET', "$endpoint/snapshot"], ['POST', "$endpoint/sample"], ['DELETE', "$endpoint/sample"], ['POST', "$endpoint/capture"], ['POST', "$endpoint/simulate"]] as [$method, $url]) {
        $this->json($method, $url, [])->assertForbidden();
    }
})->with(['manager', 'agent']);

test('administration cannot read or change a webhook through another workspace', function () {
    [$user, $workspace, $webhook, $endpoint] = externalFixture();
    $other = Workspace::factory()->create();
    $this->actingAs($user)->getJson("/workspaces/{$other->id}/webhooks/{$webhook->id}/snapshot")->assertForbidden();
    $foreign = ExternalWebhook::create(['workspace_id' => $other->id, 'name' => 'Privado', 'endpoint_key' => bin2hex(random_bytes(32)), 'mappings' => [], 'tag_rules' => [], 'payload_fields' => []]);
    $this->getJson("/workspaces/{$workspace->id}/webhooks/{$foreign->id}/snapshot")->assertNotFound();
    $this->getJson("$endpoint/snapshot")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

test('test requests only capture an encrypted example and its typed paths', function () {
    [, $workspace, $webhook] = externalFixture();
    $payload = ['phone' => '5527999999999', 'buyers' => [['a.b' => 'segredo', 'zero' => 0, 'flag' => false]], 'null' => null];
    $this->postJson(externalReceiveUrl($webhook), $payload)->assertOk()->assertJsonPath('status', 'received');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(DB::table('external_webhooks')->where('id', $webhook->id)->value('sample_payload'))->not->toContain('segredo')
        ->and($webhook->fresh()->sample_payload)->toContain('segredo');
    $fields = $webhook->fresh()->payload_fields;
    expect($fields[1]['path'])->toBe(['buyers', 0, 'a.b'])->and($fields[1]['label'])->toBe('buyers[0]["a.b"]');
    expect(DB::table('external_webhook_receipts')->get()->toJson())->not->toContain('segredo')->not->toContain('5527999999999');
});

test('paste capture polling and deleting a sample preserve the mapping and draft', function () {
    [$user, , $webhook, $endpoint] = externalFixture();
    $this->actingAs($user)->postJson("$endpoint/sample", ['payload' => '{"buyer":{"phone":"5527999999999"}}'])->assertOk();
    $mapping = [externalMapping('phone', ['buyer', 'phone'])];
    $this->putJson($endpoint, ['name' => 'Compras', 'status' => 'test', 'mappings' => $mapping, 'tag_rules' => []])->assertOk();
    $this->postJson("$endpoint/capture")->assertOk();
    expect($webhook->fresh()->capture_until->isFuture())->toBeTrue();
    $this->deleteJson("$endpoint/sample")->assertOk()->assertJsonPath('data.sample_payload', null);
    expect($webhook->fresh()->payload_fields)->not->toBeEmpty()->and($webhook->fresh()->mappings)->toBe($mapping);
});

test('activation requires a phone mapping valid sources compatible types and distinct destinations', function () {
    [$user, , $webhook, $endpoint] = externalFixture();
    $this->actingAs($user)->postJson("$endpoint/sample", ['payload' => '{"phone":"5527999999999","flag":true}'])->assertOk();
    $base = ['name' => 'Compras', 'status' => 'active', 'tag_rules' => []];
    $this->putJson($endpoint, [...$base, 'mappings' => []])->assertUnprocessable()->assertJsonValidationErrors('mappings');
    $this->putJson($endpoint, [...$base, 'mappings' => [externalMapping('phone', ['missing'])]])->assertUnprocessable()->assertJsonValidationErrors('mappings.0.source_path');
    $this->putJson($endpoint, [...$base, 'mappings' => [externalMapping('phone', ['flag'])]])->assertUnprocessable()->assertJsonValidationErrors('mappings.0.source_path');
    $this->putJson($endpoint, [...$base, 'mappings' => [externalMapping('phone', ['phone']), externalMapping('phone', ['phone'])]])->assertUnprocessable()->assertJsonValidationErrors('mappings.1.target');
    $this->putJson($endpoint, [...$base, 'mappings' => [externalMapping('phone', ['phone'])]])->assertOk();
    $this->putJson($endpoint, [...$base, 'mappings' => []])->assertUnprocessable();
    expect($webhook->fresh()->status)->toBe('active');
});

test('configuration rejects foreign fields tags and contradictory actions within a rule', function () {
    [$user, $workspace, , $endpoint] = externalFixture();
    $other = Workspace::factory()->create();
    $field = $other->contactCustomFields()->create(['name' => 'Privado', 'type' => 'text']);
    $tag = $other->tags()->create(['name' => 'Privada']);
    $localTag = $workspace->tags()->create(['name' => 'Cliente']);
    $this->actingAs($user);
    $base = ['name' => 'Compras', 'status' => 'test'];
    $this->putJson($endpoint, [...$base, 'mappings' => [externalMapping((string) $field->id, ['value'], 'custom')], 'tag_rules' => []])->assertUnprocessable();
    $this->putJson($endpoint, [...$base, 'mappings' => [], 'tag_rules' => [['condition' => null, 'add' => [$tag->id], 'remove' => []]]])->assertUnprocessable();
    $this->putJson($endpoint, [...$base, 'mappings' => [], 'tag_rules' => [['condition' => null, 'add' => [$localTag->id], 'remove' => [$localTag->id]]]])->assertUnprocessable();
});

test('upsert preserves absent null empty and invalid optional values but accepts zero and false', function () {
    [, $workspace, $webhook] = externalFixture();
    $field = $workspace->contactCustomFields()->create(['name' => 'Número', 'type' => 'text']);
    $flag = $workspace->contactCustomFields()->create(['name' => 'Flag', 'type' => 'text']);
    externalActivate($webhook, [externalMapping('display_name', ['name']), externalMapping('email', ['email']), externalMapping('cpf', ['cpf']), externalMapping((string) $field->id, ['zero'], 'custom'), externalMapping((string) $flag->id, ['flag'], 'custom')]);
    $url = externalReceiveUrl($webhook);
    $this->postJson($url, ['phone' => '+55 (27) 99999-9999', 'name' => 'Maria', 'email' => 'maria@example.com', 'cpf' => '123', 'zero' => 0, 'flag' => false])->assertOk()->assertJsonPath('status', 'processed');
    $contact = Contact::where('workspace_id', $workspace->id)->sole();
    expect($contact->phone)->toBe('5527999999999')->and($contact->customFieldValues()->pluck('value', 'field_id')->all())->toBe([$field->id => '0', $flag->id => 'false']);
    $this->postJson($url, ['phone' => '5527999999999', 'name' => '  ', 'email' => 'invalid', 'cpf' => null, 'zero' => []])->assertOk()->assertJsonPath('status', 'ignored')->assertJsonCount(2, 'warnings');
    expect($contact->fresh()->display_name)->toBe('Maria')->and($contact->fresh()->email)->toBe('maria@example.com')->and($contact->fresh()->cpf)->toBe('123')->and(Contact::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('phone failure prevents all CRM operations', function (mixed $phone) {
    [, $workspace, $webhook] = externalFixture();
    $tag = $workspace->tags()->create(['name' => 'Cliente']);
    externalActivate($webhook, [], [['condition' => null, 'add' => [$tag->id], 'remove' => []]]);
    $this->postJson(externalReceiveUrl($webhook), ['phone' => $phone])->assertUnprocessable()->assertJsonPath('code', 'invalid_phone');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(0)->and(DB::table('contact_tag')->count())->toBe(0)->and($webhook->receipts()->sole()->error_code)->toBe('invalid_phone');
})->with([null, '', '   ', '123', '5511999999999@s.whatsapp.net', '5511999999999 ext 2', false, [['number' => '5511999999999']]]);

test('international phone normalization accepts DDI forms and rejects local numbers', function () {
    $phones = new InternationalPhone;
    foreach (['+55 (27) 99999-9999', '005527999999999', '5527999999999', 5527999999999] as $value) {
        expect($phones->normalize($value))->toBe('5527999999999');
    }
    expect($phones->normalize('11999999999'))->toBeNull()->and($phones->normalize('+1 650 253 0000'))->toBe('16502530000');
});

test('ordered tag conditions compare JSON types and the current payload with last action winning', function () {
    [, $workspace, $webhook] = externalFixture();
    $tag = $workspace->tags()->create(['name' => 'Cliente']);
    $falseTag = $workspace->tags()->create(['name' => 'Outra']);
    externalActivate($webhook, [], [
        ['condition' => null, 'add' => [$tag->id], 'remove' => []],
        ['condition' => ['source_path' => ['status'], 'value' => 'approved'], 'add' => [], 'remove' => [$tag->id]],
        ['condition' => ['source_path' => ['number'], 'value' => '0'], 'add' => [$falseTag->id], 'remove' => []],
    ]);
    $url = externalReceiveUrl($webhook);
    $this->postJson($url, ['phone' => '5527999999999', 'status' => 'approved', 'number' => 0])->assertOk();
    $contact = Contact::where('workspace_id', $workspace->id)->sole();
    expect($contact->tags()->count())->toBe(0);
    $this->postJson($url, ['phone' => '5527999999999', 'status' => 'Approved'])->assertOk();
    expect($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    $this->postJson($url, ['phone' => '5527999999999'])->assertOk()->assertJsonPath('status', 'ignored');
    expect($contact->tags()->count())->toBe(1);
});

test('multiple webhooks share a contact while workspaces remain isolated and repeats run again', function () {
    [, $workspace, $webhook] = externalFixture();
    [, $other, $foreign] = externalFixture();
    $second = $webhook->replicate(['endpoint_key']);
    $second->endpoint_key = bin2hex(random_bytes(32));
    $second->save();
    foreach ([$webhook, $second, $foreign] as $target) {
        externalActivate($target, [externalMapping('display_name', ['name'])]);
    }
    foreach ([[$webhook, 'Primeiro'], [$second, 'Segundo'], [$foreign, 'Isolado'], [$second, 'Repetido']] as [$target, $name]) {
        $this->postJson(externalReceiveUrl($target), ['phone' => '5527999999999', 'name' => $name, 'workspace_id' => $other->id])->assertOk();
    }
    expect(Contact::where('workspace_id', $workspace->id)->sole()->display_name)->toBe('Repetido')->and(Contact::where('workspace_id', $other->id)->sole()->display_name)->toBe('Isolado')->and($second->receipts()->count())->toBe(2);
    foreach (['threads', 'flow_executions', 'sequence_enrollments', 'messages'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

test('simulation uses the unsaved configuration even when active without changing CRM or activation', function () {
    [$user, $workspace, $webhook, $endpoint] = externalFixture();
    $tag = $workspace->tags()->create(['name' => 'Cliente']);
    externalActivate($webhook);
    $this->actingAs($user)->postJson("$endpoint/simulate", ['name' => 'Não salvo', 'status' => 'test', 'mappings' => [externalMapping('phone', ['buyer', 0, 'phone']), externalMapping('display_name', ['name'])], 'tag_rules' => [['condition' => null, 'add' => [$tag->id], 'remove' => []]], 'payload' => '{"buyer":[{"phone":"5527999999999"}],"name":"Maria"}'])->assertOk()->assertJsonPath('data.status', 'simulated');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(0)->and(DB::table('contact_tag')->count())->toBe(0)->and($webhook->fresh()->name)->toBe('Compras')->and($webhook->fresh()->status)->toBe('active')->and($webhook->receipts()->sole()->status)->toBe('simulated');
});

test('active payloads are only retained during an explicitly armed capture', function () {
    [$user, , $webhook, $endpoint] = externalFixture();
    externalActivate($webhook);
    $url = externalReceiveUrl($webhook);
    $this->postJson($url, ['phone' => '5527999999999'])->assertOk();
    expect($webhook->fresh()->sample_payload)->toBeNull();
    $this->actingAs($user)->postJson("$endpoint/capture")->assertOk();
    $this->postJson($url, ['phone' => '5527999999999', 'version' => 1])->assertOk();
    $captured = $webhook->fresh()->sample_payload;
    expect($captured)->toContain('version')->and($webhook->fresh()->capture_until)->toBeNull();
    $this->postJson($url, ['phone' => '5527999999999', 'version' => 2])->assertOk();
    expect($webhook->fresh()->sample_payload)->toBe($captured);
    $webhook->update(['capture_until' => now()->subMinute()]);
    $this->postJson($url, ['phone' => '5527999999999', 'version' => 3])->assertOk();
    expect($webhook->fresh()->sample_payload)->toBe($captured);
});

test('HTTP validation returns explicit statuses without mutating contacts', function () {
    [, , $webhook] = externalFixture();
    $url = externalReceiveUrl($webhook);
    $this->post($url, ['phone' => '5527999999999'])->assertStatus(415);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{oops')->assertStatus(400);
    foreach (['[]', '1', 'null', '{}'] as $raw) {
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(422);
    }
    config(['external-webhooks.max_body_bytes' => 10]);
    $this->postJson($url, ['phone' => '5527999999999'])->assertStatus(413);
    $this->postJson('/webhooks/'.str_repeat('a', 64), ['phone' => '5527999999999'])->assertNotFound();
    expect(Contact::count())->toBe(0);
});

test('payload limits and dotted property names are handled without confusing paths', function () {
    $fields = new PayloadFields;
    $payload = $fields->parse('{"a.b":"literal","a":{"b":"nested"},"list":[{"0":"property"}]}');
    expect($fields->resolve($payload, ['a.b']))->toBe('literal')->and($fields->resolve($payload, ['a', 'b']))->toBe('nested')->and($fields->resolve($payload, ['list', 0, '0']))->toBe('property')->and($fields->resolve($payload, ['list', '0', '0']))->toBeNull();
    config(['external-webhooks.max_fields' => 1]);
    expect(fn () => $fields->parse('{"a":1,"b":2}'))->toThrow(WebhookFailure::class);
    config(['external-webhooks.max_depth' => 2]);
    expect(fn () => $fields->parse('{"a":{"b":{"c":1}}}'))->toThrow(WebhookFailure::class);
});

test('rate limits use the resolved webhook and include retry information', function () {
    [, , $webhook] = externalFixture();
    config(['external-webhooks.requests_per_minute' => 1]);
    $this->postJson(externalReceiveUrl($webhook), ['phone' => '5527999999999'])->assertOk();
    $this->postJson(externalReceiveUrl($webhook), ['phone' => '5527999999999'])->assertStatus(429)->assertHeader('Retry-After');
});

test('CRM writes roll back on failure and the receipt records only a safe error', function () {
    [, $workspace, $webhook] = externalFixture();
    $field = $workspace->contactCustomFields()->create(['name' => 'Falha', 'type' => 'text']);
    externalActivate($webhook, [externalMapping((string) $field->id, ['value'], 'custom')]);
    ContactCustomFieldValue::saving(fn () => throw new RuntimeException('PII must not leak'));
    try {
        $response = $this->postJson(externalReceiveUrl($webhook), ['phone' => '5527999999999', 'value' => 'segredo'])->assertStatus(500)->assertJsonPath('code', 'internal_error');
        expect($response->getContent())->not->toContain('PII')->and(Contact::count())->toBe(0)->and(ContactCustomFieldValue::count())->toBe(0)->and($webhook->receipts()->sole()->status)->toBe('error');
    } finally {
        ContactCustomFieldValue::flushEventListeners();
    }
});

test('the UAZAPI reuses a contact created by an external webhook and preserves its data', function () {
    [, $workspace, $webhook] = externalFixture();
    externalActivate($webhook, [externalMapping('display_name', ['name'])]);
    $this->postJson(externalReceiveUrl($webhook), ['phone' => '5527999999999', 'name' => 'CRM'])->assertOk();
    $contact = Contact::sole();
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
    $connection->update(['webhook_secret_hash' => hash('sha256', $secret)]);
    $this->postJson(route('webhooks.uazapi', [$connection, $secret]), ['EventType' => 'messages', 'id' => 'shared-contact', 'message' => ['id' => 'message-shared', 'chatid' => '5527999999999@s.whatsapp.net', 'fromMe' => true], 'chat' => ['wa_chatid' => '5527999999999@s.whatsapp.net', 'name' => 'WhatsApp']])->assertOk();
    expect(Contact::count())->toBe(1)->and(ContactChannel::sole()->contact_id)->toBe($contact->id)->and(Thread::sole()->contact_id)->toBe($contact->id)->and($contact->fresh()->display_name)->toBe('CRM');
    $this->postJson(route('webhooks.uazapi', [$connection, $secret]), ['EventType' => 'messages', 'id' => 'lid-event', 'message' => ['chatid' => '123456789012345@lid', 'fromMe' => true]])->assertOk();
    expect(Contact::count())->toBe(1);
});

test('receipt pruning retains recent metadata and the encrypted example', function () {
    [, $workspace, $webhook] = externalFixture();
    $webhook->update(['sample_payload' => '{"example":"retained"}']);
    foreach ([31, 1] as $days) {
        ExternalWebhookReceipt::create(['workspace_id' => $workspace->id, 'external_webhook_id' => $webhook->id, 'status' => 'received', 'created_at' => now()->subDays($days)]);
    }
    $this->artisan('webhooks:prune-receipts')->assertSuccessful();
    expect($webhook->receipts()->count())->toBe(1)->and($webhook->fresh()->sample_payload)->toContain('retained');
});

test('an armed active capture retains a rejected event for inspection', function () {
    [$user, , $webhook, $endpoint] = externalFixture();
    externalActivate($webhook);
    $this->actingAs($user)->postJson("$endpoint/capture")->assertOk();
    $this->postJson(externalReceiveUrl($webhook), ['phone' => 'invalid', 'debug' => 'inspect'])->assertUnprocessable();
    expect($webhook->fresh()->sample_payload)->toContain('inspect')->and($webhook->fresh()->capture_until)->toBeNull()->and(Contact::count())->toBe(0);
});

test('malformed conditions and destinations produce validation errors', function () {
    [$user, , , $endpoint] = externalFixture();
    $this->actingAs($user)->putJson($endpoint, ['name' => 'Webhook', 'status' => 'test', 'mappings' => [externalMapping('phone', ['phone'])], 'tag_rules' => [['condition' => [], 'add' => [], 'remove' => []]]])->assertUnprocessable();
    $this->putJson($endpoint, ['name' => 'Webhook', 'status' => 'test', 'mappings' => [['source_path' => ['phone'], 'target_kind' => 'native', 'target' => ['phone']]], 'tag_rules' => []])->assertUnprocessable();
});

test('the migration canonicalizes existing phones before adding the unique index', function () {
    [, $workspace] = externalFixture();
    $migration = require database_path('migrations/2026_10_14_000003_create_external_webhooks.php');
    $migration->down();
    $id = DB::table('contacts')->insertGetId(['workspace_id' => $workspace->id, 'phone' => '+55 (27) 99999-9999', 'display_name' => 'Keep me', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    expect(DB::table('contacts')->where('id', $id)->value('phone'))->toBe('5527999999999')->and(DB::table('contacts')->where('id', $id)->value('display_name'))->toBe('Keep me');
    expect(DB::table('contacts')->insertOrIgnore(['workspace_id' => $workspace->id, 'phone' => '5527999999999']))->toBe(0);
});

test('the migration detects canonical collisions without modifying or merging contacts', function () {
    [, $workspace] = externalFixture();
    $migration = require database_path('migrations/2026_10_14_000003_create_external_webhooks.php');
    $migration->down();
    foreach (['+55 (27) 99999-9999', '5527999999999'] as $phone) {
        DB::table('contacts')->insert(['workspace_id' => $workspace->id, 'phone' => $phone]);
    }
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Contact phone collision');
    expect(DB::table('contacts')->where('workspace_id', $workspace->id)->pluck('phone')->all())->toBe(['+55 (27) 99999-9999', '5527999999999']);
});

test('configuration preserves whitespace in JSON keys and exact equality values', function () {
    [$user, $workspace, $webhook, $endpoint] = externalFixture();
    $tag = $workspace->tags()->create(['name' => 'Exact match']);
    $this->actingAs($user)->postJson("$endpoint/sample", ['payload' => '{" phone ":"5527999999999"," status ":" approved ","empty":""}'])->assertOk();
    $rules = [
        ['condition' => ['source_path' => [' status '], 'value' => ' approved '], 'add' => [$tag->id], 'remove' => []],
        ['condition' => ['source_path' => ['empty'], 'value' => ''], 'add' => [$tag->id], 'remove' => []],
    ];
    $this->putJson($endpoint, ['name' => '  Exact  ', 'status' => 'active', 'mappings' => [externalMapping('phone', [' phone '])], 'tag_rules' => $rules])->assertOk();
    expect($webhook->fresh()->name)->toBe('Exact')->and($webhook->fresh()->tag_rules)->toBe($rules);
    $this->postJson(externalReceiveUrl($webhook), [' phone ' => '5527999999999', ' status ' => ' approved '])->assertOk();
    expect(Contact::where('workspace_id', $workspace->id)->sole()->tags()->count())->toBe(1);
});

test('settings webhooks lists only the current workspace for administrators', function (string $role) {
    [$user, $workspace, $webhook] = externalFixture();
    $user->membershipFor($workspace)->update(['role' => $role]);
    [, , $foreign] = externalFixture();
    $foreign->update(['name' => 'Outro workspace']);
    $this->actingAs($user->fresh())->get('/settings/webhooks')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('webhooks/index')->where('workspace.id', $workspace->id)->has('webhooks', 1)->where('webhooks.0.id', $webhook->id));
})->with(['owner', 'admin']);

test('settings webhooks requires authentication and administrative access', function () {
    $this->get('/settings/webhooks')->assertRedirect(route('login'));
    [$user, $workspace] = externalFixture();
    foreach (['manager', 'agent'] as $role) {
        $user->membershipFor($workspace)->update(['role' => $role]);
        $this->actingAs($user->fresh())->get('/settings/webhooks')->assertForbidden();
    }
});

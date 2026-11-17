<?php

use App\Models\Contact;
use App\Models\UazapiConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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

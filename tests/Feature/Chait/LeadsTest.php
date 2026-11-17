<?php

use App\Models\Contact;
use App\Models\UazapiConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('admin creates edits and lists leads without WhatsApp and prevents duplicates', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $this->actingAs($user)->post("/workspaces/{$workspace->id}/leads", ['phone' => '+55 (11) 98765-4321', 'display_name' => 'Maria'])->assertSessionHasNoErrors()->assertRedirect();
    $lead = Contact::where('workspace_id', $workspace->id)->sole();
    expect($lead->phone)->toBe('5511987654321');
    $this->get("/workspaces/{$workspace->id}/leads")->assertOk();
    $this->get("/workspaces/{$workspace->id}/leads/{$lead->id}")->assertOk();
    $this->patch("/workspaces/{$workspace->id}/leads/{$lead->id}", ['observations' => 'Cliente interessado'])->assertRedirect();
    expect($lead->fresh()->observations)->toBe('Cliente interessado');
    $this->post("/workspaces/{$workspace->id}/leads", ['phone' => '5511987654321'])->assertSessionHasErrors('phone');
    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(1);
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

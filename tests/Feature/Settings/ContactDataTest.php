<?php

use App\Models\Contact;
use App\Models\FlowVersion;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Inertia\Testing\AssertableInertia as Assert;

function contactDataEndpoints(Workspace $workspace, int $field, int $tag): array
{
    return [
        ['GET', '/settings/contact-data', []],
        ['POST', route('workspaces.contact-fields.store', $workspace), ['name' => 'Novo campo']],
        ['PATCH', route('workspaces.contact-fields.update', [$workspace, $field]), ['name' => 'Campo editado']],
        ['POST', route('workspaces.tags.store', $workspace), ['name' => 'Nova tag', 'color' => '#123abc']],
        ['PATCH', route('workspaces.tags.update', [$workspace, $tag]), ['name' => 'Tag editada', 'color' => '#abcdef']],
    ];
}

test('contact data requires authentication for the page and every write', function () {
    $workspace = Workspace::factory()->create();

    foreach (contactDataEndpoints($workspace, 1, 1) as [$method, $url, $payload]) {
        $this->call($method, $url, $payload)->assertRedirect(route('login'));
    }
});

test('owners and admins manage contact definitions', function (string $role) {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $user->membershipFor($workspace)->update(['role' => $role]);
    $field = $workspace->contactCustomFields()->create(['name' => 'Inicial', 'type' => 'text']);
    $tag = $workspace->tags()->create(['name' => 'Inicial', 'color' => '#16a34a']);

    $this->actingAs($user->fresh());
    foreach (contactDataEndpoints($workspace, $field->id, $tag->id) as [$method, $url, $payload]) {
        $response = $this->from('/settings/contact-data')->call($method, $url, $payload);
        $method === 'GET' ? $response->assertOk() : $response->assertRedirect('/settings/contact-data')->assertSessionHasNoErrors();
    }

    expect($field->fresh()->name)->toBe('Campo editado')
        ->and($tag->fresh()->name)->toBe('Tag editada')
        ->and($tag->fresh()->color)->toBe('#abcdef')
        ->and($workspace->tags()->where('name', 'Nova tag')->sole()->created_by_user_id)->toBe($user->id);
})->with(['owner', 'admin']);

test('operational roles cannot manage contact definitions even with flow permission', function (string $role, bool $canManageFlows) {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $user->membershipFor($workspace)->update(['role' => $role, 'can_manage_flows' => $canManageFlows]);
    $field = $workspace->contactCustomFields()->create(['name' => 'Original', 'type' => 'text']);
    $tag = $workspace->tags()->create(['name' => 'Original', 'color' => '#16a34a']);

    $this->actingAs($user->fresh());
    foreach (contactDataEndpoints($workspace, $field->id, $tag->id) as [$method, $url, $payload]) {
        $this->call($method, $url, $payload)->assertForbidden();
    }

    expect($workspace->contactCustomFields()->count())->toBe(1)
        ->and($workspace->tags()->count())->toBe(1)
        ->and($field->fresh()->name)->toBe('Original')
        ->and($tag->fresh()->name)->toBe('Original');
})->with([['manager', false], ['manager', true], ['agent', false], ['agent', true]]);

test('contact data lists only current workspace definitions in name order', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $other = Workspace::factory()->create();
    foreach ([$workspace, $other] as $target) {
        foreach (['Zebra', 'Alpha'] as $name) {
            $target->contactCustomFields()->create(['name' => $name, 'type' => 'text']);
            $target->tags()->create(['name' => $name, 'color' => '#16a34a']);
        }
    }

    $this->actingAs($user)->get('/settings/contact-data')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/contact-data')
            ->where('workspace.id', $workspace->id)->where('isAdmin', true)
            ->has('fields', 2)->where('fields.0.name', 'Alpha')->where('fields.1.name', 'Zebra')
            ->has('tags', 2)->where('tags.0.name', 'Alpha')->where('tags.1.name', 'Zebra'));
});

test('writes cannot use inaccessible workspaces or definitions from another workspace', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $other = Workspace::factory()->create();
    $field = $other->contactCustomFields()->create(['name' => 'Privado', 'type' => 'text']);
    $tag = $other->tags()->create(['name' => 'Privado', 'color' => '#16a34a']);
    $this->actingAs($user);

    foreach (array_slice(contactDataEndpoints($other, $field->id, $tag->id), 1) as [$method, $url, $payload]) {
        $this->call($method, $url, $payload)->assertForbidden();
    }

    // Even an admin of both workspaces cannot mix their resource IDs.
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $user->id, 'role' => 'admin']);
    $this->actingAs($user->fresh());
    $this->patch(route('workspaces.contact-fields.update', [$workspace, $field]), ['name' => 'Inválido'])->assertNotFound();
    $this->patch(route('workspaces.tags.update', [$workspace, $tag]), ['name' => 'Inválido', 'color' => '#abcdef'])->assertNotFound();
    $this->patch(route('workspaces.contact-fields.update', [$workspace, 999999]), ['name' => 'Inválido'])->assertNotFound();
    $this->patch(route('workspaces.tags.update', [$workspace, 999999]), ['name' => 'Inválido', 'color' => '#abcdef'])->assertNotFound();

    expect($field->fresh()->name)->toBe('Privado')->and($tag->fresh()->name)->toBe('Privado');
});

test('names are trimmed and unique per category and workspace with unchanged edits allowed', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $other = Workspace::factory()->create();
    $other->contactCustomFields()->create(['name' => 'Interesse', 'type' => 'text']);
    $other->tags()->create(['name' => 'Interesse', 'color' => '#16a34a']);
    $this->actingAs($user)->from('/settings/contact-data');

    $this->post(route('workspaces.contact-fields.store', $workspace), ['name' => '  Interesse  ', 'type' => 'number', 'workspace_id' => $other->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('workspaces.tags.store', $workspace), ['name' => '  Interesse  ', 'color' => '#ABCDEF', 'created_by_user_id' => 999, 'workspace_id' => $other->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $field = $workspace->contactCustomFields()->sole();
    $tag = $workspace->tags()->sole();
    expect($field->name)->toBe('Interesse')->and($field->type)->toBe('text')
        ->and($tag->name)->toBe('Interesse')->and($tag->created_by_user_id)->toBe($user->id);

    $this->patch(route('workspaces.contact-fields.update', [$workspace, $field]), ['name' => ' Interesse '])->assertSessionHasNoErrors();
    $this->patch(route('workspaces.tags.update', [$workspace, $tag]), ['name' => ' Interesse ', 'color' => '#123456'])->assertSessionHasNoErrors();
    $this->post(route('workspaces.contact-fields.store', $workspace), ['name' => ' Interesse '])->assertSessionHasErrors('name');
    $this->post(route('workspaces.tags.store', $workspace), ['name' => ' Interesse ', 'color' => '#123456'])->assertSessionHasErrors('name');

    $secondField = $workspace->contactCustomFields()->create(['name' => 'Outro', 'type' => 'text']);
    $secondTag = $workspace->tags()->create(['name' => 'Outro', 'color' => '#16a34a']);
    $this->patch(route('workspaces.contact-fields.update', [$workspace, $secondField]), ['name' => 'Interesse'])->assertSessionHasErrors('name');
    $this->patch(route('workspaces.tags.update', [$workspace, $secondTag]), ['name' => 'Interesse', 'color' => '#123456'])->assertSessionHasErrors('name');
    expect($secondField->fresh()->name)->toBe('Outro')->and($secondTag->fresh()->name)->toBe('Outro');
});

test('invalid names are rejected for both categories on create and edit', function (mixed $name) {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $field = $workspace->contactCustomFields()->create(['name' => 'Original', 'type' => 'text']);
    $tag = $workspace->tags()->create(['name' => 'Original', 'color' => '#16a34a']);
    $this->actingAs($user)->from('/settings/contact-data');

    foreach (array_slice(contactDataEndpoints($workspace, $field->id, $tag->id), 1) as [$method, $url, $payload]) {
        $this->call($method, $url, [...$payload, 'name' => $name])->assertRedirect()->assertSessionHasErrors('name');
    }
    expect($field->fresh()->name)->toBe('Original')->and($tag->fresh()->name)->toBe('Original');
})->with(['empty' => [''], 'spaces' => ['   '], 'missing' => [null], 'too long' => [str_repeat('a', 101)], 'array' => [['invalid']]]);

test('invalid tag colors are rejected on create and edit', function (mixed $color) {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $tag = $workspace->tags()->create(['name' => 'Original', 'color' => '#16a34a']);
    $this->actingAs($user)->from('/settings/contact-data');

    $this->post(route('workspaces.tags.store', $workspace), ['name' => 'Nova', 'color' => $color])->assertSessionHasErrors('color');
    $this->patch(route('workspaces.tags.update', [$workspace, $tag]), ['name' => 'Alterada', 'color' => $color])->assertSessionHasErrors('color');
    expect($tag->fresh()->name)->toBe('Original')->and($tag->fresh()->color)->toBe('#16a34a');
})->with(['missing' => [null], 'empty' => [''], 'short' => ['#abc'], 'no hash' => ['123456'], 'invalid hex' => ['#zzzzzz'], 'css' => ['red'], 'array' => [['#123456']]]);

test('renaming preserves contact values tag links and draft and published flow references', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $field = $workspace->contactCustomFields()->create(['name' => 'Interesse', 'type' => 'text']);
    $tag = $workspace->tags()->create(['name' => 'VIP', 'color' => '#16a34a', 'created_by_user_id' => $user->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $value = $contact->customFieldValues()->create(['workspace_id' => $workspace->id, 'field_id' => $field->id, 'value' => 'Produto A']);
    $contact->tags()->attach($tag->id, ['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id]);
    $thread->tags()->attach($tag->id);
    $graph = [
        'schema_version' => 2,
        'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Start']],
            ['id' => 'action', 'type' => 'action', 'position' => ['x' => 0, 'y' => 100], 'data' => ['actions' => [
                ['type' => 'set_field', 'field_id' => $field->id, 'value' => 'Produto A'],
                ['type' => 'add_tag', 'tag_ids' => [$tag->id]],
            ]]],
        ],
        'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];
    $flow = $workspace->flows()->create(['name' => 'Fluxo', 'graph' => $graph]);
    $version = FlowVersion::create(['workspace_id' => $workspace->id, 'flow_id' => $flow->id, 'version' => 1, 'graph' => $graph, 'published_by_user_id' => $user->id, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);

    $this->actingAs($user)->from('/settings/contact-data');
    $this->patch(route('workspaces.contact-fields.update', [$workspace, $field]), ['name' => 'Produto desejado'])->assertSessionHasNoErrors();
    $this->patch(route('workspaces.tags.update', [$workspace, $tag]), ['name' => 'Prioridade', 'color' => '#ff0000', 'created_by_user_id' => 999])->assertSessionHasNoErrors();

    expect($value->fresh()->value)->toBe('Produto A')->and($value->fresh()->field_id)->toBe($field->id)
        ->and($contact->tags()->sole()->id)->toBe($tag->id)
        ->and($thread->tags()->sole()->id)->toBe($tag->id)
        ->and($tag->fresh()->created_by_user_id)->toBe($user->id)
        ->and($flow->fresh()->graph)->toBe($graph)->and($version->fresh()->graph)->toBe($graph);

    $this->get(route('workspaces.flows.edit', [$workspace, $flow]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('flows/editor')
            ->has('reference.fields', 1)->where('reference.fields.0.id', $field->id)->where('reference.fields.0.name', 'Produto desejado')
            ->has('reference.tags', 1)->where('reference.tags.0.id', $tag->id)->where('reference.tags.0.name', 'Prioridade'));
});

test('maximum length names and existing flow creation contracts remain supported', function () {
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $this->actingAs($user)->from('/settings/contact-data');
    $this->post(route('workspaces.contact-fields.store', $workspace), ['name' => str_repeat('a', 100)])->assertSessionHasNoErrors();
    $this->post(route('workspaces.tags.store', $workspace), ['name' => str_repeat('a', 100), 'color' => '#123abc'])->assertSessionHasNoErrors();

    $this->postJson(route('workspaces.flow-fields.store', $workspace), ['name' => 'Pelo fluxo'])->assertCreated()->assertJsonPath('data.type', 'text');
    $this->postJson(route('workspaces.flow-fields.store', $workspace), ['name' => 'Pelo fluxo'])->assertOk();
    $this->postJson(route('workspaces.flow-tags.store', $workspace), ['name' => 'Pelo fluxo'])->assertCreated()->assertJsonPath('data.color', '#16a34a');
    $this->postJson(route('workspaces.flow-tags.store', $workspace), ['name' => 'Pelo fluxo'])->assertOk();
});

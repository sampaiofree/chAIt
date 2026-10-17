<?php

use App\Models\Contact;
use App\Models\ContactCustomFieldValue;
use App\Models\ExternalWebhook;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contacts\ContactResourceReferences;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\Flows\FlowCondition;
use App\Services\Flows\FlowGraphValidator;
use App\Services\Flows\FlowRuntime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function conditionFixture(): array
{
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'display_name' => ' Vitória ', 'email' => 'Ana@example.test', 'cpf' => '123.456.789-00']);
    $tag = $workspace->tags()->create(['name' => 'VIP']);
    $field = $workspace->contactCustomFields()->create(['name' => 'Interesse', 'type' => 'text']);
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null]);

    return compact('user', 'workspace', 'contact', 'tag', 'field', 'connection', 'thread');
}

function conditionGraph(array $data): array
{
    return ['schema_version' => 2, 'nodes' => [
        ['id' => 'start', 'type' => 'start', 'position' => ['x' => 300, 'y' => 80], 'data' => ['label' => 'Início']],
        ['id' => 'condition', 'type' => 'condition', 'position' => ['x' => 300, 'y' => 250], 'data' => ['label' => 'Verificar contato', ...$data]],
        ['id' => 'yes', 'type' => 'wait_reply', 'position' => ['x' => 100, 'y' => 450], 'data' => ['label' => 'Sim', 'field_id' => 1, 'timeout_seconds' => 60]],
        ['id' => 'no', 'type' => 'wait_reply', 'position' => ['x' => 500, 'y' => 450], 'data' => ['label' => 'Não', 'field_id' => 1, 'timeout_seconds' => 60]],
    ], 'edges' => [
        ['id' => 'begin', 'source' => 'start', 'target' => 'condition', 'sourceHandle' => 'next', 'targetHandle' => 'input'],
        ['id' => 'yes-edge', 'source' => 'condition', 'target' => 'yes', 'sourceHandle' => 'yes', 'targetHandle' => 'input'],
        ['id' => 'no-edge', 'source' => 'condition', 'target' => 'no', 'sourceHandle' => 'no', 'targetHandle' => 'input'],
    ], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
}

function conditionVersion(array $f, array $data): array
{
    $graph = conditionGraph($data);
    $graph['nodes'][2]['data']['field_id'] = $f['field']->id;
    $graph['nodes'][3]['data']['field_id'] = $f['field']->id;
    $flow = $f['workspace']->flows()->create(['name' => 'Condição', 'graph' => $graph, 'revision' => 1]);
    $version = $flow->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $flow->update(['published_version_id' => $version->id, 'is_active' => true]);

    return [$flow, $version];
}

test('condition tag checks combine with contact text checks', function (string $operator, bool $present, string $match, bool $expected) {
    $f = conditionFixture();
    if ($present) {
        $f['contact']->tags()->attach($f['tag'], ['workspace_id' => $f['workspace']->id]);
    }
    $data = ['condition_operator' => $match, 'checks' => [
        ['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => $operator],
        ['kind' => 'field', 'field_key' => 'display_name', 'operator' => 'equals', 'value' => 'vitoria'],
    ]];
    expect(app(FlowCondition::class)->evaluate($data, $f['workspace'], $f['contact']->id))->toBe($expected);
})->with([
    ['has', true, 'all', true], ['has', false, 'all', false], ['not_has', false, 'all', true],
    ['not_has', true, 'all', false], ['has', false, 'any', true], ['not_has', true, 'any', true],
]);

test('condition normalizes Unicode while preserving textual punctuation and inner spaces', function (?string $value, string $operator, string $needle, bool $expected) {
    $f = conditionFixture();
    ContactCustomFieldValue::create(['workspace_id' => $f['workspace']->id, 'contact_id' => $f['contact']->id, 'field_id' => $f['field']->id, 'value' => $value]);
    expect(app(FlowCondition::class)->evaluate(['condition_operator' => 'all', 'checks' => [
        ['kind' => 'field', 'field_id' => $f['field']->id, 'operator' => $operator, 'value' => $needle],
    ]], $f['workspace'], $f['contact']->id))->toBe($expected);
})->with([
    ['Quero um curso de inglês online', 'contains', 'INGLES', true],
    ['Quero um curso de inglês online', 'equals', 'inglês', false],
    [" Vito\u{0301}ria ", 'equals', 'vitória', true], ['Vitória', 'equals', ' vitoria ', true],
    ['Mariana', 'contains', 'ana', true], ['a  b', 'equals', 'a b', false],
    ['123.456.789-00', 'equals', '12345678900', false], ['123.456.789-00', 'contains', '456.789', true],
    [null, 'equals', 'curso', false], ['', 'contains', 'curso', false], ['   ', 'equals', 'curso', false],
    ["\u{00a0}Vitória\u{00a0}", 'equals', 'vitoria', true],
]);

test('all standard fields and absent custom values are supported', function () {
    $f = conditionFixture();
    foreach (['display_name' => 'vitoria', 'email' => 'ana@EXAMPLE.test', 'cpf' => '123.456.789-00'] as $key => $value) {
        expect(app(FlowCondition::class)->evaluate(['condition_operator' => 'all', 'checks' => [['kind' => 'field', 'field_key' => $key, 'operator' => 'equals', 'value' => $value]]], $f['workspace'], $f['contact']->id))->toBeTrue();
    }
    expect(app(FlowCondition::class)->evaluate(['condition_operator' => 'any', 'checks' => [['kind' => 'field', 'field_id' => $f['field']->id, 'operator' => 'contains', 'value' => 'curso']]], $f['workspace'], $f['contact']->id))->toBeFalse();
});

test('incomplete conditions save as drafts but cannot publish and diagnostics identify the verification', function () {
    $f = conditionFixture();
    [$flow] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => []]);
    $graph = $flow->graph;
    $this->actingAs($f['user'])->putJson("/workspaces/{$f['workspace']->id}/flows/{$flow->id}", ['name' => $flow->name, 'graph' => $graph, 'revision' => 1])->assertOk();
    $this->postJson("/workspaces/{$f['workspace']->id}/flows/{$flow->id}/publish", ['revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('graph.nodes.condition.checks');
    $graph['nodes'][1]['data']['checks'] = [['kind' => 'field', 'field_key' => 'display_name', 'operator' => 'equals', 'value' => '   ']];
    $pending = app(AssistantTools::class)->validate($graph, $f['workspace'])['pending'];
    expect($pending)->toContain(['node_id' => 'condition', 'path' => 'checks.0.value', 'message' => 'Preencha a expressão com até 4.000 caracteres.']);
    expect(fn () => app(FlowGraphValidator::class)->validateForPublish($graph, $f['workspace'], $flow))->toThrow(ValidationException::class);
});

test('invalid references identifiers and incompatible operators are rejected at evaluation', function (string $case) {
    $f = conditionFixture();
    $other = Workspace::factory()->create();
    $foreign = $other->contactCustomFields()->create(['name' => 'Privado', 'type' => 'text']);
    $unsupported = $f['workspace']->contactCustomFields()->create(['name' => 'Data', 'type' => 'date']);
    $checks = match ($case) {
        'foreign-tag' => [['kind' => 'tag', 'tag_id' => $other->tags()->create(['name' => 'Privada'])->id, 'operator' => 'not_has']],
        'foreign-field' => [['kind' => 'field', 'field_id' => $foreign->id, 'operator' => 'equals', 'value' => 'a']],
        'unsupported' => [['kind' => 'field', 'field_id' => $unsupported->id, 'operator' => 'equals', 'value' => 'a']],
        'both' => [['kind' => 'field', 'field_id' => $f['field']->id, 'field_key' => 'display_name', 'operator' => 'equals', 'value' => 'a']],
        'column' => [['kind' => 'field', 'field_key' => 'notes', 'operator' => 'contains', 'value' => 'a']],
        'operator' => [['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'contains']],
        'empty' => [],
        'whitespace' => [['kind' => 'field', 'field_key' => 'email', 'operator' => 'equals', 'value' => "\u{00a0}"]],
    };
    expect(fn () => app(FlowCondition::class)->evaluate(['condition_operator' => 'all', 'checks' => $checks], $f['workspace'], $f['contact']->id))->toThrow(ValidationException::class);
})->with(['foreign-tag', 'foreign-field', 'unsupported', 'both', 'column', 'operator', 'empty', 'whitespace']);

test('condition graph normalization is idempotent and leaves published versions immutable', function () {
    $f = conditionFixture();
    [$flow, $version] = conditionVersion($f, ['condition_operator' => 'any', 'tag_ids' => [$f['tag']->id]]);
    $before = $version->graph;
    $validator = app(FlowGraphValidator::class);
    $graph = $validator->normalize($before);
    expect($graph['nodes'][1]['data']['checks'])->toBe([['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'has']])
        ->and($graph['edges'])->toBe($before['edges'])->and($validator->normalize($graph))->toBe($graph)
        ->and($version->fresh()->graph)->toBe($before);
    $this->actingAs($f['user'])->get("/workspaces/{$f['workspace']->id}/flows/{$flow->id}/assistant")->assertOk()->assertJsonPath('data.flow.graph.nodes.1.data.checks.0.tag_id', $f['tag']->id);
});

test('runtime sees earlier field operations and only advances the condition once', function () {
    $f = conditionFixture();
    [$flow, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => [['kind' => 'field', 'field_id' => $f['field']->id, 'operator' => 'contains', 'value' => 'inglês']]]);
    $graph = $version->graph;
    $graph['nodes'][] = ['id' => 'set', 'type' => 'operations', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Preencher', 'actions' => [['id' => 'action', 'kind' => 'set_field', 'field_id' => $f['field']->id, 'value' => 'Curso de INGLES']]]];
    $graph['edges'][0]['target'] = 'set';
    $graph['edges'][] = ['id' => 'set-condition', 'source' => 'set', 'target' => 'condition', 'sourceHandle' => 'next', 'targetHandle' => 'input'];
    $version->update(['graph' => $graph]);
    $execution = app(FlowRuntime::class)->start($version, $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    app(FlowRuntime::class)->run($execution->id);
    expect($execution->fresh()->current_node_id)->toBe('yes')->and($execution->fresh()->status)->toBe('waiting_reply');
    $events = DB::table('flow_events')->where('flow_execution_id', $execution->id)->where('event_type', 'transition')->get()->filter(fn ($event) => (json_decode($event->metadata, true)['from'] ?? null) === 'condition');
    expect($events)->toHaveCount(1)->and($events->toJson())->not->toContain('INGLES');
});

test('invalid configuration fails the runtime without choosing the no branch', function () {
    $f = conditionFixture();
    [, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => []]);
    $execution = app(FlowRuntime::class)->start($version, $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    expect($execution->fresh()->status)->toBe('failed')->and($execution->fresh()->current_node_id)->toBe('condition');
});

test('database failure during evaluation never routes to no', function () {
    $f = conditionFixture();
    [, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => [['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'has']]]);
    $execution = app(FlowRuntime::class)->start($version, $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    $this->partialMock(FlowCondition::class, fn ($mock) => $mock->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('Database unavailable')));
    expect(fn () => app(FlowRuntime::class)->run($execution->id))->toThrow(RuntimeException::class);
    expect($execution->fresh()->current_node_id)->toBe('condition');
});

test('deleting unreferenced resources removes field values and tag links after admin authorization', function () {
    $f = conditionFixture();
    ContactCustomFieldValue::create(['workspace_id' => $f['workspace']->id, 'contact_id' => $f['contact']->id, 'field_id' => $f['field']->id, 'value' => 'privado']);
    $f['contact']->tags()->attach($f['tag'], ['workspace_id' => $f['workspace']->id]);
    $this->actingAs($f['user'])->delete("/workspaces/{$f['workspace']->id}/contact-fields/{$f['field']->id}")->assertRedirect();
    $this->delete("/workspaces/{$f['workspace']->id}/tags/{$f['tag']->id}")->assertRedirect();
    expect(ContactCustomFieldValue::count())->toBe(0)->and($f['contact']->tags()->count())->toBe(0);
});

test('deletion blocks every existing published block reference', function (string $kind, string $type) {
    $f = conditionFixture();
    [$flow, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => []]);
    $id = $f[$kind]->id;
    $data = match ($type) {
        'legacy' => ['tag_ids' => [$id]],
        'condition' => ['checks' => [['kind' => $kind, $kind.'_id' => $id]]],
        'wait_reply' => ['field_id' => $id],
        'operations' => ['actions' => [['kind' => $kind === 'tag' ? 'remove_tag' : 'set_field', $kind.'_id' => $id]]],
        'ai' => [$kind === 'tag' ? 'ai_tags' : 'ai_fields' => [[$kind.'_id' => $id]]],
    };
    $version->update(['graph' => ['nodes' => [['id' => 'ref', 'type' => $type === 'legacy' ? 'condition' : $type, 'data' => $data]]]]);
    $path = $kind === 'field' ? 'contact-fields' : 'tags';
    $this->actingAs($f['user'])->deleteJson("/workspaces/{$f['workspace']->id}/$path/$id")->assertUnprocessable()->assertJsonValidationErrors('resource');
    expect(app(ContactResourceReferences::class)->find($f['workspace'], $kind, $id))->toContain('Fluxo “Condição” (versão 1)');
})->with([['tag', 'legacy'], ['tag', 'condition'], ['field', 'condition'], ['field', 'wait_reply'], ['tag', 'operations'], ['field', 'operations'], ['tag', 'ai'], ['field', 'ai']]);

test('old versions are protected by active executions and released once dependencies end', function () {
    $f = conditionFixture();
    [$flow, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => [['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'has']]]);
    $execution = app(FlowRuntime::class)->start($version, $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    $next = $flow->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 2, 'graph' => ['nodes' => []], 'published_at' => now()]);
    $flow->update(['published_version_id' => $next->id]);
    $endpoint = "/workspaces/{$f['workspace']->id}/tags/{$f['tag']->id}";
    $this->actingAs($f['user'])->deleteJson($endpoint)->assertUnprocessable();
    app(FlowRuntime::class)->finish($execution, 'completed');
    $this->delete($endpoint)->assertRedirect();
});

test('sequence snapshots protect old versions before an execution starts', function (bool $active) {
    $f = conditionFixture();
    [$flow, $version] = conditionVersion($f, ['condition_operator' => 'all', 'checks' => [['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'has']]]);
    $sequence = Sequence::create(['workspace_id' => $f['workspace']->id, 'name' => 'Retorno', 'draft' => [], 'is_active' => $active]);
    $snapshot = $sequence->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 1, 'name' => 'Retorno', 'timezone' => 'America/Sao_Paulo', 'published_at' => now()]);
    $sequence->update(['published_version_id' => $snapshot->id]);
    DB::table('sequence_version_flows')->insert(['sequence_version_id' => $snapshot->id, 'flow_id' => $flow->id, 'flow_version_id' => $version->id]);
    if (! $active) {
        SequenceEnrollment::create(['workspace_id' => $f['workspace']->id, 'sequence_id' => $sequence->id, 'sequence_version_id' => $snapshot->id, 'contact_id' => $f['contact']->id, 'thread_id' => $f['thread']->id, 'uazapi_connection_id' => $f['connection']->id, 'status' => 'pending', 'enrolled_at' => now(), 'source' => 'manual']);
    }
    $next = $flow->versions()->create(['workspace_id' => $f['workspace']->id, 'version' => 2, 'graph' => ['nodes' => []], 'published_at' => now()]);
    $flow->update(['published_version_id' => $next->id]);
    expect(app(ContactResourceReferences::class)->find($f['workspace'], 'tag', $f['tag']->id))->toContain('Sequência “Retorno”');
    $this->actingAs($f['user'])->deleteJson("/workspaces/{$f['workspace']->id}/tags/{$f['tag']->id}")->assertUnprocessable();
})->with([true, false]);

test('active webhooks protect their field and tag references while drafts do not', function () {
    $f = conditionFixture();
    $webhook = ExternalWebhook::create(['workspace_id' => $f['workspace']->id, 'name' => 'Formulário', 'endpoint_key' => bin2hex(random_bytes(32)), 'status' => 'active', 'mappings' => [['target_kind' => 'custom', 'target' => $f['field']->id]], 'tag_rules' => [['add' => [$f['tag']->id], 'remove' => []]], 'payload_fields' => []]);
    foreach (['tag', 'field'] as $kind) {
        expect(app(ContactResourceReferences::class)->find($f['workspace'], $kind, $f[$kind]->id))->toBe(['Webhook “Formulário”']);
    }
    $webhook->update(['status' => 'test']);
    expect(app(ContactResourceReferences::class)->find($f['workspace'], 'field', $f['field']->id))->toBe([]);
});

test('deletion requires admin and cannot reach another workspace resource', function () {
    $f = conditionFixture();
    $other = Workspace::factory()->create();
    $tag = $other->tags()->create(['name' => 'Privada']);
    $this->actingAs($f['user'])->deleteJson("/workspaces/{$f['workspace']->id}/tags/{$tag->id}")->assertNotFound();
    $f['user']->membershipFor($f['workspace'])->update(['role' => 'manager', 'can_manage_flows' => true]);
    $this->actingAs($f['user']->fresh())->deleteJson("/workspaces/{$f['workspace']->id}/tags/{$f['tag']->id}")->assertForbidden();
});

test('legacy published conditions still select the correct branch without rewriting their version', function (bool $present) {
    $f = conditionFixture();
    if ($present) {
        $f['contact']->tags()->attach($f['tag'], ['workspace_id' => $f['workspace']->id]);
    }
    [, $version] = conditionVersion($f, ['condition_operator' => 'all', 'tag_ids' => [$f['tag']->id]]);
    $before = $version->graph;
    $execution = app(FlowRuntime::class)->start($version, $f['contact']->id, $f['thread']->id, $f['connection'], 'test');
    app(FlowRuntime::class)->run($execution->id);
    expect($execution->fresh()->current_node_id)->toBe($present ? 'yes' : 'no')->and($version->fresh()->graph)->toBe($before);
})->with([true, false]);

test('draft-only references permit deletion and become assistant publication diagnostics', function () {
    $f = conditionFixture();
    $graph = conditionGraph(['condition_operator' => 'all', 'checks' => [['kind' => 'tag', 'tag_id' => $f['tag']->id, 'operator' => 'has']]]);
    $f['workspace']->flows()->create(['name' => 'Rascunho', 'graph' => $graph]);
    $this->actingAs($f['user'])->delete("/workspaces/{$f['workspace']->id}/tags/{$f['tag']->id}")->assertRedirect();
    expect(app(AssistantTools::class)->validate($graph, $f['workspace'])['pending'])->toContain([
        'node_id' => 'condition', 'path' => 'checks.0.tag_id', 'message' => 'Selecione uma etiqueta existente neste workspace.',
    ]);
});

test('condition cannot evaluate a contact from another workspace', function () {
    $f = conditionFixture();
    $other = Contact::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    expect(fn () => app(FlowCondition::class)->evaluate(['condition_operator' => 'all', 'checks' => [
        ['kind' => 'field', 'field_key' => 'display_name', 'operator' => 'contains', 'value' => 'a'],
    ]], $f['workspace'], $other->id))->toThrow(ModelNotFoundException::class);
});

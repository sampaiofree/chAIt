<?php

use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\Sequence;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Sequences\SequenceDefinitionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'Browser fixtures refused or failed: '.get_class($error)."\n");
    exit(1);
});
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'chait_test'
    || config('database.connections.pgsql.url')
    || ! preg_match('/\Achait_browser_[a-f0-9]{32}\z/', config('database.connections.pgsql.search_path'))) {
    throw new RuntimeException('Browser fixtures require an isolated test database.');
}
$admin = User::factory()->create(['name' => 'Admin de teste', 'email' => 'admin@example.test']);
$workspace = $admin->fresh()->currentWorkspace;
$workspace->update(['name' => 'Workspace de revisão visual', 'timezone' => 'America/Sao_Paulo']);
UazapiConnection::factory()->create(['workspace_id' => $workspace->id, 'status' => 'connected']);
$agent = User::factory()->create(['name' => 'Atendente de teste', 'email' => 'agent@example.test', 'current_workspace_id' => $workspace->id]);
WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'joined_at' => now()]);
User::factory()->create(['name' => 'Conta existente', 'email' => 'existing@example.test']);
$other = Workspace::create(['name' => 'Outro workspace', 'slug' => 'outro-workspace', 'timezone' => 'America/Sao_Paulo']);
WorkspaceMembership::create(['workspace_id' => $other->id, 'user_id' => $admin->id, 'role' => 'owner', 'joined_at' => now()]);
Flow::create(['workspace_id' => $workspace->id, 'name' => 'Fluxo de teste', 'revision' => 1, 'graph' => ['schema_version' => 2, 'nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 100, 'y' => 100], 'data' => ['label' => 'Início', 'nodeType' => 'start', 'description' => 'Início do fluxo']]], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]], 'is_active' => false]);

Flow::create(['workspace_id' => $workspace->id, 'name' => 'Prévia dos blocos', 'revision' => 1, 'graph' => [
    'schema_version' => 2,
    'nodes' => [
        ['id' => 'preview-start', 'type' => 'start', 'position' => ['x' => 40, 'y' => 40], 'data' => ['label' => 'Início da prévia', 'description' => 'Descrição interna do início']],
        ['id' => 'preview-content', 'type' => 'content', 'position' => ['x' => 310, 'y' => 40], 'data' => ['label' => 'Identificação interna', 'description' => 'DESCRIÇÃO INTERNA EXISTENTE', 'items' => [
            ['id' => 'message-first', 'kind' => 'text', 'text' => 'Olá! Esta é uma mensagem para o contato. O conteúdo permanece identificado como mensagem, mesmo com um texto bastante longo que ocupa várias linhas e deve ter uma prévia limitada no canvas.'],
            ['id' => 'media-preview', 'kind' => 'media', 'asset_id' => 1, 'media_type' => 'image', 'original_name' => 'catalogo.png', 'caption' => 'Veja as opções disponíveis.'],
            ['id' => 'delay-preview', 'kind' => 'delay', 'seconds' => 7],
            ['id' => 'message-last', 'kind' => 'text', 'text' => 'Mensagem depois da espera.'],
        ]]],
    ],
    'edges' => [], 'viewport' => ['x' => 20, 'y' => 40, 'zoom' => 1],
], 'is_active' => false]);

FlowAsset::create(['workspace_id' => $workspace->id, 'flow_id' => 2, 'disk' => 'local', 'path' => 'browser-fixtures/catalogo.png', 'original_name' => 'catalogo.png', 'mime_type' => 'image/png', 'size' => 1234]);

$workspace->tags()->create(['name' => 'Interesse IA', 'color' => '#16a34a']);
$workspace->contactCustomFields()->create(['name' => 'Curso IA', 'type' => 'text']);

$sequenceFlow = Flow::create(['workspace_id' => $workspace->id, 'name' => 'Acompanhamento publicado', 'revision' => 1, 'graph' => ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []]]);
$sequenceFlowVersion = $sequenceFlow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $sequenceFlow->graph, 'published_at' => now()]);
$sequenceFlow->update(['is_active' => true, 'published_version_id' => $sequenceFlowVersion->id]);
$sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => 'Pós-venda e acompanhamento de clientes', 'draft' => ['name' => 'Pós-venda e acompanhamento de clientes', 'timezone' => 'America/Sao_Paulo', 'steps' => [['schedule_type' => 'interval', 'delay_seconds' => 60, 'flow_id' => $sequenceFlow->id], ['schedule_type' => 'specific_datetime', 'scheduled_at' => '2026-10-20T09:00', 'flow_id' => $sequenceFlow->id]]]]);
app(SequenceDefinitionService::class)->publish($sequence, $workspace, $admin, 1);
$sequenceContact = Contact::factory()->create(['workspace_id' => $workspace->id]);
$sequenceThread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => UazapiConnection::where('workspace_id', $workspace->id)->value('id'), 'contact_id' => $sequenceContact->id, 'status' => 'bot', 'human_opened_at' => null]);

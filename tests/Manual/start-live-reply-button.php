<?php

use App\Models\Contact;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Flows\FlowContentButton;
use App\Services\Flows\FlowRuntime;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Somente no ambiente local.');
}
if (($argv[1] ?? '') !== '--send') {
    throw new InvalidArgumentException('Informe --send para iniciar o ensaio real.');
}

$connection = UazapiConnection::findOrFail(1);
$contact = Contact::findOrFail(3);
$thread = Thread::findOrFail(3);
if (! app(FlowContentButton::class)->profile($connection, 'reply')) {
    throw new RuntimeException('Ative o perfil provisório local para testar a resposta.');
}
$field = $connection->workspace->contactCustomFields()->firstOrCreate(['name' => 'Homologação Resposta'], ['type' => 'text']);
$item = ['id' => 'reply-test', 'kind' => 'button', 'text' => 'Homologação do botão Resposta.',
    'button' => ['kind' => 'reply', 'label' => 'Responder teste']];
$graph = ['schema_version' => 2, 'nodes' => [
    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
    ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'items' => [$item]]],
    ['id' => 'wait', 'type' => 'wait_reply', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'Capturar', 'field_id' => $field->id, 'timeout_seconds' => 300]],
], 'edges' => [
    ['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input'],
    ['id' => 'next', 'source' => 'content', 'sourceHandle' => 'next', 'target' => 'wait', 'targetHandle' => 'input'],
], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
$flow = $connection->workspace->flows()->create(['name' => 'Homologação local Botão Resposta', 'graph' => $graph, 'revision' => 1]);
$version = $flow->versions()->create(['workspace_id' => $connection->workspace_id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
$flow->update(['published_version_id' => $version->id, 'is_active' => true]);
$execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'manual_homologation');
if (! $execution) {
    throw new RuntimeException('Contato ocupado por outra automação.');
}
$selection = app(FlowContentButton::class)->replyId($execution, 'content', $item['id']);
echo json_encode(['flow_id' => $flow->id, 'execution_id' => $execution->id, 'field_id' => $field->id,
    'selection_hash' => hash('sha256', $selection)], JSON_UNESCAPED_SLASHES).PHP_EOL;

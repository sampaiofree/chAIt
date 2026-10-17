<?php

use App\Models\Contact;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Flows\FlowMenu;
use App\Services\Flows\FlowRuntime;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local')) {
    throw new RuntimeException('Somente no ambiente local.');
}

$format = $argv[1] ?? '';
if (! in_array($format, ['button', 'list'], true)) {
    throw new InvalidArgumentException('Informe button ou list.');
}

$connection = UazapiConnection::findOrFail(1);
$contact = Contact::findOrFail(3);
$thread = Thread::findOrFail(3);
if (! app(FlowMenu::class)->profile($connection, $format)) {
    throw new RuntimeException('Formato sem perfil local.');
}

$data = array_replace(FlowMenu::defaults(), [
    'menu_format' => $format,
    'question' => 'Homologação do roteamento: selecione Ramo B.',
    'options' => [
        ['id' => 'a', 'label' => 'Ramo A'],
        ['id' => 'b', 'label' => 'Ramo B'],
    ],
]);
$graph = ['schema_version' => 2, 'nodes' => [
    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
    ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Menu', ...$data]],
    ['id' => 'a', 'type' => 'content', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'A', 'items' => [['id' => 'a-text', 'kind' => 'text', 'text' => 'Rota A confirmada']]]],
    ['id' => 'b', 'type' => 'content', 'position' => ['x' => 300, 'y' => 400], 'data' => ['label' => 'B', 'items' => [['id' => 'b-text', 'kind' => 'text', 'text' => 'Rota B confirmada']]]],
], 'edges' => [
    ['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'menu', 'targetHandle' => 'input'],
    ['id' => 'a-edge', 'source' => 'menu', 'sourceHandle' => 'option:a', 'target' => 'a', 'targetHandle' => 'input'],
    ['id' => 'b-edge', 'source' => 'menu', 'sourceHandle' => 'option:b', 'target' => 'b', 'targetHandle' => 'input'],
], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];

$flow = $connection->workspace->flows()->create(['name' => 'Homologação local Menu '.$format, 'graph' => $graph, 'revision' => 1]);
$version = $flow->versions()->create(['workspace_id' => $connection->workspace_id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
$flow->update(['published_version_id' => $version->id, 'is_active' => true]);
$execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'manual_homologation');
if (! $execution) {
    $flow->update(['published_version_id' => null, 'is_active' => false]);
    $version->delete();
    $flow->delete();
    throw new RuntimeException('Contato ocupado por outra automação.');
}
echo json_encode(['flow_id' => $flow->id, 'execution_id' => $execution->id, 'format' => $format], JSON_UNESCAPED_SLASHES).PHP_EOL;

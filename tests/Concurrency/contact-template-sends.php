<?php

use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'chait_test' || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Requires APP_ENV=testing, PostgreSQL chait_test and empty DB_URL.');
}
Http::preventStrayRequests();
Queue::fake();

function sendVerify(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'worker') {
    sendVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $executionId, $barrier, $holder] = $argv;
    $execution = FlowExecution::with(['connection', 'thread'])->findOrFail($executionId);
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    Http::fake(['*/message/find' => Http::response(['messages' => [['id' => 'found', 'status' => 'Sent']]])]);
    $operation = function () use ($action, $execution, $holder, $barrier): void {
        if ($holder !== 'yes') {
            touch($barrier.'-read');
        }
        if ($action === 'confirm') {
            app(FlowRuntime::class)->run($execution->id);
        } else {
            app(FlowRuntime::class)->finish($execution, 'interrupted', 'Atendimento humano iniciado.');
        }
    };
    if ($holder === 'yes') {
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($operation, $barrier): void {
            $operation();
            touch($barrier);
            $deadline = microtime(true) + 10;
            while (! is_file($barrier.'-read')) {
                sendVerify(microtime(true) < $deadline, 'Contender never started');
                usleep(10000);
            }
            usleep(400000);
        });
    } else {
        $deadline = microtime(true) + 10;
        while (! is_file($barrier)) {
            sendVerify(microtime(true) < $deadline, 'Barrier timeout');
            usleep(10000);
        }
        $operation();
    }
    echo 'done';
    exit(0);
}

$schema = 'chait_concurrency_'.bin2hex(random_bytes(12));
DB::statement('CREATE SCHEMA "'.$schema.'"');
config(['database.connections.pgsql.search_path' => $schema]);
putenv('DB_SCHEMA='.$schema);
DB::purge('pgsql');
$processes = [];
$barriers = [];
try {
    sendVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    config(["flow-content-button.connections.{$connection->id}.reply" => ['verified' => true, 'max_text_length' => 4000, 'max_label_length' => 30, 'max_reply_id_length' => 128]]);
    foreach ([['confirm', 'confirm'], ['cancel', 'confirm'], ['confirm', 'cancel']] as [$first, $second]) {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id, 'status' => 'bot', 'human_opened_at' => null]);
        $graph = ['schema_version' => 2, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
            ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'items' => [['id' => 'item', 'kind' => 'text', 'text' => ['template_version' => 1, 'parts' => [['type' => 'standard', 'key' => 'nome']]]], ['id' => 'delay', 'kind' => 'delay', 'seconds' => 3]]]],
        ], 'edges' => [['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input']], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
        $flow = $workspace->flows()->create(['name' => 'Race '.$first, 'graph' => $graph, 'revision' => 1]);
        $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
        $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'default');
        $execution->update(['current_node_id' => 'content', 'context' => ['item_index' => 0, 'pending_item_id' => 'item', 'pending_track_id' => 'reserved', 'pending_attempts' => 1]]);
        $barrier = sys_get_temp_dir().'/chait-content-button-'.bin2hex(random_bytes(12));
        $barriers[] = $barrier;
        $barriers[] = $barrier.'-read';
        foreach ([[$first, 'yes'], [$second, 'no']] as [$action, $holder]) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $execution->id, $barrier, $holder], env: ['DB_SCHEMA' => $schema]);
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }
        foreach (array_slice($processes, -2) as $process) {
            $process->wait();
            sendVerify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
        }
        $context = $execution->fresh()->context;
        $confirmed = DB::table('flow_events')->where('flow_execution_id', $execution->id)->where('event_type', 'message_confirmed')->count();
        sendVerify($confirmed === ($first === 'cancel' ? 0 : 1), 'Duplicate confirmation or late advancement');
        sendVerify(($context['item_index'] ?? 0) === ($first === 'cancel' ? 0 : 1), 'Wrong item index after race');
        if ($first === 'cancel' || $second === 'cancel') {
            sendVerify($execution->fresh()->status === 'interrupted', 'Cancellation was reopened');
        }
        echo $first.' / '.$second." passed\n";
    }
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    foreach ($barriers as $barrier) {
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
    DB::purge('pgsql');
    config(['database.connections.pgsql.search_path' => 'public']);
    DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}

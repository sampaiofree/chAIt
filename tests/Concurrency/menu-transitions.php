<?php

use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\FlowMenuWait;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowMenu;
use App\Services\Flows\FlowMenuRuntime;
use App\Services\Flows\FlowRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
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

function menuVerify(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'worker') {
    menuVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $waitId, $barrier, $holder] = $argv;
    $wait = FlowMenuWait::findOrFail($waitId);
    $execution = FlowExecution::findOrFail($wait->flow_execution_id);
    Carbon::setTestNow($action === 'select' ? $wait->expires_at->subSecond() : $wait->expires_at->addSecond());
    $operation = function () use ($action, $wait, $execution): void {
        if ($action === 'select') {
            app(FlowMenuRuntime::class)->receive($execution, ['messageid' => 'selection-race', 'messageType' => 'Conversation', 'text' => '1']);
        } else {
            app(FlowMenuRuntime::class)->expire($wait->id);
        }
    };
    if ($holder === 'yes') {
        app(FlowExecutionManager::class)->locked($execution->workspace_id, $execution->uazapi_connection_id, $execution->contact_id, function () use ($operation, $barrier): void {
            $operation();
            touch($barrier);
            usleep(700000);
        });
    } else {
        $deadline = microtime(true) + 10;
        while (! is_file($barrier)) {
            menuVerify(microtime(true) < $deadline, 'Barrier timeout');
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
    menuVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    Http::fake(['*/send/text' => Http::response(['id' => 'confirmed', 'status' => 'Sent'])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    foreach (['select', 'expire'] as $first) {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id, 'status' => 'bot', 'human_opened_at' => null]);
        $graph = ['schema_version' => 2, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
            ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Menu', ...FlowMenu::defaults(), 'question' => 'Escolha', 'options' => [['id' => 'a', 'label' => 'A']]]],
        ], 'edges' => [['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'menu', 'targetHandle' => 'input']], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
        $flow = $workspace->flows()->create(['name' => 'Race '.$first, 'graph' => $graph, 'revision' => 1]);
        $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
        $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'default');
        app(FlowRuntime::class)->run($execution->id);
        $wait = FlowMenuWait::where('flow_execution_id', $execution->id)->sole();
        app(FlowMenuRuntime::class)->process($wait->id);
        $barrier = sys_get_temp_dir().'/chait-menu-'.bin2hex(random_bytes(12));
        $barriers[] = $barrier;
        $holder = new Process([PHP_BINARY, __FILE__, 'worker', $first, (string) $wait->id, $barrier, 'yes'], env: ['DB_SCHEMA' => $schema]);
        $contender = new Process([PHP_BINARY, __FILE__, 'worker', $first === 'select' ? 'expire' : 'select', (string) $wait->id, $barrier, 'no'], env: ['DB_SCHEMA' => $schema]);
        foreach ([$holder, $contender] as $process) {
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }
        foreach ([$holder, $contender] as $process) {
            $process->wait();
            menuVerify($process->isSuccessful(), 'Race worker failed: '.$process->getErrorOutput());
        }
        $wait->refresh();
        $execution->refresh();
        menuVerify($wait->status === ($first === 'select' ? 'selected' : 'timed_out'), 'Wrong winner');
        menuVerify(($execution->context['resume_handle'] ?? null) === ($first === 'select' ? 'option:a' : 'continue'), 'Wrong transition');
        menuVerify(DB::table('flow_events')->where('flow_execution_id', $execution->id)->whereIn('event_type', ['menu_selected', 'menu_timed_out'])->count() === 1, 'More than one terminal transition');
    }
    echo "Menu PostgreSQL concurrency: 2 races passed; one transition per wait.\n";
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
    DB::statement('SET search_path TO public');
    DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
}

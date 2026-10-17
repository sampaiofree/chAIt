<?php

use App\Http\Controllers\Settings\ContactDataController;
use App\Http\Controllers\Workspace\FlowController;
use App\Models\Flow;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Flows\FlowGraphValidator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'chait_test' || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Requires APP_ENV=testing, PostgreSQL chait_test and empty DB_URL.');
}
Http::preventStrayRequests();

function conditionVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function conditionBarrier(string $path): void
{
    $deadline = microtime(true) + 10;
    while (! is_file($path)) {
        conditionVerify(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
}

if (($argv[1] ?? '') === 'worker') {
    conditionVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $flowId, $tagId, $barrier, $holder] = $argv;
    $flow = Flow::findOrFail($flowId);
    $workspace = $flow->workspace;
    $user = User::where('current_workspace_id', $workspace->id)->firstOrFail();
    $request = Request::create('/', $action === 'publish' ? 'POST' : 'DELETE', ['revision' => $flow->revision]);
    $request->setUserResolver(fn () => $user);
    $operation = function () use ($action, $request, $workspace, $flow, $tagId): void {
        if ($action === 'publish') {
            app(FlowController::class)->publish($request, $workspace, $flow->id, app(FlowGraphValidator::class));
        } else {
            app(ContactDataController::class)->destroyTag($request, $workspace, (int) $tagId);
        }
    };
    try {
        if ($holder === 'yes') {
            DB::transaction(function () use ($operation, $workspace, $barrier): void {
                Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                $operation();
                touch($barrier);
                // Hold the real operation uncommitted while the competing process reaches its lock.
                usleep(700000);
            });
        } else {
            conditionBarrier($barrier);
            $operation();
        }
        echo 'done';
    } catch (ValidationException) {
        echo 'blocked';
    }
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
    conditionVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    foreach (['publish', 'delete'] as $first) {
        $tag = $workspace->tags()->create(['name' => 'Race '.$first]);
        $graph = ['schema_version' => 2, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
            ['id' => 'condition', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Condição', 'condition_operator' => 'all', 'checks' => [['kind' => 'tag', 'tag_id' => $tag->id, 'operator' => 'has']]]],
        ], 'edges' => [['id' => 'edge', 'source' => 'start', 'target' => 'condition', 'sourceHandle' => 'next', 'targetHandle' => 'input']], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
        $flow = $workspace->flows()->create(['name' => 'Race '.$first, 'revision' => 1, 'graph' => $graph]);
        $barrier = sys_get_temp_dir().'/chait-condition-'.bin2hex(random_bytes(10));
        $barriers[] = $barrier;
        $holder = new Process([PHP_BINARY, __FILE__, 'worker', $first, (string) $flow->id, (string) $tag->id, $barrier, 'yes'], env: ['DB_SCHEMA' => $schema]);
        $contender = new Process([PHP_BINARY, __FILE__, 'worker', $first === 'publish' ? 'delete' : 'publish', (string) $flow->id, (string) $tag->id, $barrier, 'no'], env: ['DB_SCHEMA' => $schema]);
        foreach ([$holder, $contender] as $process) {
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }
        $holder->wait();
        $contender->wait();
        conditionVerify($holder->isSuccessful(), 'Holder failed: '.$holder->getErrorOutput());
        conditionVerify($contender->isSuccessful(), 'Contender failed: '.$contender->getErrorOutput());
        conditionVerify($holder->getOutput() === 'done' && $contender->getOutput() === 'blocked', 'Publication/deletion race bypassed protection');
        conditionVerify(($flow->fresh()->published_version_id !== null) === ($first === 'publish'), 'Incorrect published state');
        conditionVerify($workspace->tags()->whereKey($tag->id)->exists() === ($first === 'publish'), 'Published flow lost its tag');
        echo $first." wins: competing operation blocked\n";
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
    DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
}

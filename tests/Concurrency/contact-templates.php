<?php

use App\Http\Controllers\Settings\ContactDataController;
use App\Models\Flow;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FlowAssistant\DraftSessionService;
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

function templateVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function templateBarrier(string $path): void
{
    $deadline = microtime(true) + 10;
    while (! is_file($path)) {
        templateVerify(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
}

if (($argv[1] ?? '') === 'worker') {
    templateVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $flowId, $fieldId, $barrier, $holder] = $argv;
    $flow = Flow::findOrFail($flowId);
    $workspace = $flow->workspace;
    $user = User::where('current_workspace_id', $workspace->id)->firstOrFail();
    $request = Request::create('/', 'DELETE');
    $request->setUserResolver(fn () => $user);
    $operation = function () use ($action, $request, $workspace, $flow, $fieldId): void {
        if ($action !== 'delete') {
            $graph = $flow->graph;
            $graph['nodes'][1]['data']['items'][0]['text'] = ['template_version' => 1, 'parts' => [['type' => 'custom', 'field_id' => (int) $fieldId]]];
            $service = app(DraftSessionService::class);
            $data = ['name' => $flow->name, 'graph' => $graph, 'revision' => $flow->revision];
            if ($action === 'save') {
                $service->save($flow, $data);
            } else {
                $service->update($flow, $data);
            }
        } else {
            app(ContactDataController::class)->destroyField($request, $workspace, (int) $fieldId);
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
            templateBarrier($barrier);
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
    templateVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    foreach (['save', 'preview'] as $write) {
        foreach ([$write, 'delete'] as $first) {
            $field = $workspace->contactCustomFields()->create(['name' => 'Race '.$write.$first, 'type' => 'text']);
            $graph = ['schema_version' => 2, 'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
                ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'items' => [['id' => 'item', 'kind' => 'text', 'text' => 'Sem referência']]]],
            ], 'edges' => [['id' => 'edge', 'source' => 'start', 'target' => 'content', 'sourceHandle' => 'next', 'targetHandle' => 'input']], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
            $flow = $workspace->flows()->create(['name' => 'Race '.$write.$first, 'revision' => 1, 'graph' => $graph]);
            $barrier = sys_get_temp_dir().'/chait-template-'.bin2hex(random_bytes(10));
            $barriers[] = $barrier;
            $holder = new Process([PHP_BINARY, __FILE__, 'worker', $first, (string) $flow->id, (string) $field->id, $barrier, 'yes'], env: ['DB_SCHEMA' => $schema]);
            $contender = new Process([PHP_BINARY, __FILE__, 'worker', $first === $write ? 'delete' : $write, (string) $flow->id, (string) $field->id, $barrier, 'no'], env: ['DB_SCHEMA' => $schema]);
            foreach ([$holder, $contender] as $process) {
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $holder->wait();
            $contender->wait();
            templateVerify($holder->isSuccessful(), 'Holder failed: '.$holder->getErrorOutput());
            templateVerify($contender->isSuccessful(), 'Contender failed: '.$contender->getErrorOutput());
            templateVerify($holder->getOutput() === 'done' && $contender->getOutput() === 'blocked', 'Template save/deletion race bypassed protection');

            templateVerify($workspace->contactCustomFields()->whereKey($field->id)->exists() === ($first === $write), 'Referenced field disappeared');
            echo $write.' / '.$first." wins: competing operation blocked\n";
        }
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

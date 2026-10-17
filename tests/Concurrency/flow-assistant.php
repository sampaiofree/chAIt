<?php

use App\Models\Flow;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiOperation;
use App\Models\FlowAiRun;
use App\Models\User;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\FlowAssistant\DraftSessionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.($error instanceof RuntimeException && ! $error instanceof QueryException ? $error->getMessage() : 'Concurrency check failed')."\n");
    exit(1);
});
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'chait_test' || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Use APP_ENV=testing, PostgreSQL chait_test and an empty DB_URL.');
}
Http::preventStrayRequests();

function assistantVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assistantBarrier(string $path): void
{
    $deadline = microtime(true) + 10;
    while (! is_file($path)) {
        assistantVerify(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
}

if (($argv[1] ?? '') === 'worker') {
    assistantVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $id, $barrier, $marker, $other] = $argv;
    $flow = Flow::findOrFail($id);
    $drafts = app(DraftSessionService::class);
    $session = $drafts->current($flow);
    $data = ['base_revision' => $flow->revision, 'draft_revision' => $session->revision, 'generation' => $session->generation, 'graph' => $session->graph, 'name' => $marker];
    if ($action === 'deadlock') {
        $drafts->locked($flow, function (Flow $flow) use ($other, $barrier, $marker) {
            file_put_contents($barrier.'.'.$marker, 'attempt\n', FILE_APPEND);
            assistantBarrier($barrier);
            $second = Flow::whereKey($other)->lockForUpdate()->firstOrFail();
            $flow->update(['revision' => $flow->revision + 1]);
            $second->update(['revision' => $second->revision + 1]);
        });
        echo 'updated';
        exit(0);
    }
    touch($barrier.'.'.$marker);
    assistantBarrier($barrier);
    try {
        if ($action === 'patch') {
            $run = FlowAiRun::findOrFail($other);
            app(AssistantTools::class)->execute($flow, $run, 'same-call', 'apply_flow_patch', ['expected_draft_revision' => $session->revision, 'operations' => [
                ['op' => 'add_node', 'client_ref' => 'welcome', 'node_type' => 'content', 'data' => ['label' => 'Welcome', 'items' => [['id' => 'greeting', 'kind' => 'text', 'text' => 'Olá!']]], 'position' => ['x' => 10, 'y' => 180]],
                ['op' => 'connect', 'source_ref' => 'start', 'source_handle' => 'next', 'target_ref' => 'welcome'],
            ]]);
        } else {
            $drafts->update($flow, $data);
        }
        echo 'updated';
    } catch (HttpException $error) {
        assistantVerify($error->getStatusCode() === 409, 'Expected revision/generation conflict');
        echo 'conflict';
    }
    exit(0);
}

function assistantRace(string $action, array $flows, int $other = 0): array
{
    $barrier = sys_get_temp_dir().'/chait-assistant-'.bin2hex(random_bytes(10));
    $processes = [];
    try {
        foreach ($flows as $i => $flow) {
            $second = $action === 'deadlock' ? $flows[1 - $i]->id : $other;
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $flow->id, $barrier, 'worker'.$i, (string) $second], env: ['DB_SCHEMA' => config('database.connections.pgsql.search_path')]);
            $process->setTimeout(20);
            $process->start();
            $processes[] = $process;
        }
        foreach ($flows as $i => $flow) {
            assistantBarrier($barrier.'.worker'.$i);
        }
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            assistantVerify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
            $results[] = $process->getOutput();
        }
        if ($action === 'deadlock') {
            $attempts = 0;
            foreach ($flows as $i => $flow) {
                $attempts += substr_count(file_get_contents($barrier.'.worker'.$i), 'attempt');
            }
            assistantVerify($attempts === 3, 'Deadlock did not retry exactly once');
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($barrier.'*') as $path) {
            unlink($path);
        }
    }
}

$schema = 'chait_concurrency_'.bin2hex(random_bytes(12));
DB::statement('CREATE SCHEMA "'.$schema.'"');
config(['database.connections.pgsql.search_path' => $schema]);
putenv('DB_SCHEMA='.$schema);
DB::purge('pgsql');
try {
    assistantVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $graph = ['schema_version' => 2, 'nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início', 'description' => '']]], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
    $first = $workspace->flows()->create(['name' => 'First', 'revision' => 1, 'graph' => $graph]);
    $second = $workspace->flows()->create(['name' => 'Second', 'revision' => 1, 'graph' => $graph]);
    $drafts = app(DraftSessionService::class);
    $drafts->locked($first, fn ($flow) => $drafts->ensure($flow));
    $drafts->locked($second, fn ($flow) => $drafts->ensure($flow));
    $results = assistantRace('manual', [$first, $first]);
    sort($results);
    assistantVerify($results === ['conflict', 'updated'] && $drafts->current($first)->revision === 2, 'Concurrent edits overwrote a revision');
    assistantVerify(assistantRace('manual', [$first, $second]) === ['updated', 'updated'], 'Different flows blocked one another');
    assistantRace('deadlock', [$first, $second]);
    assistantVerify($first->fresh()->revision === 3 && $second->fresh()->revision === 3, 'Retry repeated or lost an increment');
    // The synthetic deadlock test advanced saved revisions; rebase the fixture sessions.
    foreach ([$first, $second] as $flow) {
        $drafts->current($flow)->update(['base_revision' => 3]);
    }
    WorkspaceOpenAiIntegration::create(['workspace_id' => $workspace->id, 'api_key' => 'sk-local-concurrency-fixture']);
    $conversation = FlowAiConversation::create(['workspace_id' => $workspace->id, 'flow_id' => $first->id, 'state' => 'building']);
    $session = $drafts->current($first);
    $plan = FlowAiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'kind' => 'plan', 'status' => 'approved', 'approved_at' => now(), 'content' => 'Welcome', 'generation' => $session->generation]);
    $conversation->update(['approved_plan_id' => $plan->id]);
    $run = FlowAiRun::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'generation' => $session->generation, 'model' => 'gpt-6.1-sol', 'purpose' => 'building', 'message_id' => $plan->id, 'status' => 'running', 'started_at' => now(), 'credential_fingerprint' => hash('sha256', 'sk-local-concurrency-fixture')]);
    assistantVerify(assistantRace('patch', [$first, $first], $run->id) === ['updated', 'updated'], 'Idempotent race failed');
    assistantVerify(FlowAiOperation::count() === 1 && count($drafts->current($first)->graph['nodes']) === 2, 'Repeated call duplicated a batch');
    assistantVerify(assistantRace('manual', [$first, $first]) === ['conflict', 'conflict'], 'Active AI allowed manual writes');
    $run->update(['status' => 'completed']);
    $session = $drafts->current($first);
    $save = ['base_revision' => 3, 'generation' => $session->generation, 'draft_revision' => $session->revision, 'name' => $session->name, 'graph' => $session->graph];
    $drafts->save($first->fresh(), $save);
    assistantVerify(count($first->fresh()->graph['nodes']) === 2 && ! $drafts->current($first), 'Shared save failed');
    $drafts->locked($first->fresh(), fn ($flow) => $drafts->ensure($flow));
    assistantVerify(assistantRace('patch', [$first, $first], $run->id) === ['conflict', 'conflict'], 'Late results changed a new generation');
    $session = $drafts->current($first);
    $drafts->discard($first->fresh(), ['base_revision' => 4, 'generation' => $session->generation, 'draft_revision' => $session->revision]);
    assistantVerify(! $drafts->current($first) && count($first->fresh()->graph['nodes']) === 2, 'Discard damaged the saved draft');
    echo "Flow assistant concurrency passed: revision conflicts, independent flows, deadlock retry, idempotency, AI lock, shared save and late-generation rejection.\n";
} finally {
    DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
}

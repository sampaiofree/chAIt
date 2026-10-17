<?php

use App\Jobs\ExpireFlowWait;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowContentButton;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowInboundProcessor;
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

function buttonVerify(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'worker') {
    buttonVerify((bool) preg_match('/\Achait_concurrency_[a-f0-9]{24}\z/', config('database.connections.pgsql.search_path')), 'Worker schema guard');
    [, , $action, $executionId, $barrier, $holder] = $argv;
    $execution = FlowExecution::with(['connection', 'thread'])->findOrFail($executionId);
    $wait = DB::table('flow_waits')->where('flow_execution_id', $executionId)->first();
    if ($wait) {
        Carbon::setTestNow(Carbon::parse($wait->expires_at)->addSeconds($action === 'expire' ? 1 : -1));
    }
    $operation = function () use ($action, $execution, $wait): void {
        if ($action === 'activate') {
            app(FlowRuntime::class)->run($execution->id);
        } elseif ($action === 'expire') {
            (new ExpireFlowWait($wait->id))->handle();
        } else {
            $selection = app(FlowContentButton::class)->replyId($execution, 'content', 'button');
            app(FlowInboundProcessor::class)->handle($execution->connection, $execution->thread,
                ['messageid' => 'concurrent-click', 'messageType' => 'ButtonsResponseMessage', 'buttonOrListid' => $selection]);
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
            buttonVerify(microtime(true) < $deadline, 'Barrier timeout');
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
    buttonVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Migrations failed');
    config(['services.uazapi.base_url' => 'https://provider.example.test']);
    Http::fake(['*/send/menu' => Http::response(['id' => 'sent', 'status' => 'Sent'])]);
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $field = $workspace->contactCustomFields()->create(['name' => 'Resposta', 'type' => 'text']);
    foreach ([['receive', 'activate'], ['activate', 'receive'], ['select', 'expire'], ['expire', 'select']] as [$first, $second]) {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'uazapi_connection_id' => $connection->id, 'status' => 'bot', 'human_opened_at' => null]);
        $graph = ['schema_version' => 2, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
            ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 200], 'data' => ['label' => 'Conteúdo', 'items' => [['id' => 'button', 'kind' => 'button', 'text' => 'Quer saber mais?', 'button' => ['kind' => 'reply', 'label' => 'Tenho interesse']]]]],
            ['id' => 'wait', 'type' => 'wait_reply', 'position' => ['x' => 0, 'y' => 400], 'data' => ['label' => 'Capturar', 'field_id' => $field->id, 'timeout_seconds' => 300]],
        ], 'edges' => [
            ['id' => 'begin', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input'],
            ['id' => 'next', 'source' => 'content', 'sourceHandle' => 'next', 'target' => 'wait', 'targetHandle' => 'input'],
        ], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
        $flow = $workspace->flows()->create(['name' => 'Race '.$first, 'graph' => $graph, 'revision' => 1]);
        $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
        $execution = app(FlowRuntime::class)->start($version, $contact->id, $thread->id, $connection, 'default');
        app(FlowRuntime::class)->run($execution->id);
        if (in_array($first, ['select', 'expire'], true)) {
            app(FlowRuntime::class)->run($execution->id);
        }
        $barrier = sys_get_temp_dir().'/chait-content-button-'.bin2hex(random_bytes(12));
        $barriers[] = $barrier;
        foreach ([[$first, 'yes'], [$second, 'no']] as [$action, $holder]) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $execution->id, $barrier, $holder], env: ['DB_SCHEMA' => $schema]);
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }
        foreach (array_slice($processes, -2) as $process) {
            $process->wait();
            buttonVerify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
        }
        $wait = DB::table('flow_waits')->where('flow_execution_id', $execution->id)->sole();
        $expected = $first === 'expire' ? 'timed_out' : 'replied';
        buttonVerify($wait->status === $expected, 'Wrong wait winner');
        $value = DB::table('contact_custom_field_values')->where('contact_id', $contact->id)->where('field_id', $field->id)->value('value');
        buttonVerify($value === ($expected === 'replied' ? 'Tenho interesse' : null), 'Wrong captured value');
        buttonVerify(($execution->fresh()->context['resume_handle'] ?? null) === ($expected === 'replied' ? 'replied' : 'not_replied'), 'Wrong transition');
        buttonVerify(! isset($execution->fresh()->context['early_button_reply']), 'Early reference leaked: '.$first);
        buttonVerify(DB::table('uazapi_webhook_receipts')->where('uazapi_connection_id', $connection->id)->where('event_type', 'content_reply')->where('event_id', 'concurrent-click')->count() === 1, 'Duplicate receipt');
        // Each iteration represents an independent contact event; reuse the synthetic provider ID only after cleanup.
        DB::table('uazapi_webhook_receipts')->where('event_type', 'content_reply')->delete();
    }
    echo "Content Button PostgreSQL concurrency: 4 races passed; one capture/transition per wait.\n";
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

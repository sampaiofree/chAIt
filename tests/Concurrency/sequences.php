<?php

// Standalone multi-process integration test. It must only run against a disposable PostgreSQL database.
use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\FlowExecution;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowRuntime;
use App\Services\Sequences\SequenceDefinitionService;
use App\Services\Sequences\SequenceEnrollmentService;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'chait_seq_test_')) {
    throw new RuntimeException('Use an isolated PostgreSQL database named chait_seq_test_* and APP_ENV=testing.');
}
Queue::fake();
function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
if (($argv[1] ?? '') === 'worker') {
    [$script, $worker, $mode, $threadId, $sequenceId, $barrier, $generation] = $argv;
    $deadline = microtime(true) + 15;
    while (! is_file($barrier)) {
        verify(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
    $thread = Thread::findOrFail($threadId);
    $sequence = Sequence::findOrFail($sequenceId);
    if ($mode === 'enroll') {
        app(SequenceEnrollmentService::class)->enroll($thread, $sequence, 'test', (string) str()->uuid());
    } elseif ($mode === 'due') {
        $id = AutomationContext::where('contact_id', $thread->contact_id)->value('current_enrollment_id');
        app(SequenceScheduler::class)->execute($id, (int) $generation);
    } else {
        app(FlowRuntime::class)->start($sequence->publishedVersion->steps()->first()->flowVersion, $thread->contact_id,
            $thread->id, $thread->uazapiConnection, 'keyword');
    }
    exit(0);
}
function race(array $modes, Thread $thread, array $sequences, int $generation = 0): void
{
    $barrier = sys_get_temp_dir().'/chait-sequence-barrier-'.bin2hex(random_bytes(8));
    $processes = [];
    try {
        foreach ($modes as $i => $mode) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $mode, (string) $thread->id, (string) $sequences[$i]->id, $barrier, (string) $generation]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        touch($barrier);
        foreach ($processes as $process) {
            $process->wait();
            verify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        @unlink($barrier);
    }
}
$user = User::factory()->create();
$workspace = $user->fresh()->currentWorkspace;
$connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
$contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
$thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
    'contact_id' => $contact->id, 'status' => 'bot', 'human_opened_at' => null]);
$graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []];
$flow = $workspace->flows()->create(['name' => 'Concurrency', 'graph' => $graph]);
$version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
$flow->update(['is_active' => true, 'published_version_id' => $version->id]);
$sequences = [];
foreach (['A', 'B'] as $name) {
    $sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => $name,
        'draft' => ['name' => $name, 'timezone' => 'UTC', 'steps' => [['schedule_type' => 'interval', 'delay_seconds' => 60, 'flow_id' => $flow->id]]]]);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $user, 1);
    $sequences[] = $sequence->refresh();
}
race(['start', 'start'], $thread, $sequences);
verify(FlowExecution::whereIn('status', ['running', 'waiting_reply', 'waiting_ai'])->count() === 1, 'Concurrent starts created multiple active flows');
app(FlowRuntime::class)->finish(FlowExecution::sole(), 'completed');
race(['enroll', 'enroll'], $thread, $sequences);
verify(SequenceEnrollment::whereNotIn('status', SequenceEnrollment::TERMINAL)->count() === 1, 'Concurrent enrollments left multiple current participations');
verify(SequenceEnrollment::where('status', 'replaced')->count() === 1, 'Replacement was not applied');
$current = SequenceEnrollment::findOrFail(AutomationContext::sole()->current_enrollment_id);
$current->update(['due_at' => now()->subMinute()]);
race(['due', 'due'], $thread, $sequences, $current->generation);
verify(FlowExecution::where('trigger_source', 'sequence')->count() === 1, 'Repeated due workers duplicated a stage');
app(FlowRuntime::class)->finish(FlowExecution::where('status', 'running')->sole(), 'completed');
app(SequenceEnrollmentService::class)->enroll($thread, $sequences[0], 'test', (string) str()->uuid());
$current = SequenceEnrollment::findOrFail(AutomationContext::sole()->current_enrollment_id);
$current->update(['due_at' => now()->subMinute()]);
race(['due', 'start'], $thread, $sequences, $current->generation);
verify(FlowExecution::where('status', 'running')->count() === 1, 'Keyword and sequence race created multiple active flows');
echo "PASS: exclusive flow, concurrent enrollment, duplicated due jobs, keyword/sequence race.\n";

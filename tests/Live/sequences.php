<?php

/** Explicit real-provider smoke test. Run only with a recipient authorized by the workspace owner. */
use App\Models\AutomationContext;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Services\Flows\FlowExecutionManager;
use App\Services\Flows\FlowRuntime;
use App\Services\Sequences\SequenceDefinitionService;
use App\Services\Sequences\SequenceEnrollmentService;
use App\Services\Sequences\SequenceScheduler;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$number = preg_replace('/\D/', '', $argv[1] ?? '');
if (strlen($number) < 10 || ! $app->environment('local')) {
    throw new RuntimeException('Supply an explicitly authorized test phone and use APP_ENV=local.');
}
Queue::fake(); // Execute only this enrollment manually; leave all other workspace jobs untouched.
$connection = UazapiConnection::findOrFail((int) ($argv[2] ?? 1));
$state = app(UazapiClient::class)->status($connection);
if (! data_get($state, 'status.connected')) {
    throw new RuntimeException('Test connection is not connected.');
}
$workspace = $connection->workspace;
$actor = $workspace->users()->whereIn('workspace_memberships.role', ['owner', 'admin'])->firstOrFail();
$contact = Contact::where('workspace_id', $workspace->id)->get()->first(fn ($candidate) => preg_replace('/\D/', '', $candidate->phone ?? '') === $number);
$contact ??= Contact::create(['workspace_id' => $workspace->id, 'phone' => '+'.$number, 'display_name' => 'Homologação Sequências']);
$thread = Thread::firstOrCreate(['workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id],
    ['external_chat_id' => $number.'@s.whatsapp.net', 'status' => 'bot']);
$manager = app(FlowExecutionManager::class);
$resumeId = (int) ($argv[3] ?? 0);
if ($resumeId) {
    $enrollment = SequenceEnrollment::where('workspace_id', $workspace->id)->where('contact_id', $contact->id)->findOrFail($resumeId);
    $sequence = $enrollment->sequence;
    if (! str_starts_with($sequence->name, 'Homologação Sequências ')) {
        throw new RuntimeException('Only this smoke test enrollment may be reconciled.');
    }
    $flows = Flow::whereIn('id', $enrollment->version->steps->map(fn ($step) => $step->flowVersion->flow_id))->get()->all();
    $execution = FlowExecution::findOrFail($enrollment->current_step_execution_id);
    $track = $execution->context['pending_track_id'] ?? null;
    if (! $track) {
        throw new RuntimeException('No pending tracked send to reconcile.');
    }
    $response = app(UazapiClient::class)->messages($connection, '', 0, $track);
    $message = $response['messages'][0] ?? null;
    if (! $message || ($message['track_id'] ?? null) !== $track || ! in_array(strtolower($message['status'] ?? ''), ['sent', 'delivered', 'read'], true)) {
        throw new RuntimeException('Provider did not confirm this tracked message; do not resend.');
    }
    $manager->locked($workspace->id, $connection->id, $contact->id, function ($context) use ($manager, $execution, $enrollment): void {
        if ($manager->blockers($context) || $context->current_enrollment_id) {
            throw new RuntimeException('Test context occupied.');
        }
        $execution->update(['status' => 'running', 'terminal_reason' => null, 'error_message' => null, 'finished_at' => null]);
        $enrollment->update(['status' => 'running_step', 'next_step_id' => $execution->sequence_step_id, 'terminal_reason' => null, 'finished_at' => null, 'generation' => $enrollment->generation + 1]);
        $context->update(['active_execution_id' => $execution->id, 'current_enrollment_id' => $enrollment->id]);
        app(SequenceEnrollmentService::class)->audit($enrollment, 'homologation_confirmation_reconciled', ['execution_id' => $execution->id]);
    });
} else {
    $manager->locked($workspace->id, $connection->id, $contact->id, function ($context) use ($manager): void {
        if ($manager->blockers($context) || $context->current_enrollment_id) {
            throw new RuntimeException('Test contact is occupied or already enrolled; no existing automation was replaced.');
        }
    });
    $stamp = now()->format('Ymd-His');
    $flows = [];
    foreach ([1, 2] as $position) {
        $graph = ['schema_version' => 2, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']],
            ['id' => 'content', 'type' => 'content', 'position' => ['x' => 0, 'y' => 150], 'data' => ['label' => 'Mensagem de teste',
                'items' => [['id' => 'test-message', 'kind' => 'text', 'text' => "chAIt — teste autorizado de Sequências. Etapa {$position}/2. ".($position === 1 ? 'Início por data específica.' : 'Intervalo de um minuto após o término da etapa anterior.')]]]],
        ], 'edges' => [['id' => 'start-content', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'content', 'targetHandle' => 'input']],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
        $flow = $workspace->flows()->create(['name' => "Homologação Sequências {$stamp} · {$position}", 'graph' => $graph]);
        $version = $flow->versions()->create(['workspace_id' => $workspace->id, 'version' => 1, 'graph' => $graph, 'published_by_user_id' => $actor->id, 'published_at' => now()]);
        $flow->update(['published_version_id' => $version->id, 'is_active' => true]);
        $flows[] = $flow;
    }
    $sequence = Sequence::create(['workspace_id' => $workspace->id, 'name' => 'Homologação Sequências '.$stamp,
        'draft' => ['name' => 'Homologação Sequências '.$stamp, 'timezone' => $workspace->timezone,
            'steps' => [['schedule_type' => 'specific_datetime', 'scheduled_at' => now($workspace->timezone)->format('Y-m-d\TH:i'), 'flow_id' => $flows[0]->id],
                ['schedule_type' => 'interval', 'delay_seconds' => 60, 'flow_id' => $flows[1]->id]]]]);
    app(SequenceDefinitionService::class)->publish($sequence, $workspace, $actor, 1);
    $enrollment = app(SequenceEnrollmentService::class)->enroll($thread, $sequence->refresh(), 'manual', 'live-test:'.$stamp, $actor)['enrollment'];
}
echo "Enrollment {$enrollment->id}: real-provider test started.\n";
$deadline = microtime(true) + 180;
$executed = [];
while (microtime(true) < $deadline) {
    $context = AutomationContext::where('current_enrollment_id', $enrollment->id)->first();
    if ($context) {
        app(SequenceScheduler::class)->reconcile($context);
    }
    $enrollment->refresh();
    if ($enrollment->status === 'completed') {
        echo "PASS: two sequential real-provider flows completed; enrollment {$enrollment->id}.\n";
        $sequence->update(['is_active' => false]);
        foreach ($flows as $flow) {
            $flow->update(['is_active' => false]);
        }
        exit(0);
    }
    if ($enrollment->status === 'failed' || $enrollment->status === 'replaced') {
        $sequence->update(['is_active' => false]);
        throw new RuntimeException('Test enrollment ended with '.$enrollment->status.'; do not blindly resend.');
    }
    if ($enrollment->status === 'ready') {
        app(SequenceScheduler::class)->execute($enrollment->id, $enrollment->generation);
    }
    $enrollment->refresh();
    if ($enrollment->current_step_execution_id) {
        app(FlowRuntime::class)->run($enrollment->current_step_execution_id);
        $execution = FlowExecution::find($enrollment->current_step_execution_id);
        if ($execution->status === 'completed' && ! isset($executed[$execution->id])) {
            echo 'Stage '.(count($executed) + 1)." completed.\n";
            $executed[$execution->id] = true;
        }
    }
    usleep(5000000);
}
$sequence->update(['is_active' => false]);
throw new RuntimeException('Test timed out. Inspect the durable enrollment before any further send.');

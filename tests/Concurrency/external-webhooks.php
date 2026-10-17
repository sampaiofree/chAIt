<?php

// Multi-process verification against an isolated PostgreSQL database only.
use App\Models\Contact;
use App\Models\ExternalWebhook;
use App\Models\ExternalWebhookReceipt;
use App\Models\User;
use App\Services\ExternalWebhooks\PayloadFields;
use App\Services\ExternalWebhooks\WebhookProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'chait_webhook_test_')
    || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Use APP_ENV=testing, DB_URL empty and a disposable PostgreSQL database named chait_webhook_test_*.');
}
function webhookVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
if (($argv[1] ?? '') === 'worker') {
    [, , $id, $barrier, $marker] = $argv;
    $deadline = microtime(true) + 15;
    while (! is_file($barrier)) {
        webhookVerify(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
    $webhook = ExternalWebhook::findOrFail($id);
    $payload = app(PayloadFields::class)->parse(json_encode(['phone' => '5527999999999', 'name' => $marker, 'custom' => $marker]));
    DB::transaction(function () use ($webhook, $payload, $marker): void {
        $processor = app(WebhookProcessor::class);
        $result = $processor->apply($webhook, $processor->prepare($webhook, $payload));
        // Hold the contact lock so the other worker must wait for a complete transaction.
        usleep(50000);
        ExternalWebhookReceipt::create(['workspace_id' => $webhook->workspace_id, 'external_webhook_id' => $webhook->id,
            'contact_id' => $result['contact_id'], 'status' => 'processed', 'error_code' => $marker]);
    }, 3);
    exit(0);
}
function webhookRace(array $webhooks, int $round): void
{
    $barrier = sys_get_temp_dir().'/chait-webhook-barrier-'.bin2hex(random_bytes(8));
    $processes = [];
    try {
        foreach ($webhooks as $i => $webhook) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', (string) $webhook->id, $barrier, "round{$round}worker{$i}"]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        touch($barrier);
        foreach ($processes as $process) {
            $process->wait();
            webhookVerify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
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
$field = $workspace->contactCustomFields()->create(['name' => 'Concurrency marker', 'type' => 'text']);
$tag = $workspace->tags()->create(['name' => 'Concurrency tag']);
$webhooks = [];
foreach ([1, 2] as $i) {
    $webhooks[] = ExternalWebhook::create(['workspace_id' => $workspace->id, 'name' => "Concurrency $i", 'endpoint_key' => bin2hex(random_bytes(32)), 'status' => 'active', 'payload_fields' => [],
        'mappings' => [
            ['source_path' => ['phone'], 'target_kind' => 'native', 'target' => 'phone'],
            ['source_path' => ['name'], 'target_kind' => 'native', 'target' => 'display_name'],
            ['source_path' => ['custom'], 'target_kind' => 'custom', 'target' => $field->id],
        ], 'tag_rules' => [['condition' => null, 'add' => [$tag->id], 'remove' => []]]]);
}
for ($round = 0; $round < 4; $round++) {
    webhookRace($webhooks, $round);
    $contact = Contact::where('workspace_id', $workspace->id)->sole();
    $lastCommit = ExternalWebhookReceipt::where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
    webhookVerify($contact->display_name === $lastCommit->error_code, 'Last successful transaction did not prevail');
    webhookVerify($contact->customFieldValues()->sole()->value === $contact->display_name, 'Contact and custom field updated partially');
    webhookVerify($contact->tags()->count() === 1, 'Duplicate or missing tag link');
}
echo "External webhook concurrency passed: one contact, atomic updates, last commit wins, one tag link.\n";

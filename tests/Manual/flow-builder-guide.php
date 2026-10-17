<?php

use App\Models\Contact;
use App\Models\ContactCustomFieldValue;
use App\Models\FlowAiMessage;
use App\Models\FlowAiRun;
use App\Models\FlowDraftSession;
use App\Models\User;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\FlowAssistant\AssistantTools;
use App\Services\FlowAssistant\DraftSessionService;
use App\Services\FlowAssistant\FlowAssistantService;
use App\Services\Flows\FlowCondition;
use Dotenv\Dotenv;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function guideVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function guideSourceFingerprint(): string
{
    return hash('sha256', json_encode([
        DB::table('flows')->orderBy('id')->get(['id', 'revision', 'graph', 'published_version_id']),
        DB::table('flow_draft_sessions')->orderBy('id')->get(['id', 'flow_id', 'generation', 'revision', 'graph']),
    ], JSON_THROW_ON_ERROR));
}

function guideRunSummary(FlowAiRun $run): array
{
    return $run->fresh()->only(['status', 'purpose', 'cycles', 'error']);
}

$report = ['started_at' => now()->toIso8601String(), 'passed' => false];
$reportPath = base_path('artifacts/assistente-ia-homologacao/flow-builder-guide.json');
$originalConnection = config('database.connections.pgsql');
$schema = null;
$exitCode = 1;

try {
    guideVerify(config('database.default') === 'pgsql' && $originalConnection['database'] === 'chait', 'Start from the local PostgreSQL chait configuration.');
    DB::beginTransaction();
    DB::statement('SET TRANSACTION READ ONLY');
    $key = WorkspaceOpenAiIntegration::where('workspace_id', (int) ($argv[1] ?? 1))->firstOrFail()->api_key;
    $sourceFingerprint = guideSourceFingerprint();
    DB::rollBack();

    $testing = Dotenv::parse(file_get_contents(base_path('.env.testing')));
    guideVerify(($testing['DB_CONNECTION'] ?? '') === 'pgsql' && ($testing['DB_DATABASE'] ?? '') === 'chait_test' && empty($testing['DB_URL']), 'The isolated check requires PostgreSQL chait_test and an empty DB_URL.');
    config(['database.connections.pgsql' => array_replace($originalConnection, [
        'host' => $testing['DB_HOST'], 'port' => $testing['DB_PORT'], 'database' => 'chait_test',
        'username' => $testing['DB_USERNAME'], 'password' => $testing['DB_PASSWORD'], 'url' => null, 'search_path' => 'public',
    ]), 'cache.default' => 'array', 'cache.limiter' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
    // Fortify may have resolved the limiter before the isolated cache configuration.
    $app->instance(RateLimiter::class, new RateLimiter(Cache::store('array')));
    Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(RateLimiter::class);
    DB::purge('pgsql');
    guideVerify(DB::selectOne('select current_database() as name')->name === 'chait_test', 'Database isolation check failed.');
    $schema = 'chait_guide_'.bin2hex(random_bytes(12));
    DB::statement('CREATE SCHEMA "'.$schema.'"');
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge('pgsql');
    guideVerify(Artisan::call('migrate', ['--force' => true]) === 0, 'Isolated migrations failed.');
    Queue::fake();
    Http::preventStrayRequests();
    Http::allowStrayRequests(['https://api.openai.com/v1/*']);

    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    WorkspaceOpenAiIntegration::create(['workspace_id' => $workspace->id, 'api_key' => $key]);
    unset($key);
    $field = $workspace->contactCustomFields()->create(['name' => 'Resposta de teste', 'type' => 'text']);
    $initialGraph = ['schema_version' => 2, 'nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Início']]], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
    $flow = $workspace->flows()->create(['name' => 'Triagem sintética do guia', 'revision' => 1, 'graph' => $initialGraph]);
    $assistant = app(FlowAssistantService::class);
    $drafts = app(DraftSessionService::class);
    $briefing = $assistant->start($flow, $user, ['base_revision' => 1, 'content' => 'Teste sintético: crie uma triagem sem bloco IA e sem Menu. Envie "Responda suporte ou compra." e colete texto no campo existente Resposta de teste, com prazo de 300 segundos. Compare por igualdade, primeiro suporte, depois compra. Se suporte, envie somente "SUPORTE-TESTE" e encerre; se compra, envie somente "COMPRA-TESTE" e encerre; se nenhuma opção corresponder, envie somente "INVALIDA-TESTE" e encerre. Se o prazo vencer, envie somente "PRAZO-TESTE" e encerre. Não repita perguntas, não abra atendimento humano e não use etiquetas. Todas as decisões estão definidas. Apresente o plano para Construir.']);
    $assistant->process($briefing->id);
    $report['briefing'] = guideRunSummary($briefing);
    echo json_encode(['phase' => 'briefing', ...$report['briefing']], JSON_UNESCAPED_UNICODE).PHP_EOL;
    guideVerify($briefing->fresh()->status === 'completed', 'The real model did not complete briefing.');
    $plan = FlowAiMessage::where('kind', 'plan')->where('status', 'pending')->sole();
    guideVerify($drafts->current($flow)->graph === $initialGraph, 'Briefing modified the preview.');

    // Simulate approval only in this disposable fixture, never in the source workspace.
    $session = $drafts->current($flow);
    $building = $assistant->start($flow, $user, ['base_revision' => 1, 'generation' => $session->generation, 'draft_revision' => $session->revision], $plan->id);
    $assistant->process($building->id);
    $report['building'] = guideRunSummary($building);
    echo json_encode(['phase' => 'building', ...$report['building']], JSON_UNESCAPED_UNICODE).PHP_EOL;
    guideVerify($building->fresh()->status === 'completed', 'The real model did not complete construction.');
    $graph = FlowDraftSession::sole()->graph;
    guideVerify(! collect($graph['nodes'])->contains(fn ($node) => in_array($node['type'], ['ai', 'menu', 'operations', 'jump_flow'], true)), 'Construction introduced an unrequested block.');
    $validation = app(AssistantTools::class)->validate($graph, $workspace);
    guideVerify($validation['pending'] === [], 'The real model left configuration pending.');
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $nodes = collect($graph['nodes'])->keyBy('id');
    $outcomes = [];
    $reached = [];
    foreach ([['suporte', 'replied', 'SUPORTE-TESTE'], ['compra', 'replied', 'COMPRA-TESTE'], ['banana', 'replied', 'INVALIDA-TESTE'], ['suporte', 'not_replied', 'PRAZO-TESTE']] as [$answer, $waitHandle, $expected]) {
        ContactCustomFieldValue::updateOrCreate(['contact_id' => $contact->id, 'field_id' => $field->id], ['workspace_id' => $workspace->id, 'value' => $answer]);
        $node = $nodes['start'];
        $visited = [];
        while (true) {
            guideVerify(! in_array($node['id'], $visited, true), 'A path contains a cycle.');
            $visited[] = $node['id'];
            if ($node['type'] === 'wait_reply') {
                guideVerify($node['data']['field_id'] === $field->id && $node['data']['timeout_seconds'] === 300, 'The collection configuration differs from the request.');
            }
            $handle = match ($node['type']) {
                'wait_reply' => $waitHandle,
                'condition' => app(FlowCondition::class)->evaluate($node['data'], $workspace, $contact->id) ? 'yes' : 'no',
                default => 'next',
            };
            $edge = collect($graph['edges'])->first(fn ($edge) => $edge['source'] === $node['id'] && $edge['sourceHandle'] === $handle);
            if (! $edge) {
                break;
            }
            $node = $nodes[$edge['target']];
        }
        guideVerify(collect($node['data']['items'] ?? [])->contains(fn ($item) => ($item['text'] ?? '') === $expected), 'A branch did not reach its requested terminal message.');
        $outcomes[$expected] = true;
        $reached = array_unique([...$reached, ...$visited]);
    }
    guideVerify(count($reached) === count($graph['nodes']), 'The model left unreachable nodes.');
    guideVerify($flow->fresh()->graph === $initialGraph && $flow->fresh()->published_version_id === null && $flow->versions()->count() === 0, 'The saved or published state was modified.');
    $report['outcomes'] = $outcomes;
    $report['nodes'] = count($graph['nodes']);
    $report['edges'] = count($graph['edges']);
    $report['passed'] = true;
    $exitCode = 0;
} catch (Throwable $error) {
    $report['error_class'] = get_class($error);
    if (get_class($error) === RuntimeException::class) {
        $report['error'] = $error->getMessage();
    }
    echo json_encode(['phase' => 'failed', 'error_class' => get_class($error)]).PHP_EOL;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($schema !== null) {
        DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        $report['isolated_schema_removed'] = true;
    }
    config(['database.connections.pgsql' => $originalConnection]);
    DB::purge('pgsql');
    if (isset($sourceFingerprint)) {
        $report['source_state_unchanged'] = hash_equals($sourceFingerprint, guideSourceFingerprint());
        if (! $report['source_state_unchanged']) {
            $report['passed'] = false;
            $exitCode = 1;
        }
    }
    $report['finished_at'] = now()->toIso8601String();
    if (! is_dir(dirname($reportPath))) {
        mkdir(dirname($reportPath), 0700, true);
    }
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo json_encode(['passed' => $report['passed'], 'isolated_schema_removed' => $report['isolated_schema_removed'] ?? false, 'source_state_unchanged' => $report['source_state_unchanged'] ?? null]).PHP_EOL;
}
exit($exitCode);

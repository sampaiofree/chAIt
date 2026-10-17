<?php

use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\Flows\AiActionService;
use App\Services\Flows\OpenAiClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\RequestException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$workspace = Workspace::sole();
$key = WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->firstOrFail()->api_key;
$client = app(OpenAiClient::class);
$path = base_path('artifacts/assistente-ia-homologacao/openai-smoke.json');
$report = ['workspace_id' => $workspace->id, 'started_at' => now()->toIso8601String(), 'models' => []];
foreach (['gpt-6.1-sol', 'gpt-6-luna', 'gpt-6-astra'] as $model) {
    $id = null;
    $result = ['model' => $model];
    try {
        $id = $client->createConversation($key);
        $result['conversation_id'] = $id;
        $response = $client->respond($key, $id, $model, 'Homologação: responda apenas com uma saudação curta em português. Não conclua o bloco.', [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Olá, este é um teste de integração.']]]], app(AiActionService::class)->tools([]));
        $outcome = $client->outcome($response);
        $result['response_status'] = $response['status'] ?? null;
        $result['text_present'] = $outcome['text'] !== '';
        $result['tool_count'] = count($outcome['calls']);
        $result['usage'] = $response['usage'] ?? null;
        $result['passed'] = $result['text_present'] && $result['tool_count'] === 0;
    } catch (Throwable $exception) {
        $result['passed'] = false;
        $result['error_class'] = get_class($exception);
        // Our adapter messages contain only controlled diagnostic labels.
        if ($exception instanceof RuntimeException && ! ($exception instanceof RequestException)) {
            $result['reason'] = $exception->getMessage();
        }
    } finally {
        $result['cleanup_completed'] = false;
        if ($id) {
            try {
                for ($page = 0; $page < 10; $page++) {
                    if ($client->cleanup($key, $id)) {
                        $result['cleanup_completed'] = true;
                        break;
                    }
                }
            } catch (Throwable $exception) {
                $result['cleanup_error_class'] = get_class($exception);
            }
        }
    }
    $report['models'][] = $result;
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['model' => $model, 'passed' => $result['passed'], 'cleanup_completed' => $result['cleanup_completed'], 'reason' => $result['reason'] ?? null]).PHP_EOL;
}

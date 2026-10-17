<?php

namespace App\Services\Flows;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient
{
    private function request(string $key, ?AiTurnBudget $budget = null): PendingRequest
    {
        return Http::baseUrl('https://api.openai.com/v1')->withToken($key)->acceptJson()->timeout($budget?->timeout(45) ?? 45);
    }

    public function createConversation(string $key, ?AiTurnBudget $budget = null): string
    {
        $response = $this->request($key, $budget)->post('/conversations', []);
        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Não foi possível iniciar a sessão OpenAI.');
        }

        return $response->json('id');
    }

    public function respond(string $key, string $conversationId, string $model, string $prompt, array $input, array $tools = [], ?AiTurnBudget $budget = null): array
    {
        $payload = [
            'model' => $model, 'conversation' => $conversationId,
            'instructions' => $prompt."\nResponda em texto com no máximo 3500 caracteres por mensagem.",
            'input' => $input,
            'tools' => array_merge([[
                'type' => 'function', 'name' => 'concluir_bloco_ia',
                'description' => 'Conclua o bloco somente quando o objetivo das instruções foi cumprido ou for necessário atendimento humano.',
                'parameters' => [
                    'type' => 'object', 'properties' => ['saida' => ['type' => 'string', 'enum' => ['continuar', 'atendimento_humanizado']]],
                    'required' => ['saida'], 'additionalProperties' => false,
                ],
                'strict' => true,
            ]], $tools),
            'parallel_tool_calls' => false,
            'max_output_tokens' => 1000,
        ];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->request($key, $budget)->post('/responses', $payload);
            if ($response->successful()) {
                $data = $response->json();
                if (! is_array($data)) {
                    throw new RuntimeException('Resposta OpenAI inválida.');
                }

                return $data;
            }
            if (! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === 2) {
                throw new RuntimeException('OpenAI indisponível ou acesso negado.');
            }
            usleep((int) (($budget?->timeout(0.5 * (2 ** $attempt)) ?? (0.5 * (2 ** $attempt))) * 1000000));
        }

        throw new RuntimeException('OpenAI indisponível.');
    }

    public function transcribe(string $key, string $bytes, string $filename, string $mime, ?AiTurnBudget $budget = null): string
    {
        $response = $this->request($key, $budget)->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post('/audio/transcriptions', ['model' => 'gpt-transcribe']);
        if (! $response->successful() || ! is_string($response->json('text'))) {
            throw new RuntimeException('Não foi possível transcrever o áudio.');
        }

        return $response->json('text');
    }

    public function cleanup(string $key, string $conversationId): bool
    {
        $response = $this->request($key)->get('/conversations/'.$conversationId.'/items', ['limit' => 20]);
        if ($response->status() === 404) {
            return true;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível listar o contexto remoto.');
        }
        $items = $response->json('data') ?? [];
        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            if (! $itemId) {
                continue;
            }
            $deleted = $this->request($key)->delete('/conversations/'.$conversationId.'/items/'.$itemId);
            if (! $deleted->successful() && $deleted->status() !== 404) {
                throw new RuntimeException('Não foi possível limpar um item remoto.');
            }
        }
        if ($items !== []) {
            return false;
        }
        $deleted = $this->request($key)->delete('/conversations/'.$conversationId);
        if (! $deleted->successful() && $deleted->status() !== 404) {
            throw new RuntimeException('Não foi possível limpar a conversa remota.');
        }

        return true;
    }

    public function outcome(array $response): array
    {
        $text = [];
        $calls = [];
        if (isset($response['status']) && $response['status'] !== 'completed') {
            throw new RuntimeException('Resposta OpenAI incompleta.');
        }
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $arguments = json_decode((string) ($item['arguments'] ?? ''), true);
                if (! is_array($arguments) || ! is_string($item['call_id'] ?? null) || strlen($item['call_id']) > 128 || $item['call_id'] === '') {
                    throw new RuntimeException('Chamada de ferramenta inválida.');
                }
                $calls[] = ['call_id' => $item['call_id'], 'name' => $item['name'] ?? '', 'arguments' => $arguments];
            }
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                        $text[] = $part['text'];
                    }
                }
            }
        }
        if (count($calls) > 1) {
            throw new RuntimeException('A IA solicitou ações paralelas.');
        }

        return ['text' => trim(implode("\n", $text)), 'calls' => $calls];
    }
}

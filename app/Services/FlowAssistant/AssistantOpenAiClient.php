<?php

namespace App\Services\FlowAssistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AssistantOpenAiClient
{
    public function availableModels(string $key): array
    {
        $response = Http::withToken($key)->acceptJson()->timeout(10)->get('https://api.openai.com/v1/models');
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível verificar os modelos da chave OpenAI.');
        }

        return array_values(array_intersect(config('flow-assistant.models'), array_column($response->json('data') ?? [], 'id')));
    }

    public function respond(string $key, string $model, string $instructions, array $input, array $tools, float $timeout): array
    {
        $response = Http::withToken($key)->acceptJson()->timeout(max(0.1, min(45, $timeout)))
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model, 'instructions' => $instructions, 'input' => $input, 'tools' => $tools,
                'parallel_tool_calls' => false, 'store' => false, 'include' => ['reasoning.encrypted_content'],
                'reasoning' => ['effort' => 'low'], 'max_output_tokens' => config('flow-assistant.max_output_tokens'),
            ]);
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'A chave OpenAI foi recusada. Confira a integração do workspace.',
                404 => 'O modelo escolhido está indisponível. Escolha outro modelo.',
                429 => 'A OpenAI limitou as solicitações. Tente retomar em instantes.',
                default => 'A OpenAI está indisponível. Os lotes já aplicados foram preservados.',
            });
        }
        $data = $response->json();
        if (! is_array($data) || ($data['status'] ?? 'completed') !== 'completed' || ! is_array($data['output'] ?? null)) {
            throw new RuntimeException('A OpenAI retornou uma resposta incompleta. Retome a partir da prévia atual.');
        }

        return $data['output'];
    }
}

<?php

namespace App\Services\FlowAssistant;

use App\Jobs\ProcessFlowAssistant;
use App\Models\Flow;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiRun;
use App\Models\User;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\Flows\FlowCapabilities;
use App\Services\Flows\FlowGraphValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FlowAssistantService
{
    public function __construct(private DraftSessionService $drafts, private AssistantAccess $access, private AssistantTools $tools, private AssistantOpenAiClient $openai) {}

    public function state(Flow $flow, User $user, ?int $before = null): array
    {
        $this->access->check($user, $flow);
        $conversation = FlowAiConversation::where('flow_id', $flow->id)->where('workspace_id', $flow->workspace_id)->first();
        $messages = $conversation ? FlowAiMessage::where('conversation_id', $conversation->id)->when($before, fn ($q) => $q->where('id', '<', $before))->with('author:id,name')->latest('id')->limit(100)->get()->reverse()->values() : collect();
        $run = $conversation ? FlowAiRun::where('conversation_id', $conversation->id)->latest('id')->first() : null;
        // Reconcile a terminated/lost worker without waiting for a new request to the model.
        if ($run && in_array($run->status, ['queued', 'running']) && ($run->started_at ?? $run->created_at)->addSeconds(210)->isPast()) {
            $this->pause($run->id, 'A execução foi interrompida. Você pode retomar a prévia.');
            $run->refresh();
        }
        $flow = $flow->fresh();
        $session = $this->drafts->current($flow);
        $run = $session && $run?->generation === $session->generation ? $run : null;

        return [
            'flow' => array_merge($flow->toArray(), ['graph' => app(FlowGraphValidator::class)->normalize($flow->graph)]), 'session' => $session ? array_merge($session->toArray(), ['graph' => app(FlowGraphValidator::class)->normalize($session->graph)]) : null, 'conversation' => $conversation?->fresh(), 'messages' => $messages,
            'run' => $run?->only(['id', 'status', 'purpose', 'model', 'error', 'started_at']),
            'can_use_ai' => $user->canUseFlowAssistant($flow->workspace),
            'configured' => WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->exists(),
            'catalog' => app(FlowCapabilities::class)->catalog($flow->workspace),
        ];
    }

    public function models(Flow $flow, User $user): array
    {
        $this->access->check($user, $flow, true);
        $integration = WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->first();
        abort_unless($integration, 422, 'Configure a chave OpenAI deste workspace.');
        $fingerprint = hash('sha256', $integration->api_key);
        try {
            return Cache::remember('flow-assistant-models:'.$flow->workspace_id.':'.$fingerprint, 300, fn () => $this->openai->availableModels($integration->api_key));
        } catch (\Throwable) {
            abort(503, 'Não foi possível consultar os modelos da OpenAI. Confira a chave e tente novamente.');
        }
    }

    private function prepare(Flow $flow, User $user, array $data): array
    {
        $this->access->check($user, $flow, true);
        abort_unless(RateLimiter::attempt('flow-assistant:'.$flow->workspace_id.':'.$user->id, 12, fn () => true, 60), 429, 'Aguarde um instante antes de enviar outra solicitação.');
        $conversation = FlowAiConversation::where('flow_id', $flow->id)->first();
        $preference = $data['model'] ?? $conversation?->model ?? 'auto';
        abort_unless($preference === 'auto' || in_array($preference, config('flow-assistant.models'), true), 422, 'Modelo não compatível.');
        $model = $preference === 'auto' ? config('flow-assistant.default_model') : $preference;
        abort_unless(in_array($model, $this->models($flow, $user), true), 422, 'O modelo escolhido não está disponível para esta chave. Escolha outro modelo.');

        return [$preference, $model];
    }

    public function start(Flow $flow, User $user, array $data, ?int $planId = null): FlowAiRun
    {
        [$preference, $model] = $this->prepare($flow, $user, $data);
        $run = $this->drafts->locked($flow, fn (Flow $flow) => $this->createRun($flow, $user, $data, $planId, $preference, $model));
        ProcessFlowAssistant::dispatch($run->id)->afterCommit();

        return $run->fresh();
    }

    // Only database work belongs here: the enclosing transaction may be retried.
    private function createRun(Flow $flow, User $user, array $data, ?int $planId, string $preference, string $model): FlowAiRun
    {
        $this->access->check($user, $flow, true);
        $session = $this->drafts->check($flow, $data) ?? $this->drafts->ensure($flow);
        $conversation = FlowAiConversation::firstOrCreate(['workspace_id' => $flow->workspace_id, 'flow_id' => $flow->id]);
        $hasApprovedPlan = $conversation->approved_plan_id !== null && FlowAiMessage::where('conversation_id', $conversation->id)->whereKey($conversation->approved_plan_id)->whereNotNull('approved_at')->whereIn('status', ['approved', 'saved', 'temporary'])->exists();
        $purpose = $hasApprovedPlan ? 'editing' : 'briefing';
        if ($planId !== null) {
            $plan = FlowAiMessage::where('conversation_id', $conversation->id)->whereKey($planId)->where('kind', 'plan')->where('status', 'pending')->where('generation', $session->generation)->firstOrFail();
            $plan->update(['approved_at' => now(), 'approved_by_user_id' => $user->id, 'status' => 'approved']);
            $purpose = 'building';
            $conversation->approved_plan_id = $plan->id;
        } else {
            FlowAiMessage::where('conversation_id', $conversation->id)->where('kind', 'plan')->where('status', 'pending')->update(['status' => 'superseded']);
        }
        $message = FlowAiMessage::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'user', 'content' => $planId ? 'Plano aprovado. Construa a prévia compartilhada.' : $this->tools->sanitize($data['content']), 'generation' => $session->generation]);
        $conversation->update(['model' => $preference, 'state' => $purpose]);

        return FlowAiRun::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'generation' => $session->generation, 'model' => $model, 'purpose' => $purpose, 'message_id' => $message->id, 'credential_fingerprint' => hash('sha256', WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->first()?->api_key ?? '')]);
    }

    public function stop(Flow $flow, int $id): void
    {
        $this->drafts->locked($flow, function (Flow $flow) use ($id) {
            $conversation = FlowAiConversation::where('flow_id', $flow->id)->firstOrFail();
            $run = FlowAiRun::where('conversation_id', $conversation->id)->findOrFail($id);
            if (in_array($run->status, ['queued', 'running'])) {
                $run->update(['status' => 'paused', 'finished_at' => now(), 'error' => 'Execução interrompida pela equipe.']);
                $conversation->update(['state' => 'paused_on_error']);
            }
        });
    }

    public function resume(Flow $flow, User $user, int $id, array $data): FlowAiRun
    {
        [$preference, $model] = $this->prepare($flow, $user, $data);
        // A resumed turn gets a new ID; external calls and dispatch never repeat on transaction retries.
        $run = $this->drafts->locked($flow, function (Flow $flow) use ($user, $id, $data, $preference, $model) {
            $conversation = FlowAiConversation::where('flow_id', $flow->id)->firstOrFail();
            $previous = FlowAiRun::where('conversation_id', $conversation->id)->findOrFail($id);
            $session = $this->drafts->check($flow, $data);
            abort_unless((int) FlowAiRun::where('conversation_id', $conversation->id)->latest('id')->value('id') === $previous->id && $session && $session->generation === $previous->generation && $previous->status === 'paused', 409, 'Esta execução não pode ser retomada.');

            return $this->createRun($flow, $user, array_merge($data, ['content' => 'Retome o pedido anterior a partir da prévia atual. Preserve os blocos existentes e conclua somente o que falta.']), null, $preference, $model);
        });
        ProcessFlowAssistant::dispatch($run->id)->afterCommit();

        return $run->fresh();
    }

    private function instructions(): string
    {
        $instructions = file_get_contents(resource_path('prompts/flow-assistant.md'));
        if ($instructions === false || trim($instructions) === '') {
            throw new RuntimeException('As instruções do assistente estão indisponíveis. Tente novamente após verificar a instalação.');
        }

        return $instructions;
    }

    private function context(Flow $flow, FlowAiRun $run): array
    {
        $conversation = FlowAiConversation::findOrFail($run->conversation_id);
        $plan = $conversation->approved_plan_id ? FlowAiMessage::where('conversation_id', $conversation->id)->whereKey($conversation->approved_plan_id)->whereNotNull('approved_at')->whereIn('status', ['approved', 'saved', 'temporary'])->value('content') : null;
        if (in_array($conversation->state, ['briefing', 'awaiting_plan_approval'])) {
            $plan = null;
        }
        $session = $this->drafts->current($flow);
        $context = ['briefing' => $conversation->briefing, 'approved_plan' => $plan, 'mode' => $run->purpose, 'current_flow' => ['name' => $session->name, 'revision' => $session->revision, 'graph' => app(FlowGraphValidator::class)->normalize($session->graph)]];
        $input = [['role' => 'developer', 'content' => 'Estado autoritativo do servidor: '.json_encode($this->tools->sanitize($context), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]];
        $messages = FlowAiMessage::where('conversation_id', $conversation->id)->whereIn('role', ['user', 'assistant'])->latest('id')->limit(config('flow-assistant.context_messages'))->get()->reverse();
        foreach ($messages as $message) {
            $input[] = ['role' => $message->role, 'content' => $this->tools->sanitize($message->content)];
        }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > config('flow-assistant.context_characters')) {
            throw new RuntimeException('O contexto deste fluxo está muito grande. Simplifique o pedido ou o fluxo antes de continuar.');
        }

        return $input;
    }

    public function process(int $id): void
    {
        $run = FlowAiRun::find($id);
        if (! $run || $run->status !== 'queued') {
            return;
        }
        $conversation = FlowAiConversation::find($run->conversation_id);
        $flow = $conversation ? Flow::find($conversation->flow_id) : null;
        if (! $flow) {
            return;
        }
        try {
            $claimed = $this->drafts->locked($flow, function (Flow $flow) use ($run) {
                $run->refresh();
                $session = $this->drafts->current($flow);
                if ($run->status !== 'queued' || ! $session || $session->generation !== $run->generation) {
                    return false;
                }
                $this->access->check(User::find($run->user_id), $flow, true);
                $run->update(['status' => 'running', 'started_at' => now()]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            $deadline = microtime(true) + config('flow-assistant.run_seconds');
            $input = $this->context($flow, $run);
            while (true) {
                $run->refresh();
                if ($run->status !== 'running') {
                    return;
                }
                $this->access->check(User::find($run->user_id), $flow, true);
                if ($run->cycles + 1 > config('flow-assistant.max_cycles') || microtime(true) >= $deadline) {
                    throw new RuntimeException('O limite técnico da execução foi atingido. Retome a partir da prévia atual.');
                }
                $integration = WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->first();
                if (! $integration) {
                    throw new RuntimeException('A chave OpenAI foi removida. Configure a integração para continuar.');
                }
                $keyVersion = hash('sha256', $integration->api_key);
                if (! hash_equals($run->credential_fingerprint ?? '', $keyVersion)) {
                    throw new RuntimeException('A chave OpenAI mudou. Retome com a nova integração.');
                }
                if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > config('flow-assistant.context_characters')) {
                    throw new RuntimeException('O contexto da execução atingiu o limite técnico. Retome a partir da prévia atual.');
                }
                $output = $this->openai->respond($integration->api_key, $run->model, $this->instructions(), $input, $this->tools->schemas(in_array($run->purpose, ['building', 'editing'])), $deadline - microtime(true));
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('O tempo da execução terminou. Os lotes já aplicados permanecem na prévia.');
                }
                $this->access->check(User::find($run->user_id), $flow, true);
                abort_unless(hash_equals($keyVersion, hash('sha256', WorkspaceOpenAiIntegration::where('workspace_id', $flow->workspace_id)->first()?->api_key ?? '')), 409, 'A chave OpenAI mudou durante a execução.');
                $accepted = $this->drafts->locked($flow, function (Flow $flow) use ($run) {
                    $run->refresh();
                    if ($run->status !== 'running' || $this->drafts->current($flow)?->generation !== $run->generation) {
                        return false;
                    }
                    $run->update(['cycles' => $run->cycles + 1]);

                    return true;
                });
                if (! $accepted) {
                    return;
                }
                $input = array_merge($input, $output);
                $calls = array_values(array_filter($output, fn ($item) => ($item['type'] ?? '') === 'function_call'));
                if (count($calls) > 1) {
                    throw new RuntimeException('A IA tentou executar ferramentas paralelas. Retome a prévia.');
                }
                if ($calls !== []) {
                    $call = $calls[0];
                    if (! is_string($call['call_id'] ?? null) || $call['call_id'] === '' || strlen($call['call_id']) > 128) {
                        throw new RuntimeException('Chamada de ferramenta inválida.');
                    }
                    $args = json_decode($call['arguments'] ?? '', true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($args)) {
                        throw new RuntimeException('Argumentos de ferramenta inválidos.');
                    }
                    $result = $this->tools->execute($flow, $run, $call['call_id'], $call['name'] ?? '', $args);
                    $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                    // Plan presentation is a final UX state, never a construction in the same turn.
                    if (($result['awaiting_approval'] ?? false) === true) {
                        $this->complete($flow, $run, null);

                        return;
                    }

                    continue;
                }
                $texts = [];
                foreach ($output as $item) {
                    if (($item['type'] ?? '') === 'message') {
                        foreach ($item['content'] ?? [] as $part) {
                            if (($part['type'] ?? '') === 'output_text') {
                                $texts[] = $part['text'];
                            }
                        }
                    }
                }
                $text = trim(implode("\n", $texts));
                if ($text === '') {
                    throw new RuntimeException('A OpenAI retornou uma resposta vazia. Tente retomar.');
                }
                $this->complete($flow, $run, $text);

                return;
            }
        } catch (\Throwable $error) {
            if ($error instanceof QueryException || $error instanceof \PDOException) {
                Log::warning('Flow assistant database failure', ['run_id' => $id, 'flow_id' => $flow->id, 'sqlstate' => $error->errorInfo[0] ?? (string) $error->getCode()]);
                $message = 'Não foi possível atualizar a prévia no banco. Os lotes confirmados foram preservados. Tente retomar.';
            } else {
                $message = $error instanceof ValidationException ? implode(' ', array_merge(...array_values($error->errors()))) : (($error instanceof RuntimeException || $error instanceof HttpException) ? $error->getMessage() : 'Falha no processamento do assistente. Os lotes confirmados foram preservados.');
            }
            $this->pause($id, mb_substr($this->tools->sanitize($message), 0, 250));
        }
    }

    private function complete(Flow $flow, FlowAiRun $run, ?string $text): void
    {
        $this->drafts->locked($flow, function (Flow $flow) use ($run, $text) {
            $run->refresh();
            if ($run->status !== 'running' || $this->drafts->current($flow)?->generation !== $run->generation) {
                return;
            }
            if ($text !== null) {
                FlowAiMessage::create(['conversation_id' => $run->conversation_id, 'role' => 'assistant', 'content' => $this->tools->sanitize($text), 'generation' => $run->generation]);
            }
            $run->update(['status' => 'completed', 'finished_at' => now(), 'provider_items' => null]);
            $conversation = FlowAiConversation::findOrFail($run->conversation_id);
            if ($conversation->state !== 'awaiting_plan_approval') {
                $conversation->update(['state' => $run->purpose === 'briefing' ? 'briefing' : 'editing']);
            }
        });
    }

    public function pause(int $id, string $error): void
    {
        $existing = FlowAiRun::find($id);
        $conversation = $existing ? FlowAiConversation::find($existing->conversation_id) : null;
        $flow = $conversation ? Flow::find($conversation->flow_id) : null;
        if (! $flow) {
            return;
        }
        $this->drafts->locked($flow, function () use ($id, $error) {
            $run = FlowAiRun::whereKey($id)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['queued', 'running'])) {
                return;
            }
            $run->update(['status' => 'paused', 'error' => $error, 'finished_at' => now(), 'provider_items' => null]);
            FlowAiConversation::whereKey($run->conversation_id)->update(['state' => 'paused_on_error']);
        });
    }
}

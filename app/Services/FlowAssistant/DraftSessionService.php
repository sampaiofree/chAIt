<?php

namespace App\Services\FlowAssistant;

use App\Models\Flow;
use App\Models\FlowAiConversation;
use App\Models\FlowAiMessage;
use App\Models\FlowAiRun;
use App\Models\FlowDraftSession;
use App\Models\Workspace;
use App\Services\Flows\ContactTemplate;
use App\Services\Flows\FlowGraphValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DraftSessionService
{
    public function locked(Flow $flow, callable $callback): mixed
    {
        return DB::transaction(function () use ($flow, $callback) {
            Workspace::whereKey($flow->workspace_id)->lockForUpdate()->firstOrFail();
            $flow = Flow::where('workspace_id', $flow->workspace_id)->whereKey($flow->id)->lockForUpdate()->firstOrFail();

            return $callback($flow);
        }, 3);
    }

    public function current(Flow $flow): ?FlowDraftSession
    {
        return FlowDraftSession::where('workspace_id', $flow->workspace_id)->where('flow_id', $flow->id)->first();
    }

    public function ensure(Flow $flow): FlowDraftSession
    {
        return $this->current($flow) ?? FlowDraftSession::create([
            'workspace_id' => $flow->workspace_id, 'flow_id' => $flow->id, 'generation' => (string) Str::uuid(),
            'name' => $flow->name, 'graph' => app(FlowGraphValidator::class)->normalize($flow->graph), 'base_revision' => $flow->revision, 'revision' => 1,
        ]);
    }

    public function activeRun(Flow $flow): ?FlowAiRun
    {
        $conversation = FlowAiConversation::where('workspace_id', $flow->workspace_id)->where('flow_id', $flow->id)->first();

        return $conversation ? FlowAiRun::where('conversation_id', $conversation->id)->whereIn('status', ['queued', 'running'])->latest('id')->first() : null;
    }

    public function check(Flow $flow, array $data, bool $allowRunning = false): ?FlowDraftSession
    {
        $session = $this->current($flow);
        abort_unless((int) ($data['base_revision'] ?? $data['revision'] ?? 0) === $flow->revision, 409, 'O rascunho salvo mudou. Recarregue o estado compartilhado.');
        if ($session) {
            abort_unless(($data['generation'] ?? null) === $session->generation && (int) ($data['draft_revision'] ?? 0) === $session->revision && $session->base_revision === $flow->revision, 409, 'A prévia compartilhada mudou. Recarregue ou reaplique suas alterações.');
        } else {
            abort_if(! empty($data['generation']), 409, 'Esta sessão foi salva ou descartada pela equipe.');
        }
        abort_if(! $allowRunning && $this->activeRun($flow), 409, 'A IA está trabalhando. Pare ou aguarde antes de editar.');

        return $session;
    }

    public function update(Flow $flow, array $data): FlowDraftSession
    {
        $graph = app(FlowGraphValidator::class)->validate($data['graph']);

        return $this->locked($flow, function (Flow $flow) use ($data, $graph) {
            $session = $this->check($flow, $data) ?? $this->ensure($flow);
            app(ContactTemplate::class)->references($graph, $flow->workspace, $session->graph);
            $session->update(['name' => $data['name'], 'graph' => $graph, 'revision' => $session->revision + 1]);

            return $session->fresh();
        });
    }

    public function save(Flow $flow, array $data): Flow
    {
        $graph = app(FlowGraphValidator::class)->validate($data['graph']);

        return $this->locked($flow, function (Flow $flow) use ($data, $graph) {
            $session = $this->check($flow, $data);
            if ($session) {
                // The locked session is authoritative. Browser hydration can add UI defaults;
                // these are never another write or a reason to replace the confirmed graph.
                $graph = app(FlowGraphValidator::class)->normalize($session->graph);
            }
            app(ContactTemplate::class)->references($graph, $flow->workspace, $session?->graph ?? $flow->graph);
            $flow->update(['name' => $session?->name ?? $data['name'], 'graph' => $graph, 'revision' => $flow->revision + 1]);
            $this->close($flow, $session, 'saved', $data['actor_user_id'] ?? null);

            return $flow->fresh();
        });
    }

    public function discard(Flow $flow, array $data): Flow
    {
        return $this->locked($flow, function (Flow $flow) use ($data) {
            $session = $this->check($flow, $data, true);
            $this->close($flow, $session, 'discarded', $data['actor_user_id'] ?? null);

            return $flow;
        });
    }

    /** Called with workspace/flow locks held by the import transaction. */
    public function replaceForImport(Flow $flow, array $expected, array $graph, int $actorId): Flow
    {
        $session = $this->check($flow, $expected);
        $flow->update(['graph' => $graph, 'revision' => $flow->revision + 1]);
        $conversation = FlowAiConversation::where('workspace_id', $flow->workspace_id)->where('flow_id', $flow->id)->first();
        if ($conversation) {
            FlowAiMessage::where('conversation_id', $conversation->id)->whereIn('status', ['temporary', 'approved', 'pending'])->update(['status' => 'superseded']);
            FlowAiRun::where('conversation_id', $conversation->id)->update(['provider_items' => null]);
            $conversation->update(['state' => 'briefing', 'approved_plan_id' => null]);
            FlowAiMessage::create(['conversation_id' => $conversation->id, 'user_id' => $actorId, 'role' => 'system', 'kind' => 'session', 'content' => 'A equipe importou um fluxo JSON e substituiu o rascunho salvo e a prévia compartilhada. Uma nova construção exige um novo plano.', 'status' => 'saved', 'generation' => $session?->generation]);
        }
        $session?->delete();

        return $flow->fresh();
    }

    private function close(Flow $flow, ?FlowDraftSession $session, string $status, ?int $actorId = null): void
    {
        if (! $session) {
            return;
        }
        $conversation = FlowAiConversation::where('flow_id', $flow->id)->first();
        if ($conversation) {
            FlowAiRun::where('conversation_id', $conversation->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'finished_at' => now(), 'provider_items' => null]);
            FlowAiRun::where('conversation_id', $conversation->id)->where('generation', $session->generation)->update(['provider_items' => null]);
            FlowAiMessage::where('conversation_id', $conversation->id)->where('generation', $session->generation)->whereIn('status', ['temporary', 'approved', 'pending'])->update(['status' => $status]);
            $approved = $conversation->approved_plan_id !== null && FlowAiMessage::where('conversation_id', $conversation->id)->whereKey($conversation->approved_plan_id)->whereNotNull('approved_at')->whereIn('status', ['approved', 'saved', 'temporary'])->exists();
            $conversation->update(['state' => $status === 'discarded' || ! $approved ? 'briefing' : 'editing', 'approved_plan_id' => $status === 'discarded' ? null : $conversation->approved_plan_id]);
            FlowAiMessage::create(['conversation_id' => $conversation->id, 'user_id' => $actorId, 'role' => 'system', 'kind' => 'session', 'content' => $status === 'saved' ? 'A equipe salvou a prévia no rascunho.' : 'A equipe descartou a prévia. O canvas voltou ao rascunho salvo.', 'status' => $status, 'generation' => $session->generation]);
        }
        $session->delete();
    }
}

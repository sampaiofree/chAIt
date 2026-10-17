<?php

namespace App\Services\FlowAssistant;

use App\Models\Flow;
use App\Models\User;

class AssistantAccess
{
    public function check(?User $user, Flow $flow, bool $useAi = false): void
    {
        abort_unless($user && $user->current_workspace_id === $flow->workspace_id, 403, 'Selecione o workspace deste fluxo.');
        // Never rely on relationships cached before a permission change.
        $user->unsetRelation('memberships');
        $workspace = $flow->workspace;
        abort_unless($user->canManageFlows($workspace) && $user->membershipFor($workspace)?->joined_at, 403, 'Você não tem permissão para editar este fluxo.');
        abort_if($useAi && ! $user->canUseFlowAssistant($workspace), 403, 'Você não tem permissão para utilizar o Assistente IA.');
    }
}

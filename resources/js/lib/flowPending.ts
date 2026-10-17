import { conditionCheckErrors } from '@/lib/flowCondition';
import type { ConditionCheck } from '@/lib/flowCondition';
import { menuErrors } from '@/lib/flowMenu';
import type { FlowMenuData } from '@/lib/flowMenu';

type Resources = {
    tags?: { id: number; name: string }[];
    fields?: { id: number; name: string; type?: string }[];
    members?: { id: number }[];
    flows?: { id: number }[];
    sequences?: { id: number }[];
};
export function flowPendingReasons(
    type: string,
    data: Record<string, unknown>,
    reference: Resources,
): string[] {
    const reasons = new Set<string>();
    const rows = (key: string) =>
        (data[key] || []) as Record<string, unknown>[];
    const exists = (kind: keyof Resources, id: unknown) =>
        reference[kind]?.some((resource) => resource.id === id);

    if (type === 'content') {
        if (!rows('items').length) {
            reasons.add('Adicione uma mensagem ou arquivo.');
        }

        for (const item of rows('items')) {
            if (item.kind === 'media' && !item.asset_id) {
                reasons.add('Anexe os arquivos pendentes.');
            }

            if (item.kind === 'text' && !String(item.text || '').trim()) {
                reasons.add('Preencha os textos das mensagens.');
            }

            if (item.kind === 'button') {
                const button = (item.button || {}) as Record<string, unknown>;

                if (
                    !String(item.text || '').trim() ||
                    !String(button.label || '').trim() ||
                    !button.kind ||
                    (button.kind === 'url' && !button.url) ||
                    (button.kind === 'copy' && !button.copy_text)
                ) {
                    reasons.add('Configure o botão e sua mensagem.');
                }
            }
        }
    }

    if (
        type === 'wait_reply' &&
        (!exists('fields', data.field_id) || !data.timeout_seconds)
    ) {
        reasons.add('Escolha o campo de destino e o prazo de resposta.');
    }

    if (type === 'jump_flow' && !exists('flows', data.target_flow_id)) {
        reasons.add('Selecione o fluxo de destino.');
    }

    if (type === 'operations') {
        if (!rows('actions').length) {
            reasons.add('Adicione uma operação.');
        }

        for (const action of rows('actions')) {
            if (
                ['add_tag', 'remove_tag'].includes(String(action.kind)) &&
                !exists('tags', action.tag_id)
            ) {
                reasons.add('Selecione a etiqueta da operação.');
            }

            if (
                action.kind === 'set_field' &&
                (!exists('fields', action.field_id) || action.value == null)
            ) {
                reasons.add('Selecione o campo e preencha seu valor.');
            }

            if (
                action.kind === 'notify_member' &&
                (!exists('members', action.user_id) || !action.text)
            ) {
                reasons.add(
                    'Selecione quem receberá a notificação e preencha a mensagem.',
                );
            }

            if (
                action.kind === 'add_to_sequence' &&
                !exists('sequences', action.sequence_id)
            ) {
                reasons.add('Selecione a sequência de destino.');
            }

            if (
                action.kind === 'pause_automation' &&
                (!action.pause_mode ||
                    (action.pause_mode === 'timed' && !action.pause_seconds))
            ) {
                reasons.add('Configure o período da pausa.');
            }
        }
    }

    if (type === 'ai') {
        if (!String(data.ai_prompt || '').trim()) {
            reasons.add('Preencha as instruções da IA.');
        }

        if (!data.ai_inactivity_seconds) {
            reasons.add('Configure o prazo de inatividade da IA.');
        }

        if (
            rows('ai_media').some((item) => !item.asset_id || !item.instruction)
        ) {
            reasons.add('Anexe as mídias da IA e preencha suas instruções.');
        }

        if (
            rows('ai_tags').some(
                (item) => !exists('tags', item.tag_id) || !item.instruction,
            )
        ) {
            reasons.add(
                'Configure as etiquetas autorizadas e suas instruções.',
            );
        }

        if (
            rows('ai_fields').some(
                (item) => !exists('fields', item.field_id) || !item.instruction,
            )
        ) {
            reasons.add('Configure os campos autorizados e suas instruções.');
        }
    }

    if (
        type === 'condition' &&
        (!['all', 'any'].includes(String(data.condition_operator)) ||
            !rows('checks').length ||
            rows('checks').some(
                (check) =>
                    Object.keys(
                        conditionCheckErrors(
                            check as ConditionCheck,
                            reference,
                        ),
                    ).length,
            ))
    ) {
        reasons.add('Complete as verificações da condição.');
    }

    if (
        type === 'menu' &&
        Object.keys(menuErrors(data as FlowMenuData)).length
    ) {
        reasons.add('Complete a configuração do menu.');
    }

    return [...reasons];
}

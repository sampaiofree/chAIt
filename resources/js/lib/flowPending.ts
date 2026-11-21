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
export function flowPendingIssues(
    type: string,
    data: Record<string, unknown>,
    reference: Resources,
) {
    const reasons = new Map<string, string>();
    function add(message: string, field: string) {
        if (!reasons.has(message)) reasons.set(message, field);
    }
    const rows = (key: string) =>
        (data[key] || []) as Record<string, unknown>[];
    const exists = (kind: keyof Resources, id: unknown) =>
        reference[kind]?.some((resource) => resource.id === id);

    if (type === 'content') {
        if (!rows('items').length) {
            add('Adicione uma mensagem ou arquivo.', 'content-add');
        }

        for (const [index, item] of rows('items').entries()) {
            if (item.kind === 'media' && !item.asset_id) {
                add('Anexe os arquivos pendentes.', `item-${index}-media`);
            }

            if (item.kind === 'text' && !String(item.text || '').trim()) {
                add('Preencha os textos das mensagens.', `item-${index}-text`);
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
                    add(
                        'Configure o botão e sua mensagem.',
                        `item-${index}-button`,
                    );
                }
            }
        }
    }

    if (
        type === 'wait_reply' &&
        (!exists('fields', data.field_id) || !data.timeout_seconds)
    ) {
        add(
            'Escolha o campo de destino e o prazo de resposta.',
            exists('fields', data.field_id) ? 'timeout_seconds' : 'field_id',
        );
    }

    if (type === 'jump_flow' && !exists('flows', data.target_flow_id)) {
        add('Selecione o fluxo de destino.', 'target_flow_id');
    }

    if (type === 'operations') {
        if (!rows('actions').length) {
            add('Adicione uma operação.', 'operations-add');
        }

        for (const [index, action] of rows('actions').entries()) {
            if (
                ['add_tag', 'remove_tag'].includes(String(action.kind)) &&
                !exists('tags', action.tag_id)
            ) {
                add('Selecione a etiqueta da operação.', `action-${index}`);
            }

            if (
                action.kind === 'set_field' &&
                (!exists('fields', action.field_id) || action.value == null)
            ) {
                add(
                    'Selecione o campo e preencha seu valor.',
                    `action-${index}`,
                );
            }

            if (
                action.kind === 'notify_member' &&
                (!exists('members', action.user_id) || !action.text)
            ) {
                add(
                    'Selecione quem receberá a notificação e preencha a mensagem.',
                    `action-${index}`,
                );
            }

            if (
                action.kind === 'add_to_sequence' &&
                !exists('sequences', action.sequence_id)
            ) {
                add('Selecione a sequência de destino.', `action-${index}`);
            }

            if (
                action.kind === 'pause_automation' &&
                (!action.pause_mode ||
                    (action.pause_mode === 'timed' && !action.pause_seconds))
            ) {
                add('Configure o período da pausa.', `action-${index}`);
            }
        }
    }

    if (type === 'ai') {
        if (
            rows('ai_outputs').some(
                (o) =>
                    !String(o.label || '').trim() ||
                    !String(o.instruction || '').trim(),
            )
        ) {
            add('Preencha o nome e as instruções de cada saída.', 'ai_outputs');
        }

        if (!String(data.ai_prompt || '').trim()) {
            add('Preencha as instruções da IA.', 'ai_prompt');
        }

        if (!data.ai_inactivity_seconds) {
            add(
                'Configure o prazo de inatividade da IA.',
                'ai_inactivity_seconds',
            );
        }

        if (
            rows('ai_media').some((item) => !item.asset_id || !item.instruction)
        ) {
            add(
                'Anexe as mídias da IA e preencha suas instruções.',
                'ai_media',
            );
        }

        if (
            rows('ai_tags').some(
                (item) => !exists('tags', item.tag_id) || !item.instruction,
            )
        ) {
            add(
                'Configure as etiquetas autorizadas e suas instruções.',
                'ai_tags',
            );
        }

        if (
            rows('ai_fields').some(
                (item) => !exists('fields', item.field_id) || !item.instruction,
            )
        ) {
            add(
                'Configure os campos autorizados e suas instruções.',
                'ai_fields',
            );
        }
    }

    if (type === 'condition') {
        const checks = rows('checks');
        const invalidCheck = checks.findIndex(
            (check) =>
                Object.keys(
                    conditionCheckErrors(check as ConditionCheck, reference),
                ).length,
        );
        if (!['all', 'any'].includes(String(data.condition_operator))) {
            add('Escolha como combinar as verificações.', 'condition-operator');
        }
        if (!checks.length) {
            add('Adicione uma verificação à condição.', 'condition-add');
        } else if (invalidCheck >= 0) {
            add(
                'Complete as verificações da condição.',
                `condition-check-${invalidCheck}`,
            );
        }
    }

    if (
        type === 'menu' &&
        Object.keys(menuErrors(data as FlowMenuData)).length
    ) {
        add('Complete a configuração do menu.', 'menu');
    }

    return Array.from(reasons, ([message, field]) => ({ message, field }));
}

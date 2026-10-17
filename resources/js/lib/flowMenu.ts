import type { FlowCatalog } from '@/composables/useFlowAssistant';

export type MenuOption = { id: string; label: string };
export type FlowMenuData = {
    menu_format?: 'numeric' | 'button' | 'list';
    question?: string;
    options?: MenuOption[];
    invalid_reply_text?: string;
    max_invalid_replies?: number;
    timeout_seconds?: number;
    list_button_text?: string;
};

/** Resolve the dynamic-output declaration supplied by the backend catalog. */
export function flowOutputs(
    catalog: FlowCatalog,
    type: string,
    data: FlowMenuData,
) {
    const definition = catalog.nodes.find((node) => node.type === type);
    const dynamic = definition?.dynamic_outputs;

    return [
        ...(dynamic
            ? (data.options || []).map((option, index) => ({
                  id: dynamic.prefix + option.id,
                  label: `${index + 1}. ${option.label || 'Opção sem rótulo'}`,
              }))
            : []),
        ...(definition?.outputs || []),
    ];
}

export function menuErrors(data: FlowMenuData): Record<string, string> {
    const errors: Record<string, string> = {};

    if (!data.question?.trim()) {
        errors.question = 'Preencha a pergunta para o contato.';
    }

    if (!data.options?.length) {
        errors.options = 'Adicione ao menos uma opção.';
    }

    for (const [index, option] of (data.options || []).entries()) {
        if (!option.label?.trim()) {
            errors[`options.${index}.label`] = 'Preencha o rótulo da opção.';
        } else if (/[|\r\n]/.test(option.label)) {
            errors[`options.${index}.label`] =
                'Use um rótulo de uma linha, sem o caractere |.';
        }
    }

    if (!data.invalid_reply_text?.trim()) {
        errors.invalid_reply_text =
            'Preencha a mensagem para resposta inválida.';
    }

    if (
        !Number.isInteger(data.timeout_seconds) ||
        (data.timeout_seconds || 0) < 60 ||
        (data.timeout_seconds || 0) > 604800
    ) {
        errors.timeout_seconds = 'Defina uma espera de 1 minuto a 7 dias.';
    }

    if (
        !Number.isInteger(data.max_invalid_replies) ||
        (data.max_invalid_replies || 0) < 1 ||
        (data.max_invalid_replies || 0) > 10
    ) {
        errors.max_invalid_replies = 'Escolha de 1 a 10 respostas inválidas.';
    }

    if (data.menu_format === 'list' && !data.list_button_text?.trim()) {
        errors.list_button_text = 'Preencha o texto do botão de abertura.';
    }

    return errors;
}

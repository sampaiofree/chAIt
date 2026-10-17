import {
    templateDisplay,
    hasContactFields,
    templateParts,
} from './contactTemplate';
import type { ContactTemplate } from './contactTemplate';
export type ContentButton = {
    kind?: 'url' | 'reply' | 'copy';
    label?: ContactTemplate;
    url?: ContactTemplate;
    copy_text?: ContactTemplate;
};
export type ContentButtonItem = {
    id: string;
    text?: ContactTemplate;
    button?: ContentButton;
};
export type ContentButtonProfile = {
    verified: boolean;
    max_text_length: number;
    max_label_length: number;
    max_value_length?: number;
    max_reply_id_length?: number;
};
export type ContentButtonCatalog = {
    max_per_content: number;
    kinds: string[];
    reply_help: string;
    profiles: Partial<
        Record<'url' | 'reply' | 'copy', ContentButtonProfile | null>
    >;
};

export function contentButtonErrors(
    item: ContentButtonItem,
    profile?: ContentButtonProfile | null,
): Record<string, string> {
    const errors: Record<string, string> = {};
    const literal = (value?: ContactTemplate) =>
        templateParts(value)
            .map((part) => (part.type === 'text' ? part.text : ''))
            .join('');
    const original = item.button || {};
    const button = {
        kind: original.kind,
        label: templateDisplay(original.label),
        url: templateDisplay(original.url),
        copy_text: templateDisplay(original.copy_text),
    };
    const text = templateDisplay(item.text);

    if (!text.trim()) {
        errors.text = 'Preencha o texto da mensagem.';
    }

    if (!button.kind) {
        errors.kind = 'Escolha URL, Resposta ou Copiar.';
    }

    if (!button.label?.trim()) {
        errors.label = 'Preencha o texto do botão.';
    }

    if (/[|\p{Cc}]/u.test(literal(original.label))) {
        errors.label = 'Use uma linha, sem o caractere |.';
    }

    if (button.kind === 'url' && !hasContactFields(original.url)) {
        try {
            const url = new URL(button.url || '');

            if (
                !['http:', 'https:'].includes(url.protocol) ||
                /[|\p{Cc}\s]/u.test(button.url || '')
            ) {
                throw new Error('invalid');
            }
        } catch {
            errors.url =
                'Informe um endereço completo com https:// ou http://.';
        }
    }

    if (button.kind === 'copy' && !button.copy_text?.trim()) {
        errors.copy_text = 'Preencha o texto para copiar.';
    }

    if (/[|\p{Cc}]/u.test(literal(original.copy_text))) {
        errors.copy_text = 'Use uma linha, sem o caractere |.';
    }

    if (
        literal(item.text).length >
        Math.min(4000, profile?.max_text_length ?? 4000)
    ) {
        errors.text = 'Texto acima do limite permitido.';
    }

    if (
        literal(original.label).length >
        Math.min(120, profile?.max_label_length ?? 120)
    ) {
        errors.label = 'Texto do botão acima do limite permitido.';
    }

    const field = button.kind === 'url' ? 'url' : 'copy_text';

    if (
        button.kind !== 'reply' &&
        literal(original[field]).length >
            Math.min(4000, profile?.max_value_length ?? 4000)
    ) {
        errors[field] = 'Destino acima do limite permitido.';
    }

    return errors;
}

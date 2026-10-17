export type ConditionCheck = {
    kind: 'tag' | 'field';
    tag_id?: number | null;
    field_id?: number | null;
    field_key?: string | null;
    operator?: 'has' | 'not_has' | 'equals' | 'contains' | null;
    value?: string | null;
};
export const standardConditionFields = [
    { key: 'display_name', label: 'Nome' },
    { key: 'email', label: 'E-mail' },
    { key: 'cpf', label: 'CPF' },
];
type References = {
    tags?: { id: number; name: string }[];
    fields?: { id: number; name: string; type?: string }[];
};
export function conditionCheckErrors(
    check: ConditionCheck,
    reference: References,
) {
    const errors: Record<string, string> = {};

    if (check.kind === 'tag') {
        if (!reference.tags?.some((tag) => tag.id === check.tag_id)) {
            errors.tag_id = 'Selecione uma etiqueta existente.';
        }

        if (!['has', 'not_has'].includes(check.operator || '')) {
            errors.operator = 'Escolha Possui ou Não possui.';
        }
    } else {
        if (
            Boolean(check.field_id) === Boolean(check.field_key) ||
            (check.field_id &&
                !reference.fields?.some(
                    (field) =>
                        field.id === check.field_id && field.type === 'text',
                )) ||
            (check.field_key &&
                !standardConditionFields.some(
                    (field) => field.key === check.field_key,
                ))
        ) {
            errors.field = 'Selecione um campo de texto existente.';
        }

        if (!['equals', 'contains'].includes(check.operator || '')) {
            errors.operator = 'Escolha É igual a ou Contém.';
        }

        if (!check.value?.trim() || Array.from(check.value).length > 4000) {
            errors.value = 'Preencha a expressão com até 4.000 caracteres.';
        }
    }

    return errors;
}
export function conditionCheckSummary(
    check: ConditionCheck,
    reference: References,
) {
    if (check.kind === 'tag') {
        const name =
            reference.tags?.find((tag) => tag.id === check.tag_id)?.name ||
            'Selecione uma etiqueta';

        return `${name} · ${check.operator === 'not_has' ? 'Não possui' : 'Possui'}`;
    }

    const name = check.field_id
        ? reference.fields?.find((field) => field.id === check.field_id)?.name
        : standardConditionFields.find((field) => field.key === check.field_key)
              ?.label;

    return `${name || 'Selecione um campo'} · ${check.operator === 'contains' ? 'Contém' : 'É igual a'}${check.value ? ` “${check.value}”` : ''}`;
}

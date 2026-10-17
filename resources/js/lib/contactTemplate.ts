export type ContactPart =
    | { type: 'text'; text: string }
    | { type: 'standard'; key: 'nome' | 'primeiro_nome' | 'telefone' }
    | { type: 'custom'; field_id: number };
export type ContactTemplate =
    | string
    | { template_version: 1; parts: ContactPart[] };
export type ContactFields = {
    standard: { key: 'nome' | 'primeiro_nome' | 'telefone'; label: string }[];
    custom: { id: number; name: string; type?: string }[];
};
export const standardFields: ContactFields['standard'] = [
    { key: 'nome', label: 'Nome' },
    { key: 'primeiro_nome', label: 'Primeiro nome' },
    { key: 'telefone', label: 'Telefone' },
];
export function templateParts(value?: ContactTemplate): ContactPart[] {
    return typeof value === 'object'
        ? value.parts
        : [{ type: 'text', text: value || '' }];
}
export function hasContactFields(value?: ContactTemplate): boolean {
    return templateParts(value).some((p) => p.type !== 'text');
}
export function partLabel(part: ContactPart, fields?: ContactFields): string {
    if (part.type === 'text') {
        return part.text;
    }

    if (part.type === 'standard') {
        return (
            standardFields.find((f) => f.key === part.key)?.label || part.key
        );
    }

    return (
        fields?.custom.find((f) => f.id === part.field_id)?.name ||
        `Campo indisponível #${part.field_id}`
    );
}
export function templateDisplay(
    value?: ContactTemplate,
    fields?: ContactFields,
): string {
    return templateParts(value)
        .map((p) => (p.type === 'text' ? p.text : `{${partLabel(p, fields)}}`))
        .join('');
}
export function normalizeTemplate(parts: ContactPart[]): ContactTemplate {
    const result: ContactPart[] = [];

    for (const p of parts) {
        const last = result.at(-1);

        if (p.type === 'text') {
            if (!p.text) {
                continue;
            }

            if (last?.type === 'text') {
                last.text += p.text;
                continue;
            }
        }

        result.push({ ...p });
    }

    return result.some((p) => p.type !== 'text')
        ? { template_version: 1, parts: result }
        : result.map((p) => (p.type === 'text' ? p.text : '')).join('');
}
export function templateUnits(value?: ContactTemplate): ContactPart[] {
    return templateParts(value).flatMap<ContactPart>((p) =>
        p.type === 'text'
            ? p.text.split('').map((text) => ({ type: 'text' as const, text }))
            : [p],
    );
}
export function validClipboardParts(
    value: unknown,
    fields: ContactFields,
): value is ContactPart[] {
    return (
        Array.isArray(value) &&
        value.length <= 10000 &&
        value.every(
            (p) =>
                p &&
                typeof p === 'object' &&
                ((p.type === 'text' &&
                    typeof p.text === 'string' &&
                    Object.keys(p).length === 2) ||
                    (p.type === 'standard' &&
                        standardFields.some((f) => f.key === p.key) &&
                        Object.keys(p).length === 2) ||
                    (p.type === 'custom' &&
                        Number.isInteger(p.field_id) &&
                        fields.custom.some((f) => f.id === p.field_id) &&
                        Object.keys(p).length === 2)),
        )
    );
}

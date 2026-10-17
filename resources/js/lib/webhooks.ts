export type PayloadField = {
    path: (string | number)[];
    label: string;
    type: string;
};
export type WebhookMapping = {
    source_path: (string | number)[];
    target_kind: 'native' | 'custom';
    target: string | number;
};
export type WebhookRule = {
    condition: {
        source_path: (string | number)[];
        value: string | number | boolean;
    } | null;
    add: number[];
    remove: number[];
};
export type WebhookReceipt = {
    id: number;
    status: string;
    error_code: string | null;
    warnings: { mapping: number; code: string }[] | null;
    duration_ms: number;
    created_at: string;
};
export type WebhookSnapshot = {
    id: number;
    name: string;
    status: 'test' | 'active';
    url: string;
    mappings: WebhookMapping[];
    tag_rules: WebhookRule[];
    payload_fields: PayloadField[];
    sample_payload: string | null;
    sample_received_at: string | null;
    capture_until: string | null;
    receipts: WebhookReceipt[];
};
export const receiptStates: Record<string, string> = {
    received: 'Recebido',
    simulated: 'Simulado',
    processed: 'Processado',
    ignored: 'Sem alterações',
    error: 'Erro',
};
export const errorCodes: Record<string, string> = {
    invalid_phone: 'Telefone ausente ou inválido',
    invalid_configuration: 'Configuração inválida',
    invalid_json: 'JSON inválido',
    unsupported_format: 'Formato não suportado',
    no_usable_fields: 'Nenhum campo utilizável',
    payload_too_large: 'JSON muito grande',
    payload_too_deep: 'JSON muito profundo',
    too_many_fields: 'Campos demais',
    unsupported_media_type: 'Content-Type incompatível',
    internal_error: 'Falha interna; tente novamente',
};
export class WebhookRequestError extends Error {
    constructor(
        message: string,
        public fields: Record<string, string> = {},
    ) {
        super(message);
    }
}
export async function webhookRequest<T>(
    url: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content || '',
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json();

    if (!response.ok) {
        const fields = Object.fromEntries(
            Object.entries(result.errors ?? {}).map(([key, values]) => [
                key,
                Array.isArray(values) ? String(values[0]) : String(values),
            ]),
        );

        throw new WebhookRequestError(
            result.message || 'Não foi possível concluir a ação.',
            fields,
        );
    }

    return result as T;
}

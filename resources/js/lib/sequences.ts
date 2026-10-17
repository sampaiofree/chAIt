export type SequenceSnapshot = {
    id: number;
    sequence_id: number;
    name: string;
    version: number;
    timezone: string;
    status: string;
    step_position: number | null;
    flow_name: string | null;
    due_at: string | null;
    remaining_seconds: number | null;
    blocked_reasons: string[];
    terminal_reason: string | null;
    updated_at: string;
    schedule_type: string | null;
};
export const sequenceStates: Record<string, string> = {
    waiting_current_flow: 'Aguardando fluxo atual',
    waiting_time: 'Aguardando horário',
    blocked: 'Aguardando liberação',
    ready: 'Pronta para iniciar',
    running_step: 'Em fluxo',
    completed: 'Concluída',
    replaced: 'Substituída',
    failed: 'Encerrada com erro',
};
export const blockerNames: Record<string, string> = {
    flow: 'Fluxo em andamento',
    human: 'Atendimento humano',
    manual: 'Automação pausada',
};
export function duration(seconds: number): string {
    const minutes = Math.ceil(seconds / 60);

    if (!minutes) {
        return 'Pronta para iniciar';
    }

    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;

    return [
        days ? `${days}d` : '',
        hours ? `${hours}h` : '',
        rest ? `${rest}min` : '',
    ]
        .filter(Boolean)
        .join(' ');
}
export class SequenceRequestError extends Error {
    constructor(
        message: string,
        public fields: Record<string, string> = {},
    ) {
        super(message);
    }
}
export async function sequenceRequest<T>(
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

        throw new SequenceRequestError(
            result.message || 'Não foi possível concluir a ação.',
            fields,
        );
    }

    return result as T;
}

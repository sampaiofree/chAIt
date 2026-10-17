import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref } from 'vue';

export type ChatUpdate = {
    type: string;
    workspace_id: number;
    thread_id?: number;
    data: any;
};
type Configuration = {
    enabled: boolean;
    key: string;
    host: string;
    port: number;
    scheme: string;
};

export function openChatRealtime(
    config: Configuration,
    userId: number,
    receive: (event: ChatUpdate) => void,
) {
    const connected = ref(false);
    const waiting = new Map<
        string,
        {
            resolve: (result: any) => void;
            reject: (error: Error) => void;
            timer: ReturnType<typeof setTimeout>;
        }
    >();
    const completed = new Map<string, any>();
    const chunks = new Map<string, any[]>();
    let readyResolve: (value: boolean) => void = () => {};
    const ready = new Promise<boolean>((resolve) => {
        readyResolve = resolve;
    });
    let disposed = false;
    const readyTimer = setTimeout(() => readyResolve(false), 10000);
    const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: config.key,
        wsHost: config.host,
        wsPort: Number(config.port),
        wssPort: Number(config.port),
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: {
            headers: {
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content || '',
            },
        },
    });
    const channel = echo.private(`chat.user.${userId}`);
    channel.subscribed(() => {
        if (!disposed) {
            connected.value = true;
        }

        clearTimeout(readyTimer);
        readyResolve(true);
    });
    channel.error(() => {
        connected.value = false;
        readyResolve(false);
    });
    (echo.connector as any).pusher.connection.bind(
        'state_change',
        ({ current }: { current: string }) => {
            if (current !== 'connected') {
                connected.value = false;
            }
        },
    );
    channel.listen('.chat.update', (event: ChatUpdate) => {
        if (disposed) {
            return;
        }

        if (event.type === 'operation.chunk') {
            const id = event.data.operation_id;
            chunks.set(id, [...(chunks.get(id) || []), ...event.data.items]);

            return;
        }

        if (event.type === 'operation.finished') {
            const value = event.data;

            if (chunks.has(value.operation_id)) {
                value.result.data = chunks.get(value.operation_id);
                chunks.delete(value.operation_id);
            }

            if (
                value.result.expected_messages !== undefined &&
                value.result.data?.length !== value.result.expected_messages
            ) {
                value.status = 'failed';
                value.result = {
                    message:
                        'A transferência do histórico foi interrompida. Reabra a conversa para consultar novamente.',
                };
            }

            const pending = waiting.get(value.operation_id);

            if (pending) {
                clearTimeout(pending.timer);
                waiting.delete(value.operation_id);
                pending.resolve(value);
            } else {
                completed.set(value.operation_id, value);

                if (completed.size > 100) {
                    completed.delete(completed.keys().next().value!);
                }
            }
        }

        receive(event);
    });

    return {
        connected,
        ready,
        async operation(id: string): Promise<any> {
            const value = completed.get(id);

            if (value) {
                completed.delete(id);

                return value;
            }

            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    waiting.delete(id);
                    chunks.delete(id);
                    reject(
                        new Error(
                            'A operação continua pendente. Não repita o envio; reabra a conversa para conferir.',
                        ),
                    );
                }, 210000);
                waiting.set(id, { resolve, reject, timer });
            });
        },
        close() {
            disposed = true;
            clearTimeout(readyTimer);
            readyResolve(false);
            connected.value = false;

            for (const pending of waiting.values()) {
                clearTimeout(pending.timer);
                pending.reject(new Error('A tela foi fechada.'));
            }

            waiting.clear();
            completed.clear();
            chunks.clear();
            echo.disconnect();
        },
    };
}

<script setup lang="ts">
import { openChatRealtime } from '@/lib/chatRealtime';
import AppButton from '@/components/AppButton.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

type Connection = {
    status: string;
    phone: string | null;
    connected_at: string | null;
    provider_available: boolean;
    last_checked_at: string | null;
    profile_name: string | null;
    profile_pic_url: string | null;
    last_disconnect_at: string | null;
    last_disconnect_reason: string | null;
    qr_code?: string | null;
    pairing_code?: string | null;
};

const page = usePage<any>();
const workspace = page.props.workspace;
const connection = ref<Connection | null>(null);
const qrCode = ref('');
const pairingCode = ref('');
const busy = ref(false);
const error = ref('');
let timer = 0;
const realtimeEnabled = !!page.props.chatRealtime?.enabled;
let realtime: ReturnType<typeof openChatRealtime> | undefined;
const root = `/workspaces/${workspace.id}`;

async function api(path: string, init: RequestInit = {}) {
    const headers = new Headers(init.headers);
    headers.set(
        'X-CSRF-TOKEN',
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content || '',
    );
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    if (
        realtimeEnabled &&
        init.method === 'POST' &&
        realtime &&
        !realtime.connected.value &&
        !(await realtime.ready)
    )
        throw new Error(
            'Aguarde o restabelecimento do tempo real antes de iniciar uma operação.',
        );
    const response = await fetch(path, {
        ...init,
        headers,
        credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok)
        throw new Error(
            data.message || 'Não foi possível consultar a conexão.',
        );
    if (
        realtimeEnabled &&
        response.status === 202 &&
        data.data?.operation_id &&
        realtime
    ) {
        const outcome = await realtime.operation(data.data.operation_id);
        if (outcome.status !== 'completed')
            throw new Error(
                outcome.result?.message || 'Operação não confirmada.',
            );
        return outcome.result;
    }
    return data;
}

async function refresh(diagnostic = false) {
    try {
        const result = await api(
            `${root}/uazapi/${realtimeEnabled ? (diagnostic ? 'diagnose' : 'connection') : 'status'}`,
            diagnostic && realtimeEnabled ? { method: 'POST', body: '{}' } : {},
        );
        connection.value = result.data;
        if (result.data?.status === 'connected') {
            qrCode.value = '';
            pairingCode.value = '';
        } else {
            qrCode.value = result.data?.qr_code || qrCode.value;
            pairingCode.value = result.data?.pairing_code || pairingCode.value;
        }
        error.value =
            result.data?.provider_available === false
                ? 'A Uazapi está temporariamente indisponível. O status exibido é o último conhecido.'
                : '';
    } catch (caught: any) {
        error.value = caught.message;
    }
}

async function connect() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await api(`${root}/uazapi/connect`, {
            method: 'POST',
            body: '{}',
        });
        connection.value = {
            ...(connection.value || {}),
            status: 'connecting',
            provider_available: true,
        } as Connection;
        qrCode.value = result.data.qr_code || '';
        pairingCode.value = result.data.pairing_code || '';
        await refresh();
    } catch (caught: any) {
        error.value = caught.message;
    } finally {
        busy.value = false;
    }
}

function date(value: string | null | undefined) {
    if (!value) return 'Não disponível';
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function status(value?: string | null) {
    return (
        (
            {
                connected: 'Conectado',
                connecting: 'Aguardando leitura do QR Code',
                disconnected: 'Desconectado',
                hibernated: 'Pausado',
            } as Record<string, string>
        )[value || ''] ||
        value ||
        'Não conectado'
    );
}

onMounted(async () => {
    if (realtimeEnabled) {
        realtime = openChatRealtime(
            page.props.chatRealtime,
            page.props.auth.user.id,
            (event) => {
                if (
                    event.workspace_id === workspace.id &&
                    event.type === 'access.changed'
                ) {
                    connection.value = null;
                    qrCode.value = '';
                    pairingCode.value = '';
                    error.value =
                        'Suas permissões mudaram. Reabra as configurações.';
                    return;
                }
                if (
                    event.workspace_id === workspace.id &&
                    event.type === 'connection.changed'
                ) {
                    connection.value = {
                        ...connection.value,
                        ...event.data,
                    } as Connection;
                    if (event.data.status === 'connected') {
                        qrCode.value = '';
                        pairingCode.value = '';
                    }
                }
            },
        );
        await realtime.ready;
    }
    await refresh();
    if (!realtimeEnabled)
        timer = window.setInterval(() => void refresh(), 5000);
});
onUnmounted(() => {
    window.clearInterval(timer);
    realtime?.close();
});
</script>

<template>
    <Head title="WhatsApp" />
    <SettingsLayout active="whatsapp">
        <section>
            <small class="workspace-name">{{ workspace.name }}</small>
            <h1>Conexão do WhatsApp</h1>
            <p>
                Conecte o número deste workspace para receber e responder
                conversas.
            </p>

            <article class="connection-card">
                <div class="connection-title">
                    <div>
                        <span
                            class="status-dot"
                            :class="
                                connection?.status === 'connected'
                                    ? 'online'
                                    : 'offline'
                            "
                        ></span>
                        <strong>{{ status(connection?.status) }}</strong>
                    </div>
                    <AppButton :disabled="busy" @click="connect">{{
                        busy
                            ? 'Aguarde…'
                            : connection?.status === 'connected'
                              ? 'Reconectar'
                              : 'Conectar com QR Code'
                    }}</AppButton>
                </div>
                <AppButton
                    v-if="realtimeEnabled"
                    :disabled="busy"
                    @click="refresh(true)"
                    >Verificar conexão</AppButton
                >
                <p v-if="realtimeEnabled" class="notice">
                    Status conhecido pela última confirmação. Atualização
                    automática por eventos.
                </p>
                <p v-if="error" class="notice">{{ error }}</p>
                <div class="details">
                    <div>
                        <small>Número</small
                        ><strong>{{
                            connection?.phone || 'Não disponível'
                        }}</strong>
                    </div>
                    <div>
                        <small>Nome do perfil</small
                        ><strong>{{
                            connection?.profile_name || 'Não disponível'
                        }}</strong>
                    </div>
                    <div>
                        <small>Conectado desde</small
                        ><strong>{{ date(connection?.connected_at) }}</strong>
                    </div>
                    <div>
                        <small>Última verificação</small
                        ><strong>{{
                            date(connection?.last_checked_at)
                        }}</strong>
                    </div>
                    <div>
                        <small>Última desconexão</small
                        ><strong>{{
                            date(connection?.last_disconnect_at)
                        }}</strong>
                    </div>
                    <div>
                        <small>Motivo informado</small
                        ><strong>{{
                            connection?.last_disconnect_reason ||
                            'Não disponível'
                        }}</strong>
                    </div>
                </div>
                <img
                    v-if="connection?.profile_pic_url"
                    class="instance-avatar"
                    :src="connection.profile_pic_url"
                    alt="Foto do perfil conectado"
                />
                <p
                    v-if="
                        connection?.status !== 'connected' &&
                        (qrCode || pairingCode)
                    "
                    class="pairing-help"
                >
                    Abra o WhatsApp no celular e escolha Dispositivos conectados
                    para concluir o pareamento.
                </p>
                <img
                    v-if="qrCode && connection?.status !== 'connected'"
                    class="qr-code"
                    :src="
                        qrCode.startsWith('data:')
                            ? qrCode
                            : `data:image/png;base64,${qrCode}`
                    "
                    alt="QR Code para conexão do WhatsApp"
                />
                <p
                    v-if="pairingCode && connection?.status !== 'connected'"
                    class="pairing-code"
                >
                    Código de pareamento: <strong>{{ pairingCode }}</strong>
                </p>
            </article>
        </section>
    </SettingsLayout>
</template>

<style scoped>
section {
    padding: 38px;
    max-width: 820px;
}
section h1 {
    font-size: 24px;
    margin: 6px 0;
}
section > p {
    color: var(--muted-foreground);
    font-size: 13px;
    line-height: 1.5;
}
.workspace-name {
    font-size: 10px;
    color: var(--muted-foreground);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.connection-card {
    position: relative;
    margin-top: 24px;
    padding: 22px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}
.connection-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}
.connection-title > div {
    display: flex;
    align-items: center;
    gap: 9px;
}
.connection-title strong {
    font-size: 14px;
}
.status-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--warning);
}
.status-dot.online {
    background: var(--success);
}
.details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    padding-top: 22px;
}
.details div {
    display: grid;
    gap: 5px;
}
.details small {
    font-size: 10px;
    color: var(--muted-foreground);
}
.details strong {
    font-size: 12px;
    color: var(--foreground);
    overflow-wrap: anywhere;
}
.notice {
    padding: 10px;
    background: var(--warning-soft);
    border-radius: 8px;
    color: var(--warning);
    font-size: 12px;
    line-height: 1.5;
}
.instance-avatar {
    width: 58px;
    height: 58px;
    margin-top: 20px;
    border-radius: 50%;
    object-fit: cover;
}
.qr-code {
    display: block;
    width: 200px;
    height: 200px;
    object-fit: contain;
    margin: 12px auto 0;
    padding: 8px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
}
.pairing-help {
    text-align: center;
    color: var(--muted-foreground);
    font-size: 12px;
}
.pairing-code {
    text-align: center;
    color: var(--muted-foreground);
    font-size: 12px;
}
@media (max-width: 650px) {
    section {
        padding: 24px 16px;
    }
    .details {
        grid-template-columns: 1fr;
    }
    .connection-title {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

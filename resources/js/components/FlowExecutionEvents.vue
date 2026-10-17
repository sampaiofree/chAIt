<script setup lang="ts">
import { ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
const props = defineProps<{ workspaceId: number; flowId: number }>();
type Event = {
    id: number;
    flow_execution_id: number;
    flow_version_id: number;
    event_type: string;
    node_id: string | null;
    created_at: string;
    metadata: {
        item_id?: string;
        field?: string;
        reason?: string;
        outcome?: string;
    };
};
const events = ref<Event[]>([]);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const before = ref<number | null>(null);
const labels: Record<string, string> = {
    send_failed: 'Envio ignorado',
    send_unknown: 'Resultado desconhecido',
    message_confirmed: 'Envio confirmado',
    transition: 'Próximo bloco',
    invalid_content: 'Conteúdo final inválido ou acima do limite',
    invalid_url: 'URL final inválida',
    transport_rejected: 'Envio recusado pelo provedor',
    result_unknown: 'Conciliação esgotada; não houve reenvio',
    asset_unavailable: 'Arquivo indisponível',
    compatibility_pending: 'Compatibilidade da conexão ainda não homologada',
};
async function load(reset = false) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const url = `/workspaces/${props.workspaceId}/flows/${props.flowId}/events${!reset && before.value ? `?before=${before.value}` : ''}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error();
        const result = await response.json();
        events.value = reset ? result.data : [...events.value, ...result.data];
        before.value = result.next_before;
        loaded.value = true;
    } catch {
        error.value = 'Não foi possível consultar as ocorrências.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <section class="execution-events" aria-label="Ocorrências das execuções">
        <h3>Ocorrências das execuções</h3>
        <AppButton variant="secondary" :disabled="busy" @click="load(true)">{{
            loaded ? 'Atualizar ocorrências' : 'Consultar ocorrências'
        }}</AppButton>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="loaded && !events.length">Nenhuma ocorrência registrada.</p>
        <article v-for="event in events" :key="event.id">
            <strong>{{ labels[event.event_type] || event.event_type }}</strong>
            <small
                >Execução #{{ event.flow_execution_id }} · Versão #{{
                    event.flow_version_id
                }}
                ·
                {{ new Date(event.created_at).toLocaleString('pt-BR') }}</small
            >
            <p v-if="event.node_id">
                Bloco: {{ event.node_id
                }}<template v-if="event.metadata.item_id">
                    · Item: {{ event.metadata.item_id }}</template
                ><template v-if="event.metadata.field">
                    · Campo: {{ event.metadata.field }}</template
                >
            </p>
            <p v-if="event.metadata.reason">
                {{ labels[event.metadata.reason] || event.metadata.reason }}
            </p>
        </article>
        <AppButton
            v-if="before"
            variant="secondary"
            :disabled="busy"
            @click="load()"
            >Carregar anteriores</AppButton
        >
    </section>
</template>
<style scoped>
.execution-events {
    margin-top: 1rem;
    display: grid;
    gap: 0.75rem;
    min-width: 0;
}
.execution-events h3 {
    font-size: 0.875rem;
    font-weight: 600;
}
.execution-events article {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.75rem;
    display: grid;
    gap: 0.25rem;
    overflow-wrap: anywhere;
    font-size: 0.75rem;
}
.execution-events small {
    color: var(--muted-foreground);
}
</style>

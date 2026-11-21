<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
defineProps<{
    requests: Array<{
        name: string;
        email: string;
        created_at: string;
        origin: string;
        workspaces: string[];
    }>;
}>();
</script>
<template>
    <AppLayout
        ><main class="requests">
            <h1>Solicitações de exclusão</h1>
            <p v-if="!requests.length">Nenhuma solicitação registrada.</p>
            <article v-for="request in requests" :key="request.email">
                <strong>{{ request.name }}</strong> — {{ request.email }}
                <p>
                    Solicitado em {{ request.created_at }} ·
                    {{ request.origin }}
                </p>
                <p>Workspaces: {{ request.workspaces.join(', ') }}</p>
            </article>
        </main></AppLayout
    >
</template>
<style scoped>
.requests {
    padding: var(--page-block-padding) var(--page-gutter);
    display: grid;
    gap: 16px;
    min-width: 0;
}
h1 {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 8px;
}
.requests > p,
article p {
    color: var(--text-secondary);
}
article {
    padding: var(--card-padding);
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
    background: var(--card);
    overflow-wrap: anywhere;
}
</style>

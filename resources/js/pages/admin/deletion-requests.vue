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
    padding: 32px;
}
article {
    padding: 16px;
    border-bottom: 1px solid var(--border);
}
</style>

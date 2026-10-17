<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{ workspaceId: number; active: 'keywords' | 'sequences' }>();
</script>
<template>
    <nav class="automation-tabs" aria-label="Automação">
        <Link
            :href="`/workspaces/${workspaceId}/flow-triggers`"
            :aria-current="active === 'keywords' ? 'page' : undefined"
            >Palavras-chave</Link
        >
        <Link
            :href="`/workspaces/${workspaceId}/sequences`"
            :aria-current="active === 'sequences' ? 'page' : undefined"
            >Sequências</Link
        >
    </nav>
</template>
<style scoped>
.automation-tabs {
    display: flex;
    gap: 24px;
    overflow-x: auto;
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
}
a {
    padding: 12px 0;
    white-space: nowrap;
    color: var(--muted-foreground);
    border-bottom: 2px solid transparent;
    font-size: 14px;
}
a[aria-current='page'] {
    color: var(--foreground);
    border-color: var(--primary);
    font-weight: 600;
}
</style>

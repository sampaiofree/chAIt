<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';

withDefaults(defineProps<{ label?: string }>(), { label: 'Carregando…' });
</script>

<template>
    <div class="app-loading" role="status" aria-live="polite">
        <LoaderCircle :size="18" class="app-loading-icon" aria-hidden="true" />
        <span>{{ label }}</span>
    </div>
</template>

<style scoped>
.app-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 20px 16px;
    color: var(--muted-foreground);
    font-size: 13px;
    line-height: 1.5;
}
.app-loading-icon {
    flex: none;
}
@media (prefers-reduced-motion: no-preference) {
    .app-loading-icon {
        animation: loading-spin 1s linear infinite;
    }
}
@keyframes loading-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>

<script setup lang="ts">
import SettingsNavigation from '@/components/SettingsNavigation.vue';
defineProps<{ active: string }>();
</script>
<template>
    <main class="settings-page">
        <SettingsNavigation :active="active" /><slot />
    </main>
</template>
<style scoped>
.settings-page {
    width: 100%;
    margin: 0 auto;
    padding: var(--page-block-padding) var(--page-gutter) 32px;
    min-width: 0;
}
.settings-page :deep(> section) {
    padding: 0;
    width: 100%;
    max-width: none;
    min-width: 0;
}
.settings-page :deep(h1) {
    font-size: 24px;
    line-height: 1.3;
    font-weight: 600;
    margin: 0 0 8px;
}
.settings-page :deep(section > p) {
    color: var(--text-secondary);
    font-size: 14px;
    line-height: 1.6;
    margin: 0 0 var(--section-gap);
}
.settings-page :deep(.settings-card) {
    padding: var(--card-padding);
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
    margin-top: var(--section-gap);
}
.settings-page :deep(.settings-card form) {
    margin-top: 0;
}
</style>

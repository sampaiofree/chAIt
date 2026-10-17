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
    width: min(1120px, 100%);
    margin: 0 auto;
    padding: 24px 40px 48px;
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
    color: var(--muted-foreground);
    font-size: 14px;
    line-height: 1.6;
    margin: 0 0 24px;
}
.settings-page :deep(.settings-card) {
    padding: 24px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    max-width: 720px;
    margin-top: 24px;
}
.settings-page :deep(.settings-card form) {
    margin-top: 0;
}
@media (max-width: 640px) {
    .settings-page {
        padding: 16px 16px 32px;
    }
    .settings-page :deep(.settings-card) {
        padding: 20px;
    }
}
</style>

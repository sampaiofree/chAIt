<script setup lang="ts">
defineProps<{ compact?: boolean }>();
</script>
<template>
    <span class="app-brand" :class="{ compact }" aria-label="chAIt"
        >ch<span>AI</span>t</span
    >
</template>
<style scoped>
.app-brand {
    display: inline-block;
    flex: none;
    white-space: nowrap;
    font-size: 25px;
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1;
    color: var(--foreground);
}
.app-brand span {
    color: var(--brand-highlight);
}
.app-brand.compact {
    font-size: 16px;
    letter-spacing: -0.7px;
}
</style>

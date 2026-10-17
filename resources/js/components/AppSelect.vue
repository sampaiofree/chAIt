<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
defineOptions({ inheritAttrs: false });
const model = defineModel<any>();
</script>
<template>
    <div class="native-select-wrap">
        <select v-model="model" v-bind="$attrs" data-slot="native-select">
            <slot /></select
        ><ChevronDown :size="16" aria-hidden="true" />
    </div>
</template>
<style scoped>
.native-select-wrap {
    position: relative;
    min-width: 0;
    width: 100%;
}
.native-select-wrap > select {
    appearance: none;
    width: 100%;
    min-height: var(--control-height);
    padding: 8px 32px 8px 12px;
    font-size: 14px;
    border: 1px solid var(--input);
    border-radius: var(--radius);
    background: var(--card);
    color: var(--foreground);
}
.native-select-wrap > svg {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--muted-foreground);
}
.native-select-wrap > select:focus-visible {
    border-color: var(--ring);
}
.native-select-wrap > select:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

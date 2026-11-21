<script setup lang="ts">
import { useId, useAttrs } from 'vue';
import { Info } from '@lucide/vue';
import {
    PopoverRoot,
    PopoverTrigger,
    PopoverPortal,
    PopoverContent,
    PopoverClose,
} from 'reka-ui';
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();
defineProps<{ label: string }>();
const descriptionId = useId();
</script>
<template>
    <span class="app-field-help" :class="attrs.class">
        <span v-if="attrs.id" :id="String(attrs.id)" class="sr-only"
            ><slot
        /></span>
        <span>{{ label }}</span>
        <PopoverRoot>
            <PopoverTrigger
                type="button"
                class="app-help-trigger"
                :aria-label="`Sobre ${label}`"
            >
                <Info :size="16" aria-hidden="true" />
            </PopoverTrigger>
            <PopoverPortal>
                <PopoverContent
                    class="app-help-content"
                    :side-offset="8"
                    :collision-padding="16"
                    :aria-label="label"
                    :aria-describedby="descriptionId"
                >
                    <div :id="descriptionId"><slot /></div>
                    <PopoverClose
                        class="app-help-close"
                        aria-label="Fechar ajuda"
                        >×</PopoverClose
                    >
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
    </span>
</template>
<style>
.app-field-help {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 500;
}
.app-help-trigger {
    display: inline-grid;
    place-items: center;
    min-width: 32px;
    min-height: 32px;
    cursor: pointer;
    color: var(--link, var(--primary));
    border-radius: 6px;
}
.app-help-trigger:focus-visible,
.app-help-close:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.app-help-content {
    z-index: 100;
    width: min(320px, calc(100vw - 32px));
    max-height: var(--reka-popover-content-available-height);
    overflow: auto;
    overflow-wrap: anywhere;
    padding: 14px 40px 14px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--popover, var(--card));
    color: var(--popover-foreground, var(--foreground));
    box-shadow: var(--shadow-popover);
    font-size: 13px;
    line-height: 1.5;
    font-weight: 400;
}
.app-help-close {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 32px;
    height: 32px;
    cursor: pointer;
}
@media (max-width: 1023px) {
    .app-help-close { width: 44px; height: 44px; }
    .app-help-content { padding-right: 48px; }
    .app-help-trigger {
        min-width: 44px;
        min-height: 44px;
    }
}
</style>

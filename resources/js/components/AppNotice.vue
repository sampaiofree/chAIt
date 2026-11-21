<script setup lang="ts">
import { onBeforeUnmount, useSlots, watch } from 'vue';
import { useAppNotifications } from '@/composables/useAppNotifications';
import type { NotificationVariant } from '@/composables/useAppNotifications';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{ variant?: NotificationVariant; duration?: number }>(),
    { variant: 'warning' },
);
const slots = useSlots();
const { add, dismiss } = useAppNotifications();
let id: number | undefined;
watch(
    () => [props.variant, props.duration],
    () => {
        if (id !== undefined) dismiss(id);
        id = add({
            variant: props.variant,
            duration: props.duration,
            content: () => slots.default?.() ?? [],
        });
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (id !== undefined) dismiss(id);
});
</script>
<template>
    <!-- Content is rendered in the shared floating notification region. -->
</template>

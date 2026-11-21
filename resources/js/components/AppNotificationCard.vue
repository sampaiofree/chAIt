<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { X, Info, CircleCheck, TriangleAlert, CircleAlert } from '@lucide/vue';
import type { AppNotification } from '@/composables/useAppNotifications';
import { useAppNotifications } from '@/composables/useAppNotifications';
const props = defineProps<{ notification: AppNotification }>();
const { dismiss } = useAppNotifications();
const icons = {
    info: Info,
    success: CircleCheck,
    warning: TriangleAlert,
    error: CircleAlert,
};
let timer: ReturnType<typeof setTimeout> | undefined;
let remaining = props.notification.duration;
let started = 0;
const hovered = ref(false);
const focused = ref(false);
function pause() {
    if (timer) {
        clearTimeout(timer);
        timer = undefined;
        remaining -= Date.now() - started;
    }
}
function resume() {
    if (
        hovered.value ||
        focused.value ||
        props.notification.duration === 0 ||
        timer
    )
        return;
    started = Date.now();
    timer = setTimeout(
        () => dismiss(props.notification.id),
        Math.max(0, remaining),
    );
}
onMounted(resume);
onBeforeUnmount(pause);
</script>
<template>
    <article
        class="app-notification"
        :class="`app-notification--${notification.variant}`"
        :role="notification.variant === 'error' ? 'alert' : 'status'"
        @mouseenter="
            hovered = true;
            pause();
        "
        @mouseleave="
            hovered = false;
            resume();
        "
        @focusin="
            focused = true;
            pause();
        "
        @focusout="
            focused = !!(
                $event.relatedTarget && $el.contains($event.relatedTarget)
            );
            resume();
        "
    >
        <component
            :is="icons[notification.variant]"
            :size="18"
            aria-hidden="true"
        />
        <div class="app-notification-body">
            <component
                :is="notification.content"
                v-if="notification.content"
            /><template v-else>{{ notification.message }}</template>
        </div>
        <button
            type="button"
            class="app-notification-close"
            aria-label="Fechar notificação"
            @click="dismiss(notification.id)"
        >
            <X :size="18" />
        </button>
    </article>
</template>

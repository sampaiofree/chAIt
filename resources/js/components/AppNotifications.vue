<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import AppNotificationCard from '@/components/AppNotificationCard.vue';
import { useAppNotifications } from '@/composables/useAppNotifications';
const { notifications } = useAppNotifications();
const target = ref<HTMLElement | string>('body');
let observer: MutationObserver | undefined;
onMounted(() => {
    const update = () => {
        const dialogs = [
            ...document.querySelectorAll<HTMLElement>(
                '[data-slot="dialog-content"][data-state="open"], [data-slot="alert-dialog-content"][data-state="open"]',
            ),
        ];
        target.value = dialogs.at(-1) ?? 'body';
    };
    observer = new MutationObserver(update);
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-state'],
    });
    update();
});
onBeforeUnmount(() => observer?.disconnect());
</script>
<template>
    <Teleport :to="target"
        ><section
            class="app-notifications"
            aria-label="Notificações"
            tabindex="-1"
        >
            <AppNotificationCard
                v-for="notification in notifications"
                :key="notification.id"
                :notification="notification"
            /></section
    ></Teleport>
</template>
<style>
.app-notifications {
    position: fixed;
    z-index: 110;
    top: calc(
        var(--visible-viewport-top, 0px) + max(72px, env(safe-area-inset-top))
    );
    right: max(16px, env(safe-area-inset-right));
    width: min(380px, calc(100vw - 32px));
    max-height: calc(var(--visible-viewport-height, 100dvh) - 100px);
    overflow-y: auto;
    display: grid;
    gap: 8px;
    pointer-events: none;
}
.app-notification {
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--card);
    color: var(--foreground);
    box-shadow: var(--shadow-popover);
    font-size: 13px;
    line-height: 1.5;
}
.app-notification > svg {
    flex: none;
    margin-top: 3px;
    color: var(--primary);
}
.app-notification--warning > svg {
    color: var(--warning);
}
.app-notification--error > svg {
    color: var(--destructive);
}
.app-notification-body {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.app-notification-body button,
.app-notification-body a {
    text-decoration: underline;
    cursor: pointer;
}
.app-notification-close {
    flex: none;
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    cursor: pointer;
    border-radius: 6px;
}
.app-notification-close:focus-visible {
    outline: 2px solid var(--ring);
}
@media (max-width: 1023px) {
    .app-notifications {
        left: 16px;
        right: 16px;
        width: auto;
    }
    .app-notification-close {
        width: 44px;
        height: 44px;
    }
}
</style>

<style>
[role='dialog'] > .app-notifications,
[role='alertdialog'] > .app-notifications {
    position: absolute;
    top: 12px;
    right: 12px;
    left: 12px;
    width: auto;
    max-height: calc(100% - 24px);
}
</style>

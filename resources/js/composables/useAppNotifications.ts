import { shallowReactive } from 'vue';
import type { VNode } from 'vue';
export type NotificationVariant = 'info' | 'success' | 'warning' | 'error';
export type AppNotification = {
    id: number;
    variant: NotificationVariant;
    message?: string;
    content?: () => VNode[];
    duration: number;
};
const notifications = shallowReactive<AppNotification[]>([]);
let nextId = 0;
function dismiss(id: number) {
    const index = notifications.findIndex((item) => item.id === id);

    if (index >= 0) {
notifications.splice(index, 1);
}
}
function notify(
    message: string,
    options: { variant?: NotificationVariant; duration?: number } = {},
) {
    return add({
        message,
        variant: options.variant ?? 'info',
        duration: options.duration,
    });
}
function add(
    options: Omit<AppNotification, 'id' | 'duration'> & { duration?: number },
) {
    const id = ++nextId;
    notifications.push({
        ...options,
        id,
        duration:
            options.duration ??
            (['warning', 'error'].includes(options.variant) ? 0 : 5000),
    });

    return id;
}
export function useAppNotifications() {
    return { notifications, notify, add, dismiss };
}

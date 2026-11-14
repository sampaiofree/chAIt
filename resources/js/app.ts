import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { clearChatCache } from '@/lib/chatCache';

const cacheChannel =
    typeof BroadcastChannel !== 'undefined'
        ? new BroadcastChannel('chait-chat-cache')
        : null;
cacheChannel?.addEventListener('message', () => void clearChatCache());
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (
        !(form instanceof HTMLFormElement) ||
        new URL(form.action).pathname !== '/logout'
    ) {
        return;
    }

    event.preventDefault();
    cacheChannel?.postMessage('clear');
    void clearChatCache().finally(() => form.submit());
});

const updateVisibleViewport = () => {
    const viewport = window.visualViewport;
    const root = document.documentElement;
    root.style.setProperty(
        '--visible-viewport-height',
        `${viewport?.height ?? window.innerHeight}px`,
    );
    root.style.setProperty(
        '--visible-viewport-top',
        `${viewport?.offsetTop ?? 0}px`,
    );
};
updateVisibleViewport();
window.visualViewport?.addEventListener('resize', updateVisibleViewport);
window.visualViewport?.addEventListener('scroll', updateVisibleViewport);
window.addEventListener('resize', updateVisibleViewport);

const appName = import.meta.env.VITE_APP_NAME || 'chAIt';
const pages = import.meta.glob<{ default: any }>('./pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page ${name} not found`);
        }

        const component = (await page()).default;

        if (
            !['welcome', 'support', 'legal'].includes(name) &&
            !name.startsWith('auth/')
        ) {
            component.layout = AppLayout;
        }

        return component;
    },
    setup({ el, App, props, plugin }) {
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
        let appearance =
            document.documentElement.dataset.appearance || 'system';
        const applyAppearance = () => {
            document.documentElement.classList.toggle(
                'dark',
                appearance === 'dark' ||
                    (appearance === 'system' && systemTheme.matches),
            );
        };

        applyAppearance();
        systemTheme.addEventListener('change', applyAppearance);
        router.on('navigate', (event) => {
            appearance = String(event.detail.page.props.appearance || 'system');
            applyAppearance();
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
        document.getElementById('app-startup')?.remove();
    },
    progress: { color: '#16a34a' },
});

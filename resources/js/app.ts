import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

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

        if (name !== 'welcome' && !name.startsWith('auth/')) {
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
    },
    progress: { color: '#16a34a' },
});

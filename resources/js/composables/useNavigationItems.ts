import { usePage } from '@inertiajs/vue3';
import {
    MessagesSquare,
    UsersRound,
    Workflow,
    Timer,
    Settings,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';

export function useNavigationItems() {
    const page = usePage<any>();
    const workspace = computed(
        () => page.props.currentWorkspace ?? page.props.workspace,
    );
    const items = computed(() => [
        ...(page.props.canViewLeads && workspace.value
            ? [
                  {
                      id: 'leads',
                      title: 'Leads',
                      mobileTitle: 'Leads',
                      href: `/workspaces/${workspace.value.id}/leads`,
                      icon: UsersRound,
                      active: /\/leads(?:\/|\?|$)/.test(page.url),
                  },
              ]
            : []),
        ...(page.props.canViewChat
            ? [
                  {
                      id: 'conversations',
                      title: 'Conversas',
                      mobileTitle: 'Conversas',
                      href: '/dashboard',
                      icon: MessagesSquare,
                      active: page.url.startsWith('/dashboard'),
                  },
              ]
            : []),
        ...(page.props.canManageFlows && workspace.value
            ? [
                  {
                      id: 'flows',
                      title: 'Fluxos',
                      mobileTitle: 'Fluxos',
                      href: `/workspaces/${workspace.value.id}/flows`,
                      icon: Workflow,
                      active: /\/flows(?:\/|\?|$)/.test(page.url),
                  },
                  {
                      id: 'automation',
                      title: 'Automação',
                      mobileTitle: 'Automação',
                      href: `/workspaces/${workspace.value.id}/flow-triggers`,
                      icon: Timer,
                      active: /\/flow-triggers|\/sequences(?:\/|\?|$)/.test(
                          page.url,
                      ),
                  },
              ]
            : []),
        {
            id: 'settings',
            title: 'Configurações',
            mobileTitle: 'Ajustes',
            href: '/settings/profile',
            icon: Settings,
            active:
                page.url.startsWith('/settings/') ||
                /\/workspaces\/\d+\/webhooks(?:\/|\?|$)/.test(page.url),
        },
        ...(page.props.isSystemAdmin
            ? [
                  {
                      id: 'admin',
                      title: 'Administração',
                      mobileTitle: 'Administração',
                      href: '/admin/usuarios',
                      icon: ShieldCheck,
                      active: page.url.startsWith('/admin/'),
                  },
              ]
            : []),
    ]);
    const bottomItems = computed(() =>
        ['conversations', 'leads', 'flows', 'automation', 'settings'].flatMap(
            (id) => items.value.filter((item) => item.id === id),
        ),
    );

    const homeHref = computed(() =>
        page.props.canViewChat
            ? '/dashboard'
            : items.value.find((item) =>
                  ['leads', 'flows', 'settings'].includes(item.id),
              )?.href || '/settings/profile',
    );
    return { items, bottomItems, workspace, homeHref };
}

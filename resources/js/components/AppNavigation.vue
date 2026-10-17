<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    MessagesSquare,
    Workflow,
    Timer,
    Settings,
    ChevronsUpDown,
    Check,
    LoaderCircle,
} from '@lucide/vue';
import AppBrand from '@/components/AppBrand.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = defineProps<{ compact?: boolean }>();
const emit = defineEmits<{ navigate: [] }>();
const page = usePage<any>();
const switching = ref(false);
watch(
    () => page.url,
    () => {
        switching.value = false;
    },
);
const workspace = computed(
    () => page.props.currentWorkspace ?? page.props.workspace,
);
const workspaceInitial = (name?: string) =>
    name?.trim().charAt(0).toUpperCase() || '?';
const items = computed(() => [
    {
        title: 'Conversas',
        href: '/dashboard',
        icon: MessagesSquare,
        active: page.url.startsWith('/dashboard'),
    },
    ...(page.props.canManageFlows && workspace.value
        ? [
              {
                  title: 'Fluxos',
                  href: `/workspaces/${workspace.value.id}/flows`,
                  icon: Workflow,
                  active: /\/flows(?:\/|\?|$)/.test(page.url),
              },
              {
                  title: 'Automação',
                  href: `/workspaces/${workspace.value.id}/flow-triggers`,
                  icon: Timer,
                  active: /\/flow-triggers|\/sequences(?:\/|\?|$)/.test(
                      page.url,
                  ),
              },
          ]
        : []),
    {
        title: 'Configurações',
        href: '/settings/profile',
        icon: Settings,
        active:
            page.url.startsWith('/settings/') ||
            /\/workspaces\/\d+\/webhooks(?:\/|\?|$)/.test(page.url),
    },
]);
function switchWorkspace(id: number) {
    if (switching.value || id === workspace.value?.id) return;
    router.post(
        `/workspaces/${id}/current`,
        {},
        {
            preserveState: false,
            onStart: () => {
                switching.value = true;
            },
            onFinish: () => {
                switching.value = false;
                emit('navigate');
            },
        },
    );
}
</script>
<template>
    <div class="app-navigation" :class="{ compact: props.compact }">
        <Link
            class="brand-link"
            href="/dashboard"
            aria-label="chAIt — Conversas"
            @click="emit('navigate')"
            ><AppBrand :compact="compact"
        /></Link>
        <div v-if="!compact" class="navigation-caption">WORKSPACE</div>
        <nav aria-label="Menu principal">
            <Tooltip v-for="item in items" :key="item.title">
                <TooltipTrigger as-child>
                    <Link
                        :href="item.href"
                        class="navigation-link"
                        :class="{ active: item.active }"
                        :aria-label="item.title"
                        :aria-current="item.active ? 'page' : undefined"
                        @click="emit('navigate')"
                    >
                        <component
                            :is="item.icon"
                            :size="20"
                            :stroke-width="1.8"
                            aria-hidden="true"
                        /><span v-if="!compact">{{ item.title }}</span>
                    </Link>
                </TooltipTrigger>
                <TooltipContent v-if="compact" side="right">{{
                    item.title
                }}</TooltipContent>
            </Tooltip>
        </nav>
        <div class="navigation-footer">
            <DropdownMenu>
                <DropdownMenuTrigger
                    class="workspace-trigger"
                    :aria-label="`Workspace atual: ${workspace?.name ?? ''}. Trocar workspace`"
                    :disabled="switching"
                >
                    <span class="user-avatar">
                        <img
                            v-if="workspace?.icon_url"
                            :src="workspace.icon_url"
                            alt=""
                        />
                        <template v-else>{{
                            workspaceInitial(workspace?.name)
                        }}</template>
                    </span>
                    <span v-if="!compact" class="workspace-info"
                        ><strong>{{ workspace?.name }}</strong></span
                    >
                    <LoaderCircle
                        v-if="switching"
                        :size="16"
                        class="animate-spin"
                    /><ChevronsUpDown v-else-if="!compact" :size="16" />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    :side="compact ? 'right' : 'top'"
                    align="start"
                    class="w-60"
                >
                    <DropdownMenuItem
                        v-for="option in page.props.workspaces ?? []"
                        :key="option.id"
                        :disabled="switching"
                        @select="switchWorkspace(option.id)"
                    >
                        <span class="workspace-option-icon">
                            <img
                                v-if="option.icon_url"
                                :src="option.icon_url"
                                alt=""
                            />
                            <template v-else>{{
                                workspaceInitial(option.name)
                            }}</template>
                        </span>
                        <span class="min-w-0 flex-1 break-words">{{
                            option.name
                        }}</span
                        ><Check v-if="option.id === workspace?.id" :size="16" />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
<style scoped>
.app-navigation {
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 0 12px 12px;
    background: var(--sidebar);
    color: var(--sidebar-foreground);
    min-width: 0;
}
.brand-link {
    display: flex;
    align-items: center;
    height: 64px;
    padding: 0 12px;
    flex: none;
    text-decoration: none;
}
.navigation-caption {
    padding: 20px 12px 10px;
    font-size: 10px;
    letter-spacing: 0.1em;
    color: var(--muted-foreground);
    font-weight: 600;
}
nav {
    display: grid;
    gap: 6px;
}
.navigation-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    min-height: 44px;
    border-radius: var(--radius);
    color: var(--muted-foreground);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
}
.navigation-link svg {
    flex: none;
}
.navigation-link:hover {
    background: var(--secondary);
    color: var(--foreground);
}
.navigation-link.active {
    background: var(--accent);
    color: var(--accent-foreground);
}
.navigation-footer {
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.workspace-trigger {
    display: flex;
    width: 100%;
    align-items: center;
    gap: 10px;
    border-radius: var(--radius);
    padding: 8px;
    text-align: left;
    cursor: pointer;
    min-width: 0;
}
.workspace-trigger:hover {
    background: var(--secondary);
}
.user-avatar {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 11px;
    font-weight: 600;
    flex: none;
}
.user-avatar img,
.workspace-option-icon img {
    width: 100%;
    height: 100%;
    border-radius: inherit;
    object-fit: cover;
}
.workspace-option-icon {
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    flex: none;
    border-radius: 50%;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 10px;
    font-weight: 600;
}
.workspace-info {
    flex: 1;
    min-width: 0;
}
.workspace-info strong,
.workspace-info small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.workspace-info strong {
    font-size: 12px;
    font-weight: 600;
}
.workspace-info small {
    font-size: 11px;
    color: var(--muted-foreground);
    margin-top: 2px;
}
.compact {
    padding: 0 8px 12px;
}
.compact .brand-link {
    padding: 0;
    justify-content: center;
}
.compact nav {
    margin-top: 18px;
}
.compact .navigation-link {
    padding: 0;
    justify-content: center;
    width: 48px;
}
.compact .workspace-trigger {
    padding: 8px;
}
</style>

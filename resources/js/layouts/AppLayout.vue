<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PanelLeft, ChevronRight } from '@lucide/vue';
import AppNavigation from '@/components/AppNavigation.vue';
import { useNavigation } from '@/composables/useNavigation';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const page = usePage<any>();
const { expanded, mobileOpen, toggle } = useNavigation(
    page.props.sidebarOpen === true,
);
const title = computed(() =>
    page.url.startsWith('/settings/') ||
    /\/workspaces\/\d+\/webhooks(?:\/|\?|$)/.test(page.url)
        ? 'Configurações'
        : page.url.startsWith('/dashboard')
          ? 'Conversas'
          : /\/sequences(?:\/|\?|$)|\/flow-triggers/.test(page.url)
            ? 'Automação'
            : 'Fluxos',
);
watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);
const media = window.matchMedia('(min-width: 1024px)');
const isMobile = ref(!media.matches);
function closeMobile() {
    isMobile.value = !media.matches;
    if (media.matches) mobileOpen.value = false;
}
media.addEventListener('change', closeMobile);
onBeforeUnmount(() => media.removeEventListener('change', closeMobile));
</script>
<template>
    <TooltipProvider :delay-duration="250">
        <div class="app-layout" :class="{ 'sidebar-expanded': expanded }">
            <aside class="desktop-navigation">
                <AppNavigation :compact="!expanded" />
            </aside>
            <div class="app-main">
                <header class="app-topbar">
                    <Tooltip
                        ><TooltipTrigger as-child
                            ><Button
                                variant="ghost"
                                size="icon"
                                aria-label="Alternar menu principal"
                                :aria-expanded="
                                    isMobile ? mobileOpen : expanded
                                "
                                @click="toggle"
                                ><PanelLeft
                                    :size="20" /></Button></TooltipTrigger
                        ><TooltipContent>{{
                            isMobile
                                ? 'Abrir menu'
                                : expanded
                                  ? 'Recolher menu'
                                  : 'Expandir menu'
                        }}</TooltipContent></Tooltip
                    >
                    <span class="topbar-divider"></span
                    ><span class="topbar-title">{{ title }}</span>
                    <ChevronRight :size="14" class="workspace-chevron" /><span
                        class="topbar-workspace"
                        >{{ page.props.currentWorkspace?.name }}</span
                    >
                </header>
                <div class="app-page"><slot /></div>
            </div>
        </div>
        <Sheet v-model:open="mobileOpen">
            <SheetContent side="left" class="mobile-navigation gap-0 p-0">
                <SheetHeader class="sr-only"
                    ><SheetTitle>Menu principal</SheetTitle
                    ><SheetDescription
                        >Navegação e troca de workspace</SheetDescription
                    ></SheetHeader
                >
                <AppNavigation @navigate="mobileOpen = false" />
            </SheetContent>
        </Sheet>
    </TooltipProvider>
</template>
<style scoped>
.app-layout {
    display: grid;
    grid-template-columns: var(--sidebar-collapsed-width) minmax(0, 1fr);
    height: 100dvh;
    overflow: hidden;
    background: var(--background);
}
.sidebar-expanded {
    grid-template-columns: var(--sidebar-expanded-width) minmax(0, 1fr);
}
.desktop-navigation {
    min-width: 0;
    border-right: 1px solid var(--border);
}
.app-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
}
.app-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    height: var(--app-header-height);
    flex: none;
    padding: 0 16px;
    border-bottom: 1px solid var(--border);
    background: var(--card);
}
.topbar-divider {
    height: 20px;
    width: 1px;
    background: var(--border);
}
.topbar-title {
    font-size: 13px;
    font-weight: 600;
}
.topbar-workspace {
    font-size: 12px;
    color: var(--muted-foreground);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.workspace-chevron {
    color: var(--muted-foreground);
    flex: none;
}
.app-page {
    flex: 1;
    min-height: 0;
    min-width: 0;
    overflow: auto;
    container-type: inline-size;
}
@media (max-width: 1023px) {
    .app-layout,
    .sidebar-expanded {
        grid-template-columns: minmax(0, 1fr);
    }
    .desktop-navigation {
        display: none;
    }
    .app-topbar {
        padding: 0 12px;
    }
}
</style>
<style>
.mobile-navigation {
    width: 280px;
    max-width: 88vw;
}
.mobile-navigation > button {
    top: 16px;
    right: 16px;
}
</style>

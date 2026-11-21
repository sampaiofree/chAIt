<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLoading from '@/components/AppLoading.vue';
import { PanelLeft, ChevronRight } from '@lucide/vue';
import AppBrand from '@/components/AppBrand.vue';
import AppNavigation from '@/components/AppNavigation.vue';
import AppBottomNavigation from '@/components/AppBottomNavigation.vue';
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
const navigating = ref(false);
let pendingVisit: object | undefined;
const stopNavigationStart = router.on('start', ({ detail: { visit } }) => {
    if (visit.method !== 'get' || visit.async || visit.prefetch) {
        return;
    }

    pendingVisit = visit;
    navigating.value = true;
});
const stopNavigationFinish = router.on('finish', ({ detail: { visit } }) => {
    if (visit !== pendingVisit) {
        return;
    }

    pendingVisit = undefined;
    navigating.value = false;
});
const { expanded, mobileOpen, mobileChatDetail, toggle } = useNavigation(
    page.props.sidebarOpen === true,
);
const flowEditor = computed(() => /^\/workspaces\/\d+\/flows\/\d+(?:[/?#]|$)/.test(page.url));
const title = computed(() =>
    page.url.startsWith('/admin/')
        ? 'Administração'
        : page.url.startsWith('/settings/') ||
            /\/workspaces\/\d+\/webhooks(?:\/|\?|$)/.test(page.url)
          ? 'Configurações'
          : page.url.startsWith('/dashboard')
            ? 'Conversas'
            : /\/leads(?:\/|\?|$)/.test(page.url)
              ? 'Leads'
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
const viewportHeight = ref(window.visualViewport?.height ?? window.innerHeight);
function updateViewport() {
    viewportHeight.value = window.visualViewport?.height ?? window.innerHeight;
}
window.visualViewport?.addEventListener('resize', updateViewport);
window.addEventListener('resize', updateViewport);
function closeMobile() {
    isMobile.value = !media.matches;
    if (media.matches) mobileOpen.value = false;
}
media.addEventListener('change', closeMobile);
onBeforeUnmount(() => {
    stopNavigationStart();
    stopNavigationFinish();
    media.removeEventListener('change', closeMobile);
    window.visualViewport?.removeEventListener('resize', updateViewport);
    window.removeEventListener('resize', updateViewport);
});
</script>
<template>
    <TooltipProvider :delay-duration="250">
        <div
            class="app-layout"
            :class="{
                'sidebar-expanded': expanded,
                'mobile-chat-detail': mobileChatDetail,
            }"
            :style="isMobile ? { height: `${viewportHeight}px` } : undefined"
        >
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
                    <AppBrand v-if="isMobile" class="mobile-topbar-brand" />
                    <span class="topbar-divider"></span
                    ><span class="topbar-title">{{ title }}</span>
                    <ChevronRight :size="14" class="workspace-chevron" /><span
                        class="topbar-workspace"
                        >{{ page.props.currentWorkspace?.name }}</span
                    >
                </header>
                <div class="app-content">
                    <div class="app-page" :aria-busy="navigating"><slot /></div>
                    <AppLoading v-if="navigating" class="navigation-loading" />
                </div>
                <AppBottomNavigation v-if="!flowEditor" />
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
    padding: 0 var(--page-gutter);
    border-bottom: 1px solid var(--border);
    background: var(--card);
}
.topbar-divider {
    height: 20px;
    width: 1px;
    background: var(--border);
}
.topbar-title {
    font-size: 14px;
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
.app-content {
    position: relative;
    display: flex;
    flex: 1;
    min-height: 0;
    min-width: 0;
}
.navigation-loading {
    position: absolute;
    z-index: 10;
    top: 12px;
    right: max(12px, env(safe-area-inset-right));
    max-width: calc(100% - 24px);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
    box-shadow: var(--shadow-popover);
    pointer-events: none;
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
        background: color-mix(in srgb, var(--accent) 13%, var(--card));
        padding: 0 max(var(--page-gutter), env(safe-area-inset-right)) 0
            max(var(--page-gutter), env(safe-area-inset-left));
    }
    .mobile-topbar-brand {
        width: 114px;
        max-width: 42%;
    }
    .topbar-divider,
    .topbar-title,
    .topbar-workspace,
    .workspace-chevron {
        display: none;
    }
    .mobile-chat-detail .app-topbar,
    .mobile-chat-detail :deep(.bottom-navigation) {
        display: none;
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

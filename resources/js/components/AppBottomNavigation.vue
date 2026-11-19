<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useNavigationItems } from '@/composables/useNavigationItems';

const { bottomItems } = useNavigationItems();
</script>

<template>
    <nav class="bottom-navigation" aria-label="Navegação inferior">
        <Link
            v-for="item in bottomItems"
            :key="item.id"
            :href="item.href"
            class="bottom-navigation-link"
            :class="{ active: item.active }"
            :aria-current="item.active ? 'page' : undefined"
        >
            <component
                :is="item.icon"
                :size="22"
                :stroke-width="item.active ? 2.2 : 1.8"
                aria-hidden="true"
            />
            <span>{{ item.mobileTitle }}</span>
        </Link>
    </nav>
</template>

<style scoped>
.bottom-navigation {
    display: none;
}
@media (max-width: 1023px) {
    .bottom-navigation {
        display: flex;
        flex: none;
        gap: 2px;
        padding: 6px max(4px, env(safe-area-inset-right))
            calc(6px + env(safe-area-inset-bottom))
            max(4px, env(safe-area-inset-left));
        border-top: 1px solid var(--border);
        background: var(--card);
    }
    .bottom-navigation-link {
        display: flex;
        flex: 1;
        min-width: 0;
        min-height: 48px;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        border-radius: var(--radius);
        color: var(--muted-foreground);
        text-decoration: none;
        font-size: 10px;
        font-weight: 500;
        line-height: 1.2;
    }
    .bottom-navigation-link svg {
        flex: none;
    }
    .bottom-navigation-link.active {
        color: var(--brand-strong);
        background: var(--accent);
        font-weight: 600;
    }
    .bottom-navigation-link:hover {
        background: var(--accent);
        color: var(--accent-foreground);
    }
    .bottom-navigation-link:focus-visible {
        outline: 2px solid var(--ring);
        outline-offset: -2px;
    }
}
</style>

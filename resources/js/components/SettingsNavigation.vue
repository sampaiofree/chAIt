<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
const props = defineProps<{ active: string }>();
const navigation = ref<HTMLElement | null>(null);
function revealActive() {
    const nav = navigation.value;
    const link = nav?.querySelector<HTMLElement>('[aria-current="page"]');
    if (!nav || !link) {
        return;
    }
    const bounds = nav.getBoundingClientRect();
    const active = link.getBoundingClientRect();
    if (active.right > bounds.right) {
        nav.scrollLeft += active.right - bounds.right;
    } else if (active.left < bounds.left) {
        nav.scrollLeft -= bounds.left - active.left;
    }
}
onMounted(() => {
    revealActive();
    window.addEventListener('resize', revealActive);
});
onBeforeUnmount(() => window.removeEventListener('resize', revealActive));
watch(() => props.active, revealActive, { flush: 'post' });
const page = usePage<any>();
const items = computed(() => [
    { key: 'profile', label: 'Perfil' },
    { key: 'security', label: 'Segurança' },
    { key: 'appearance', label: 'Aparência' },
    ...(page.props.isAdmin
        ? [
              { key: 'workspace', label: 'Workspace' },
              { key: 'team', label: 'Equipe' },
              { key: 'contact-data', label: 'Campos e tags' },
              { key: 'whatsapp', label: 'WhatsApp' },
              { key: 'openai', label: 'OpenAI' },
              { key: 'webhooks', label: 'Webhooks' },
          ]
        : []),
]);
</script>
<template>
    <nav
        ref="navigation"
        class="settings-navigation"
        aria-label="Seções de configurações"
    >
        <Link
            v-for="item in items"
            :key="item.key"
            :href="`/settings/${item.key}`"
            :aria-current="active === item.key ? 'page' : undefined"
            >{{ item.label }}</Link
        >
    </nav>
</template>
<style scoped>
.settings-navigation {
    display: flex;
    gap: 20px;
    overflow-x: auto;
    border-bottom: 1px solid var(--border);
    margin-bottom: 32px;
}
.settings-navigation a {
    flex: none;
    display: flex;
    align-items: center;
    min-height: 48px;
    border-bottom: 2px solid transparent;
    color: var(--muted-foreground);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
}
.settings-navigation a:hover {
    color: var(--foreground);
}
.settings-navigation a[aria-current='page'] {
    color: var(--brand-strong);
    border-bottom-color: var(--brand-strong);
}
</style>

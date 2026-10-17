<script setup lang="ts">
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

const page = usePage<{ appearance: Appearance }>();
const appearance = computed(() => page.props.appearance || 'system');
const selected = ref<Appearance>(appearance.value);
const saving = ref(false);
const saved = ref(false);
const error = ref('');

function applyTheme(value: Appearance) {
    const dark =
        value === 'dark' ||
        (value === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

function choose(value: Appearance) {
    selected.value = value;
    saved.value = false;
    error.value = '';
    applyTheme(value);
    saving.value = true;
    router.patch(
        '/settings/appearance',
        { appearance: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = true;
            },
            onError: () => {
                selected.value = appearance.value;
                applyTheme(appearance.value);
                error.value = 'Não foi possível salvar sua preferência.';
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

watch(appearance, (value) => {
    selected.value = value;
    applyTheme(value);
});

applyTheme(selected.value);
</script>

<template>
    <Head title="Aparência" />
    <SettingsLayout active="appearance">
        <section class="appearance-content">
            <h1>Aparência</h1>
            <p>Escolha como o chAIt será exibido nos seus dispositivos.</p>

            <fieldset class="theme-options" :disabled="saving">
                <legend>Tema</legend>
                <label
                    v-for="option in [
                        {
                            value: 'light',
                            title: 'Claro',
                            description: 'Sempre usar o tema claro',
                        },
                        {
                            value: 'dark',
                            title: 'Escuro',
                            description: 'Sempre usar o tema escuro',
                        },
                        {
                            value: 'system',
                            title: 'Sistema',
                            description:
                                'Acompanhar a preferência do dispositivo',
                        },
                    ] as const"
                    :key="option.value"
                    class="theme-option"
                    :class="{ selected: selected === option.value }"
                >
                    <input
                        type="radio"
                        name="appearance"
                        :value="option.value"
                        :checked="selected === option.value"
                        @change="choose(option.value)"
                    />
                    <span>
                        <strong>{{ option.title }}</strong>
                        <small>{{ option.description }}</small>
                    </span>
                </label>
            </fieldset>
            <p v-if="saving" class="form-status" role="status">
                Salvando preferência…
            </p>
            <p v-else-if="saved" class="form-status success" role="status">
                Preferência salva.
            </p>
            <p v-else-if="error" class="form-status error" role="alert">
                {{ error }}
            </p>
        </section>
    </SettingsLayout>
</template>

<style scoped>
.settings-nav {
    padding: 30px 20px;
    display: grid;
    align-content: start;
    gap: 8px;
    min-width: 0;
    background: var(--card);
    border-right: 1px solid var(--border);
}
.settings-nav a {
    color: var(--muted-foreground);
    text-decoration: none;
    font-size: 14px;
    padding: 9px 10px;
    border-radius: 8px;
}
.settings-nav a:hover,
.settings-nav a[aria-current='page'] {
    color: var(--accent-foreground);
    background: var(--accent);
}
.appearance-content {
    padding: 38px;
    max-width: 720px;
}
h1 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 600;
}
.appearance-content > p {
    color: var(--muted-foreground);
    font-size: 14px;
}
.theme-options {
    display: grid;
    gap: 10px;
    border: 0;
    padding: 0;
    margin-top: 28px;
}
.theme-options legend {
    margin-bottom: 12px;
    font-weight: 600;
}
.theme-option {
    min-height: 70px;
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
    cursor: pointer;
}
.theme-option.selected {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
}
.theme-option input {
    accent-color: var(--primary);
}
.theme-option span {
    display: grid;
    gap: 4px;
}
.theme-option small {
    color: var(--muted-foreground);
    font-size: 13px;
}
.form-status {
    margin-top: 14px;
    font-size: 13px;
}
.success {
    color: var(--success);
}
.error {
    color: var(--danger);
}
@media (max-width: 650px) {
    .settings-nav {
        display: flex;
        align-items: center;
        overflow-x: auto;
        min-height: 62px;
        height: auto;
        padding: 12px;
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }
    .settings-nav a {
        flex: 0 0 auto;
        height: auto;
        white-space: nowrap;
    }
    .appearance-content {
        padding: 26px 18px;
    }
}
</style>

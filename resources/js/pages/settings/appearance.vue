<script setup lang="ts">
import AppNotice from '@/components/AppNotice.vue';
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
            <AppNotice v-else-if="saved" variant="success">
                Preferência salva.
            </AppNotice>
            <AppNotice v-else-if="error" variant="error">
                {{ error }}
            </AppNotice>
        </section>
    </SettingsLayout>
</template>

<style scoped>
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
    gap: 12px;
    border: 0;
    padding: 0;
    margin-top: var(--section-gap);
}
.theme-options legend {
    margin-bottom: 12px;
    font-weight: 600;
}
.theme-option {
    min-height: 72px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: var(--card-padding);
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
    background: var(--card);
    cursor: pointer;
}
.theme-option:not(.selected):hover {
    background: var(--muted);
}
.theme-option.selected {
    border-color: var(--ring);
    background: var(--accent);
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
</style>

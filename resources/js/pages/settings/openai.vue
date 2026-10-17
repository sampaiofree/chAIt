<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    workspace: { id: number; name: string };
    configured: boolean;
}>();
const configured = ref(props.configured);
const key = ref('');
const busy = ref(false);
const error = ref('');
const notice = ref('');
const endpoint = `/workspaces/${props.workspace.id}/openai`;

async function submit(method: 'PUT' | 'DELETE') {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const response = await fetch(endpoint, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content || '',
            },
            body:
                method === 'PUT'
                    ? JSON.stringify({ api_key: key.value })
                    : undefined,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok)
            throw new Error(
                result.message || 'Não foi possível atualizar a integração.',
            );
        configured.value = result.data.configured;
        key.value = '';
        notice.value = configured.value
            ? 'Chave configurada para este workspace.'
            : 'Chave removida.';
    } catch (caught: any) {
        error.value = caught.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Integração OpenAI" />
    <SettingsLayout active="openai">
        <section>
            <small>{{ workspace.name }}</small>
            <h1>Integração OpenAI</h1>
            <p>
                Use a chave da sua conta OpenAI para o Assistente de Fluxos e os
                blocos IA deste workspace. O consumo ocorre nessa conta.
            </p>
            <article class="settings-card">
                <strong>{{
                    configured ? 'Chave configurada' : 'Chave não configurada'
                }}</strong>
                <label
                    >Chave de API
                    <Input
                        v-model="key"
                        type="password"
                        autocomplete="off"
                        placeholder="sk-…"
                    />
                </label>
                <div class="actions">
                    <AppButton
                        :disabled="busy || !key.trim()"
                        @click="submit('PUT')"
                    >
                        {{ configured ? 'Substituir chave' : 'Salvar chave' }}
                    </AppButton>
                    <AppButton
                        v-if="configured"
                        variant="danger"
                        :disabled="busy"
                        @click="submit('DELETE')"
                    >
                        Remover chave
                    </AppButton>
                </div>
                <p v-if="error" role="alert" class="error">{{ error }}</p>
                <p v-if="notice" role="status">{{ notice }}</p>
            </article>
        </section>
    </SettingsLayout>
</template>

<style scoped>
section {
    padding: 38px;
    max-width: 760px;
}
section > small {
    color: var(--muted-foreground);
}
h1 {
    font-size: 24px;
    margin: 8px 0;
}
p {
    color: var(--muted-foreground);
    font-size: 14px;
    line-height: 1.5;
}
article {
    display: grid;
    gap: 18px;
    margin-top: 24px;
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}
label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 13px;
}

.actions {
    display: flex;
    gap: var(--form-button-gap);
    flex-wrap: wrap;
}

.error {
    color: var(--destructive);
}
@media (max-width: 650px) {
    section {
        padding: 24px 16px;
    }
}
</style>

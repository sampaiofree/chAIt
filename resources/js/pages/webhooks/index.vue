<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus, Copy } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import { Input } from '@/components/ui/input';
import { webhookRequest, WebhookRequestError } from '@/lib/webhooks';
const props = defineProps<{
    workspace: { id: number; name: string };
    webhooks: { id: number; name: string; status: string; url: string }[];
}>();
const open = ref(false);
const name = ref('');
const busy = ref(false);
const error = ref('');
const notice = ref('');
watch(open, (value) => {
    if (!value) {
        name.value = '';
        error.value = '';
    }
});
async function create() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await webhookRequest<{ data: { id: number } }>(
            `/workspaces/${props.workspace.id}/webhooks`,
            'POST',
            { name: name.value },
        );
        open.value = false;
        router.visit(
            `/workspaces/${props.workspace.id}/webhooks/${result.data.id}`,
        );
    } catch (e) {
        error.value =
            e instanceof WebhookRequestError
                ? e.fields.name || e.message
                : 'Falha ao criar Webhook. Tente novamente.';
    } finally {
        busy.value = false;
    }
}
async function copy(url: string) {
    try {
        await navigator.clipboard.writeText(url);
        notice.value = 'URL copiada.';
    } catch {
        notice.value =
            'Não foi possível copiar. Selecione a URL e copie manualmente.';
    }
}
</script>
<template>
    <Head title="Webhooks" />
    <SettingsLayout active="webhooks"
        ><section class="webhooks-page">
            <header>
                <div>
                    <h1>Webhooks</h1>
                    <p>
                        Receba dados de plataformas externas e atualize seus
                        contatos.
                    </p>
                </div>
                <AppButton @click="open = true"
                    ><Plus :size="18" />Criar Webhook</AppButton
                >
            </header>
            <p v-if="notice" role="status">{{ notice }}</p>
            <div v-if="webhooks.length" class="webhook-list">
                <article v-for="webhook in webhooks" :key="webhook.id">
                    <div>
                        <Link
                            :href="`/workspaces/${workspace.id}/webhooks/${webhook.id}`"
                            class="webhook-name"
                            >{{ webhook.name }}</Link
                        >
                        <p class="url">{{ webhook.url }}</p>
                    </div>
                    <span class="status">{{
                        webhook.status === 'active' ? 'Ativo' : 'Teste'
                    }}</span
                    ><AppButton
                        variant="secondary"
                        :aria-label="`Copiar URL de ${webhook.name}`"
                        @click="copy(webhook.url)"
                        ><Copy :size="16" />Copiar URL</AppButton
                    >
                </article>
            </div>
            <section v-else class="empty">
                <h2>Nenhum Webhook cadastrado</h2>
                <p>
                    Crie um Webhook, envie um exemplo e escolha os campos do
                    contato.
                </p>
                <AppButton variant="secondary" @click="open = true"
                    >Criar Webhook</AppButton
                >
            </section>
            <AppFormDialog
                v-model:open="open"
                title="Criar Webhook"
                description="Escolha um nome para identificar a plataforma externa."
                :busy="busy"
                :dirty="Boolean(name)"
                v-slot="{ close }"
                ><form @submit.prevent="create">
                    <div class="form-field">
                        <label for="webhook-name">Nome do Webhook</label
                        ><Input
                            id="webhook-name"
                            v-model="name"
                            required
                            maxlength="120"
                            :aria-invalid="Boolean(error)"
                            aria-describedby="name-error"
                        />
                        <p
                            v-if="error"
                            id="name-error"
                            class="form-error"
                            role="alert"
                        >
                            {{ error }}
                        </p>
                    </div>
                    <div class="form-actions">
                        <AppButton
                            variant="secondary"
                            :disabled="busy"
                            @click="close()"
                            >Cancelar</AppButton
                        ><AppButton type="submit" :disabled="busy">{{
                            busy ? 'Criando…' : 'Criar Webhook'
                        }}</AppButton>
                    </div>
                </form></AppFormDialog
            >
        </section></SettingsLayout
    >
</template>
<style scoped>
.webhooks-page {
    width: 100%;
    max-width: 1120px;
    margin: 0 auto;
    padding: 0;
    min-width: 0;
}
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
h1 {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 8px;
}
p {
    color: var(--muted-foreground);
    font-size: 14px;
}
.webhook-list {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
}
article {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 16px;
    padding: 20px;
}
article + article {
    border-top: 1px solid var(--border);
}
.webhook-name {
    font-weight: 600;
    overflow-wrap: anywhere;
}
.webhook-name:hover {
    text-decoration: underline;
}
.url {
    overflow-wrap: anywhere;
    margin-top: 6px;
    font-size: 12px;
}
.status {
    border-radius: var(--radius);
    background: var(--secondary);
    padding: 4px 10px;
    font-size: 12px;
}
.empty {
    padding: 40px 20px;
    text-align: center;
    display: grid;
    justify-items: center;
    gap: 12px;
}
@media (max-width: 640px) {
    .webhooks-page {
        padding: 0;
    }
    article {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    article > div {
        grid-column: 1 / -1;
    }
}
</style>

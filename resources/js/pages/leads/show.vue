<script setup lang="ts">
import AppNotice from '@/components/AppNotice.vue';
import AppFieldHelp from '@/components/AppFieldHelp.vue';
import { useForm, Link, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import LeadTagPicker from '@/components/LeadTagPicker.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import {
    ArrowLeft,
    MessagesSquare,
    Plus,
    Check,
    LockKeyhole,
} from '@lucide/vue';
import '@/../css/leads.css';
const props = defineProps<{
    workspace: any;
    lead: any;
    tags: any[];
    fields: any[];
    threadId: number | null;
    canStartConversation: boolean;
    canEdit: boolean;
    canCreateTags: boolean;
    canCreateFields: boolean;
}>();
const opening = ref(false);
const conversationError = ref('');
async function openConversation() {
    if (opening.value) return;
    opening.value = true;
    conversationError.value = '';
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/conversations/leads/${props.lead.id}/open`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
            },
        );
        const result = await response.json();
        if (!response.ok)
            throw new Error(
                result.message || 'Não foi possível abrir a conversa.',
            );
        router.visit(`/dashboard?thread_id=${result.data.thread_id}`);
    } catch (error) {
        conversationError.value =
            error instanceof Error
                ? error.message
                : 'Não foi possível abrir a conversa.';
    } finally {
        opening.value = false;
    }
}
function fieldError(id: number): string | undefined {
    return (form.errors as Record<string, string>)[`values.${id}`];
}
const saved = ref(false);
const tagOpen = ref(false);
const fieldOpen = ref(false);
const tagForm = useForm({ name: '', color: '#16a34a' });
const fieldForm = useForm({ name: '' });
const connected = ref(false);
fetch(`/workspaces/${props.workspace.id}/chat-access`, {
    headers: { Accept: 'application/json' },
})
    .then((r) => r.json())
    .then((r) => (connected.value = r.allowed === true))
    .catch(() => {});
const form = useForm({
    display_name: props.lead.display_name || '',
    email: props.lead.email || '',
    cpf: props.lead.cpf || '',
    observations: props.lead.observations || '',
    tag_ids: props.lead.tags.map((t: any) => t.id),
    values: Object.fromEntries(
        props.fields.map((f) => [
            f.id,
            props.lead.custom_field_values.find((v: any) => v.field_id === f.id)
                ?.value || '',
        ]),
    ),
});
function save() {
    saved.value = false;
    form.patch(`/workspaces/${props.workspace.id}/leads/${props.lead.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            saved.value = true;
            form.defaults();
        },
    });
}
</script>
<template>
    <Head title="Ficha do lead" />
    <main class="leads-page">
        <Link :href="`/workspaces/${workspace.id}/leads`" class="leads-back"
            ><ArrowLeft :size="16" />Voltar para Leads</Link
        >
        <header class="leads-header leads-detail-header">
            <div class="leads-identity">
                <span
                    class="lead-avatar lead-avatar-large"
                    aria-hidden="true"
                    >{{
                        (lead.display_name || '?').charAt(0).toUpperCase()
                    }}</span
                >
                <div>
                    <h1>{{ lead.display_name || 'Sem nome' }}</h1>
                    <p>
                        {{ lead.phone || 'WhatsApp não informado' }}
                    </p>
                </div>
            </div>
            <div class="leads-conversation">
                <Button v-if="threadId && connected" variant="outline" as-child
                    ><Link :href="`/dashboard?thread_id=${threadId}`"
                        ><MessagesSquare :size="16" />Abrir conversa</Link
                    ></Button
                ><AppButton
                    v-else-if="!threadId && canStartConversation && connected"
                    variant="secondary"
                    :disabled="opening"
                    @click="openConversation"
                    ><MessagesSquare :size="16" />{{
                        opening ? 'Abrindo…' : 'Abrir conversa'
                    }}</AppButton
                ><template v-else
                    ><AppButton variant="secondary" disabled
                        ><MessagesSquare :size="16" />Abrir conversa</AppButton
                    >
                    <AppFieldHelp label="Ajuda">
                        {{
                            !connected
                                ? 'Conecte o WhatsApp para abrir a conversa.'
                                : !lead.phone
                                  ? 'Contato sem WhatsApp válido.'
                                  : 'Sem permissão para abrir esta conversa.'
                        }}
                    </AppFieldHelp></template
                >
                <AppNotice v-if="conversationError" :key="conversationError" variant="error">
                    {{ conversationError }}
                </AppNotice>
            </div>
        </header>
        <p v-if="!canEdit" class="leads-readonly">
            <LockKeyhole :size="16" />Você tem acesso de leitura a este
            cadastro.
        </p>
        <form @submit.prevent="save">
            <div class="leads-detail-grid">
                <div class="leads-column">
                    <section class="leads-card">
                        <div class="leads-section-header">
                            <div>
                                <h2>Dados do contato</h2>
                                <p>Informações de identificação do lead.</p>
                            </div>
                        </div>
                        <fieldset
                            :disabled="!canEdit || form.processing"
                            class="leads-fields-grid"
                        >
                            <div class="form-field">
                                <label for="lead-name">Nome</label
                                ><Input
                                    id="lead-name"
                                    v-model="form.display_name"
                                    autocomplete="name"
                                    :aria-invalid="
                                        Boolean(form.errors.display_name)
                                    "
                                />
                                <p
                                    v-if="form.errors.display_name"
                                    class="form-error"
                                    role="alert"
                                >
                                    {{ form.errors.display_name }}
                                </p>
                            </div>
                            <div class="form-field">
                                <label for="lead-phone">WhatsApp</label
                                ><Input
                                    id="lead-phone"
                                    :model-value="lead.phone || ''"
                                    readonly
                                />
                                <AppFieldHelp label="Ajuda">
                                    O número de WhatsApp não pode ser alterado.
                                </AppFieldHelp>
                            </div>
                            <div class="form-field">
                                <label for="lead-email">E-mail</label
                                ><Input
                                    id="lead-email"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    :aria-invalid="Boolean(form.errors.email)"
                                />
                                <p
                                    v-if="form.errors.email"
                                    class="form-error"
                                    role="alert"
                                >
                                    {{ form.errors.email }}
                                </p>
                            </div>
                            <div class="form-field">
                                <label for="lead-cpf">CPF</label
                                ><Input
                                    id="lead-cpf"
                                    v-model="form.cpf"
                                    maxlength="14"
                                    :aria-invalid="Boolean(form.errors.cpf)"
                                />
                                <p
                                    v-if="form.errors.cpf"
                                    class="form-error"
                                    role="alert"
                                >
                                    {{ form.errors.cpf }}
                                </p>
                            </div>
                        </fieldset>
                    </section>
                    <section class="leads-card">
                        <div class="leads-section-header">
                            <div>
                                <h2>Campos personalizados</h2>
                                <p>Dados compartilhados com seus fluxos.</p>
                            </div>
                            <AppButton
                                v-if="canCreateFields"
                                variant="ghost"
                                size="sm"
                                @click="fieldOpen = true"
                                ><Plus :size="16" />Criar campo</AppButton
                            >
                        </div>
                        <fieldset
                            :disabled="!canEdit || form.processing"
                            class="form-fields"
                        >
                            <div
                                v-for="field in fields"
                                :key="field.id"
                                class="form-field"
                            >
                                <label :for="`lead-field-${field.id}`">{{
                                    field.name
                                }}</label
                                ><Input
                                    :id="`lead-field-${field.id}`"
                                    v-model="form.values[field.id]"
                                />
                                <p
                                    v-if="fieldError(field.id)"
                                    class="form-error"
                                    role="alert"
                                >
                                    {{ fieldError(field.id) }}
                                </p>
                            </div>
                            <p v-if="!fields.length" class="form-help">
                                Nenhum campo personalizado cadastrado.
                            </p>
                        </fieldset>
                    </section>
                </div>
                <div class="leads-column">
                    <section class="leads-card">
                        <div class="leads-section-header">
                            <div>
                                <h2>Etiquetas</h2>
                                <p>Organize e identifique seus contatos.</p>
                            </div>
                            <AppButton
                                v-if="canCreateTags"
                                variant="ghost"
                                size="sm"
                                @click="tagOpen = true"
                                ><Plus :size="16" />Criar etiqueta</AppButton
                            >
                        </div>
                        <LeadTagPicker
                            v-model="form.tag_ids"
                            :tags="tags"
                            :disabled="!canEdit || form.processing"
                        />
                        <p
                            v-if="form.errors.tag_ids"
                            class="form-error"
                            role="alert"
                        >
                            {{ form.errors.tag_ids }}
                        </p>
                    </section>
                    <section class="leads-card">
                        <div class="leads-section-header">
                            <div>
                                <h2>Observações</h2>
                                <p>
                                    Informações gerais do contato, separadas das
                                    notas do atendimento.
                                </p>
                            </div>
                        </div>
                        <div class="form-field">
                            <label for="lead-observations" class="sr-only"
                                >Observações do contato</label
                            ><Textarea
                                id="lead-observations"
                                v-model="form.observations"
                                rows="6"
                                maxlength="10000"
                                placeholder="Adicione informações relevantes sobre este contato…"
                                :disabled="!canEdit || form.processing"
                            />
                            <p
                                v-if="form.errors.observations"
                                class="form-error"
                                role="alert"
                            >
                                {{ form.errors.observations }}
                            </p>
                        </div>
                    </section>
                </div>
            </div>
            <footer v-if="canEdit" class="leads-save-bar">
                <span
                    v-if="saved && !form.isDirty"
                    class="leads-saved"
                    role="status"
                    ><Check :size="16" />Alterações salvas.</span
                ><span v-else class="form-help"
                    >As alterações serão aplicadas ao cadastro do contato.</span
                ><AppButton type="submit" :loading="form.processing"
                    >Salvar alterações</AppButton
                >
            </footer>
        </form>
        <AppFormDialog
            v-model:open="tagOpen"
            title="Nova etiqueta"
            description="Crie uma etiqueta para organizar os contatos do workspace."
            :busy="tagForm.processing"
            :dirty="tagForm.isDirty"
            v-slot="{ close }"
            ><form
                @submit.prevent="
                    tagForm.post(`/workspaces/${workspace.id}/tags`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            tagOpen = false;
                            tagForm.reset();
                        },
                    })
                "
            >
                <div class="form-field">
                    <label for="new-tag-name">Nome da etiqueta</label
                    ><Input
                        id="new-tag-name"
                        v-model="tagForm.name"
                        required
                        maxlength="100"
                    />
                    <p
                        v-if="tagForm.errors.name"
                        class="form-error"
                        role="alert"
                    >
                        {{ tagForm.errors.name }}
                    </p>
                </div>
                <div class="form-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="tagForm.processing"
                        @click="close()"
                        >Cancelar</AppButton
                    ><AppButton type="submit" :loading="tagForm.processing"
                        >Criar etiqueta</AppButton
                    >
                </div>
            </form></AppFormDialog
        >
        <AppFormDialog
            v-model:open="fieldOpen"
            title="Novo campo personalizado"
            description="Este campo ficará disponível para os contatos e fluxos do workspace."
            :busy="fieldForm.processing"
            :dirty="fieldForm.isDirty"
            v-slot="{ close }"
            ><form
                @submit.prevent="
                    fieldForm.post(
                        `/workspaces/${workspace.id}/contact-fields`,
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                fieldOpen = false;
                                fieldForm.reset();
                            },
                        },
                    )
                "
            >
                <div class="form-field">
                    <label for="new-field-name">Nome do campo</label
                    ><Input
                        id="new-field-name"
                        v-model="fieldForm.name"
                        required
                        maxlength="100"
                    />
                    <p
                        v-if="fieldForm.errors.name"
                        class="form-error"
                        role="alert"
                    >
                        {{ fieldForm.errors.name }}
                    </p>
                </div>
                <div class="form-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="fieldForm.processing"
                        @click="close()"
                        >Cancelar</AppButton
                    ><AppButton type="submit" :loading="fieldForm.processing"
                        >Criar campo</AppButton
                    >
                </div>
            </form></AppFormDialog
        >
    </main>
</template>

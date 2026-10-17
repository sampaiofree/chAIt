<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import { useAppDialog } from '@/composables/useAppDialog';
import AppDialog from '@/components/AppDialog.vue';
import { Plus, Pencil, Trash2 } from '@lucide/vue';
import { Input } from '@/components/ui/input';

type Field = { id: number; name: string; type: string };
type Tag = { id: number; name: string; color: string };
const props = defineProps<{
    workspace: { id: number; name: string };
    fields: Field[];
    tags: Tag[];
}>();
const fieldOpen = ref(false);
const tagOpen = ref(false);
const activeTab = ref<'fields' | 'tags'>('fields');
const fieldId = ref<number | null>(null);
const tagId = ref<number | null>(null);
const fieldForm = useForm({ name: '' });
const tagForm = useForm({ name: '', color: '#16a34a' });
const fieldNotice = ref('');
const tagNotice = ref('');
const deletionForm = useForm({ resource: '' });
const deletionTarget = ref<{ kind: 'field' | 'tag'; id: number } | null>(null);
const confirmingDeletion = ref(false);
const { dialog, confirm, finish } = useAppDialog();
const busy = computed(
    () =>
        fieldForm.processing ||
        tagForm.processing ||
        deletionForm.processing ||
        confirmingDeletion.value,
);
async function deleteResource(kind: 'field' | 'tag', item: Field | Tag) {
    if (busy.value) return;
    deletionForm.clearErrors();
    deletionTarget.value = { kind, id: item.id };
    confirmingDeletion.value = true;
    const approved = await confirm(
        kind === 'field'
            ? `Excluir o campo “${item.name}”? Todos os valores preenchidos nesse campo nos contatos serão apagados. Esta ação não pode ser desfeita.`
            : `Excluir a etiqueta “${item.name}”? Ela será removida dos contatos. Esta ação não pode ser desfeita.`,
        kind === 'field' ? 'Excluir campo personalizado' : 'Excluir etiqueta',
    );
    confirmingDeletion.value = false;
    if (!approved) return;
    const endpoint = `/workspaces/${props.workspace.id}/${kind === 'field' ? 'contact-fields' : 'tags'}/${item.id}`;
    deletionForm.delete(endpoint, {
        preserveScroll: true,
        onSuccess: () => {
            deletionTarget.value = null;
            if (kind === 'field')
                fieldNotice.value = 'Campo e valores excluídos.';
            else tagNotice.value = 'Etiqueta excluída.';
        },
    });
}
const previewColor = computed(() =>
    /^#[0-9a-fA-F]{6}$/.test(tagForm.color) ? tagForm.color : '#16a34a',
);

function cancelField() {
    fieldOpen.value = false;
    fieldId.value = null;
    fieldForm.defaults({ name: '' });
    fieldForm.reset();
    fieldForm.clearErrors();
}
function cancelTag() {
    tagOpen.value = false;
    tagId.value = null;
    tagForm.defaults({ name: '', color: '#16a34a' });
    tagForm.reset();
    tagForm.clearErrors();
}
function editField(field: Field) {
    cancelField();
    fieldNotice.value = '';
    fieldId.value = field.id;
    fieldForm.name = field.name;
    fieldForm.defaults({ name: field.name });
    fieldOpen.value = true;
}
function editTag(tag: Tag) {
    cancelTag();
    tagNotice.value = '';
    tagId.value = tag.id;
    tagForm.name = tag.name;
    tagForm.color = tag.color;
    tagForm.defaults({ name: tag.name, color: tag.color });
    tagOpen.value = true;
}
function saveField() {
    if (busy.value) return;
    fieldNotice.value = '';
    const editing = fieldId.value !== null;
    const endpoint = `/workspaces/${props.workspace.id}/contact-fields`;
    fieldForm.submit(
        editing ? 'patch' : 'post',
        editing ? `${endpoint}/${fieldId.value}` : endpoint,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelField();
                fieldNotice.value = editing
                    ? 'Campo atualizado.'
                    : 'Campo cadastrado.';
            },
        },
    );
}
function saveTag() {
    if (busy.value) return;
    tagNotice.value = '';
    const editing = tagId.value !== null;
    const endpoint = `/workspaces/${props.workspace.id}/tags`;
    tagForm.submit(
        editing ? 'patch' : 'post',
        editing ? `${endpoint}/${tagId.value}` : endpoint,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelTag();
                tagNotice.value = editing
                    ? 'Tag atualizada.'
                    : 'Tag cadastrada.';
            },
        },
    );
}
function tabKeydown(event: KeyboardEvent) {
    if (
        !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) ||
        busy.value
    )
        return;
    event.preventDefault();
    activeTab.value =
        event.key === 'Home'
            ? 'fields'
            : event.key === 'End'
              ? 'tags'
              : activeTab.value === 'fields'
                ? 'tags'
                : 'fields';
    document.getElementById(`tab-${activeTab.value}`)?.focus();
}
</script>

<template>
    <Head title="Campos e tags" />
    <SettingsLayout active="contact-data">
        <section class="content">
            <small>{{ workspace.name }}</small>
            <h1>Campos e tags</h1>
            <p>
                Organize os dados dos contatos com campos personalizados e tags
                disponíveis no chat e nos fluxos.
            </p>

            <div
                class="tabs"
                role="tablist"
                aria-label="Cadastros dos contatos"
                @keydown="tabKeydown"
            >
                <button
                    id="tab-fields"
                    type="button"
                    role="tab"
                    aria-controls="panel-fields"
                    :aria-selected="activeTab === 'fields'"
                    :tabindex="activeTab === 'fields' ? 0 : -1"
                    :disabled="busy"
                    @click="activeTab = 'fields'"
                >
                    Campos personalizados
                </button>
                <button
                    id="tab-tags"
                    type="button"
                    role="tab"
                    aria-controls="panel-tags"
                    :aria-selected="activeTab === 'tags'"
                    :tabindex="activeTab === 'tags' ? 0 : -1"
                    :disabled="busy"
                    @click="activeTab = 'tags'"
                >
                    Tags
                </button>
            </div>

            <div
                v-show="activeTab === 'fields'"
                id="panel-fields"
                role="tabpanel"
                aria-labelledby="tab-fields"
                tabindex="0"
            >
                <div class="record-toolbar">
                    <AppButton
                        @click="
                            cancelField();
                            fieldOpen = true;
                        "
                        ><Plus :size="16" />Cadastrar campo</AppButton
                    >
                </div>
                <p v-if="fieldNotice" class="notice" role="status">
                    {{ fieldNotice }}
                </p>
                <h2 class="list-heading">
                    Campos cadastrados <span>{{ fields.length }}</span>
                </h2>
                <p v-if="!fields.length" class="empty">
                    Nenhum campo cadastrado. Use “Cadastrar campo” para começar.
                </p>
                <ul v-else class="records">
                    <li v-for="field in fields" :key="field.id">
                        <div class="record-info">
                            <strong>{{ field.name }}</strong
                            ><small>{{
                                field.type === 'text' ? 'Texto' : field.type
                            }}</small>
                        </div>
                        <div class="record-actions">
                            <AppButton
                                variant="secondary"
                                :disabled="busy"
                                :aria-label="`Editar campo ${field.name}`"
                                @click="editField(field)"
                                ><Pencil :size="16" />Editar</AppButton
                            >
                            <AppButton
                                variant="danger"
                                :disabled="busy"
                                :aria-label="`Excluir campo ${field.name}`"
                                @click="deleteResource('field', field)"
                                ><Trash2 :size="16" />Excluir</AppButton
                            >
                        </div>
                        <p
                            v-if="
                                deletionTarget?.kind === 'field' &&
                                deletionTarget.id === field.id &&
                                deletionForm.errors.resource
                            "
                            class="error deletion-error"
                            role="alert"
                        >
                            {{ deletionForm.errors.resource }}
                        </p>
                    </li>
                </ul>
            </div>

            <div
                v-show="activeTab === 'tags'"
                id="panel-tags"
                role="tabpanel"
                aria-labelledby="tab-tags"
                tabindex="0"
            >
                <div class="record-toolbar">
                    <AppButton
                        @click="
                            cancelTag();
                            tagOpen = true;
                        "
                        ><Plus :size="16" />Cadastrar tag</AppButton
                    >
                </div>
                <p v-if="tagNotice" class="notice" role="status">
                    {{ tagNotice }}
                </p>
                <h2 class="list-heading">
                    Tags cadastradas <span>{{ tags.length }}</span>
                </h2>
                <p v-if="!tags.length" class="empty">
                    Nenhuma tag cadastrada. Use “Cadastrar tag” para começar.
                </p>
                <ul v-else class="records">
                    <li v-for="tag in tags" :key="tag.id">
                        <div class="record-info">
                            <strong class="tag-preview"
                                ><span
                                    class="color-dot"
                                    :style="{ backgroundColor: tag.color }"
                                ></span
                                >{{ tag.name }}</strong
                            ><small>{{ tag.color }}</small>
                        </div>
                        <div class="record-actions">
                            <AppButton
                                variant="secondary"
                                :disabled="busy"
                                :aria-label="`Editar tag ${tag.name}`"
                                @click="editTag(tag)"
                                ><Pencil :size="16" />Editar</AppButton
                            >
                            <AppButton
                                variant="danger"
                                :disabled="busy"
                                :aria-label="`Excluir etiqueta ${tag.name}`"
                                @click="deleteResource('tag', tag)"
                                ><Trash2 :size="16" />Excluir</AppButton
                            >
                        </div>
                        <p
                            v-if="
                                deletionTarget?.kind === 'tag' &&
                                deletionTarget.id === tag.id &&
                                deletionForm.errors.resource
                            "
                            class="error deletion-error"
                            role="alert"
                        >
                            {{ deletionForm.errors.resource }}
                        </p>
                    </li>
                </ul>
            </div>
        </section>
    </SettingsLayout>
    <AppFormDialog
        :open="fieldOpen"
        :title="fieldId === null ? 'Cadastrar campo' : 'Editar campo'"
        :busy="busy"
        :dirty="fieldForm.isDirty"
        @update:open="!$event && cancelField()"
        v-slot="{ close }"
        ><form class="editor" @submit.prevent="saveField">
            <fieldset :disabled="busy" class="form-fields">
                <div class="form-field">
                    <label for="field-name">Nome do campo</label>
                    <Input
                        id="field-name"
                        v-model="fieldForm.name"
                        required
                        maxlength="100"
                        placeholder="Ex.: Interesse"
                        :aria-invalid="!!fieldForm.errors.name"
                        :aria-describedby="
                            fieldForm.errors.name
                                ? 'field-name-error'
                                : undefined
                        "
                    />
                    <small
                        v-if="fieldForm.errors.name"
                        id="field-name-error"
                        class="error"
                        role="alert"
                        >{{ fieldForm.errors.name }}</small
                    >
                </div>
            </fieldset>
            <div class="form-actions">
                <AppButton variant="secondary" :disabled="busy" @click="close()"
                    >Cancelar</AppButton
                ><AppButton type="submit" :loading="fieldForm.processing">{{
                    fieldForm.processing
                        ? 'Salvando…'
                        : fieldId === null
                          ? 'Cadastrar campo'
                          : 'Salvar alterações'
                }}</AppButton>
            </div>
        </form></AppFormDialog
    >
    <AppFormDialog
        :open="tagOpen"
        :title="tagId === null ? 'Cadastrar tag' : 'Editar tag'"
        :busy="busy"
        :dirty="tagForm.isDirty"
        @update:open="!$event && cancelTag()"
        v-slot="{ close }"
        ><form class="editor" @submit.prevent="saveTag">
            <fieldset :disabled="busy" class="form-fields">
                <div class="form-field">
                    <label for="tag-name">Nome da tag</label>
                    <Input
                        id="tag-name"
                        v-model="tagForm.name"
                        required
                        maxlength="100"
                        placeholder="Ex.: Cliente VIP"
                        :aria-invalid="!!tagForm.errors.name"
                        :aria-describedby="
                            tagForm.errors.name ? 'tag-name-error' : undefined
                        "
                    />
                    <small
                        v-if="tagForm.errors.name"
                        id="tag-name-error"
                        class="error"
                        role="alert"
                        >{{ tagForm.errors.name }}</small
                    >
                </div>
                <div class="form-field">
                    <label for="tag-color">Cor</label>
                    <div class="color-controls">
                        <input
                            id="tag-color"
                            v-model="tagForm.color"
                            type="color"
                            :aria-invalid="!!tagForm.errors.color"
                            :aria-describedby="
                                tagForm.errors.color
                                    ? 'tag-color-error'
                                    : undefined
                            "
                        />
                        <span class="tag-preview"
                            ><span
                                class="color-dot"
                                :style="{ backgroundColor: previewColor }"
                            ></span
                            >{{ tagForm.name.trim() || 'Prévia da tag' }}</span
                        >
                    </div>
                    <small
                        v-if="tagForm.errors.color"
                        id="tag-color-error"
                        class="error"
                        role="alert"
                        >{{ tagForm.errors.color }}</small
                    >
                </div>
            </fieldset>
            <div class="form-actions">
                <AppButton variant="secondary" :disabled="busy" @click="close()"
                    >Cancelar</AppButton
                ><AppButton type="submit" :loading="tagForm.processing">{{
                    tagForm.processing
                        ? 'Salvando…'
                        : tagId === null
                          ? 'Cadastrar tag'
                          : 'Salvar alterações'
                }}</AppButton>
            </div>
        </form></AppFormDialog
    >
    <AppDialog :dialog="dialog" @resolve="finish" />
</template>

<style scoped>
.records > li {
    flex-wrap: wrap;
    gap: 8px;
}
.deletion-error {
    width: 100%;
    margin: 8px 0;
}
.content {
    width: 100%;
    max-width: 900px;
    padding: 38px;
    min-width: 0;
}
.content > small,
p,
.record-info > small {
    color: var(--muted-foreground);
}
h1 {
    font-size: 24px;
    margin: 8px 0;
}
h2 {
    font-size: 16px;
    margin: 0;
}
p {
    font-size: 14px;
    line-height: 1.5;
}
.tabs {
    display: flex;
    gap: 8px;
    margin: 24px 0;
    border-bottom: 1px solid var(--border);
}
.tabs button {
    padding: 12px;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--muted-foreground);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
}
.tabs button[aria-selected='true'] {
    color: var(--foreground);
    border-bottom-color: var(--primary);
}
.tabs button:disabled {
    opacity: 0.55;
    cursor: default;
}
.records,
.empty {
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}
.unused-editor-description {
    margin: 8px 0 18px;
}
fieldset {
    display: grid;
    gap: var(--form-field-gap);
    border: 0;
    padding: 0;
    margin: 0;
    min-width: 0;
}
label {
    font-size: 14px;
}
input {
    width: 100%;
    min-width: 0;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--background);
    color: var(--foreground);
    font: inherit;
    font-size: 14px;
}
input[aria-invalid='true'] {
    border-color: var(--danger);
}
input:focus-visible,
button:focus-visible,
[role='tabpanel']:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 3px;
}
.error {
    color: var(--danger);
    font-size: 13px;
}
.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.editor .notice {
    color: var(--success);
    margin: 16px 0 0;
}
.list-heading {
    margin: 28px 0 12px;
}
.list-heading span {
    font-size: 13px;
    color: var(--muted-foreground);
    margin-left: 8px;
}
.records {
    padding: 0;
    list-style: none;
    margin: 0;
    overflow: hidden;
}
.records li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}
.records li:last-child {
    border-bottom: 0;
}
.record-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--form-button-gap);
    margin-left: auto;
    max-width: 100%;
}
.record-actions > button {
    flex-shrink: 0;
}
.record-info {
    min-width: 0;
    overflow-wrap: anywhere;
}
.record-info strong {
    font-size: 14px;
}
.record-info > small {
    display: block;
    font-size: 12px;
    margin-top: 5px;
}
.color-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
input[type='color'] {
    width: 48px;
    height: 40px;
    padding: 4px;
    flex-shrink: 0;
    cursor: pointer;
}
.tag-preview {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    overflow-wrap: anywhere;
    min-width: 0;
}
.color-dot {
    width: 12px;
    height: 12px;
    border: 1px solid var(--border);
    border-radius: 50%;
    flex-shrink: 0;
}
@media (max-width: 650px) {
    .content {
        padding: 24px 16px;
    }
    .editor {
        padding: 16px;
    }
    .records li {
        padding: 14px 16px;
    }
    .tabs button {
        padding: 12px 8px;
        font-size: 13px;
    }
}
</style>

<style scoped>
.record-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}
.notice {
    color: var(--success);
    background: var(--success-soft);
    border-radius: 8px;
    padding: 12px;
}
.form-actions {
    justify-content: flex-end;
}
</style>

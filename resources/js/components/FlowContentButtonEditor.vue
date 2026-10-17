<script setup lang="ts">
import { computed, ref } from 'vue';
import { ExternalLink, Copy, MessageSquare } from '@lucide/vue';
import AppSelect from '@/components/AppSelect.vue';
import ContactTemplateInput from '@/components/ContactTemplateInput.vue';
import {
    templateDisplay,
    hasContactFields,
    type ContactFields,
} from '@/lib/contactTemplate';
import {
    contentButtonErrors,
    type ContentButtonItem,
} from '@/lib/flowContentButton';

const props = defineProps<{
    item: ContentButtonItem;
    fields?: ContactFields;
    workspaceId: number;
    errors: Record<string, string>;
}>();
const emit = defineEmits<{ change: [] }>();
const touched = ref(false);
const kindError = ref('');
function changeKind(kind: string) {
    if (
        kind === 'reply' &&
        (hasContactFields(props.item.text) ||
            hasContactFields(button.value.label))
    ) {
        kindError.value =
            'Remova os campos da mensagem e do rótulo antes de escolher Resposta.';
        return;
    }
    kindError.value = '';
    button.value.kind = kind as 'url' | 'reply' | 'copy';
    changed();
}
const button = computed(
    () => props.item.button || { kind: 'url' as const, label: '' },
);
const errors = computed<Record<string, string>>(() => ({
    ...(touched.value ? contentButtonErrors(props.item) : {}),
    ...props.errors,
    ...(kindError.value ? { kind: kindError.value } : {}),
}));
const icon = computed(
    () =>
        ({ url: ExternalLink, reply: MessageSquare, copy: Copy })[
            button.value.kind || 'url'
        ],
);
function changed() {
    props.item.button = button.value;
    if (button.value.kind !== 'url') delete props.item.button.url;
    if (button.value.kind !== 'copy') delete props.item.button.copy_text;
    touched.value = true;
    emit('change');
}
</script>

<template>
    <div class="content-button-editor">
        <label class="field-label" :for="`${item.id}-text`"
            >Texto da mensagem
            <ContactTemplateInput
                :workspace-id="workspaceId"
                :fields="fields"
                :chips="button.kind !== 'reply'"
                :id="`${item.id}-text`"
                aria-label="Texto da mensagem"
                v-model="item.text"
                rows="3"
                maxlength="4000"
                :aria-invalid="!!errors.text"
                :aria-describedby="
                    errors.text ? `${item.id}-text-error` : undefined
                "
                placeholder="Escreva a mensagem que acompanha o botão…"
                @input="changed"
            />
        </label>
        <p
            v-if="errors.text"
            :id="`${item.id}-text-error`"
            class="field-error"
            role="alert"
        >
            {{ errors.text }}
        </p>
        <label class="field-label" :for="`${item.id}-kind`"
            >Tipo de botão
            <AppSelect
                :id="`${item.id}-kind`"
                :model-value="button.kind"
                @update:model-value="changeKind"
                aria-label="Tipo de botão"
                :aria-invalid="!!errors.kind"
                :aria-describedby="`${item.id}-kind-help${errors.kind ? ` ${item.id}-kind-error` : ''}`"
            >
                <option value="url">URL</option>
                <option value="reply">Resposta</option>
                <option value="copy">Copiar</option>
            </AppSelect>
        </label>
        <p
            v-if="errors.kind"
            :id="`${item.id}-kind-error`"
            class="field-error"
            role="alert"
        >
            {{ errors.kind }}
        </p>
        <p :id="`${item.id}-kind-help`" class="button-help">
            <template v-if="button.kind === 'reply'">{{
                'O clique envia uma resposta do contato. Para aguardar essa resposta, utilize o bloco Aguardar Resposta. Para criar caminhos diferentes, utilize o bloco Menu.'
            }}</template>
            <template v-else-if="button.kind === 'copy'"
                >Copia o texto no WhatsApp. O fluxo continua sem aguardar a
                ação.</template
            >
            <template v-else
                >Abre o endereço no WhatsApp. O fluxo continua sem aguardar o
                clique.</template
            >
        </p>
        <label class="field-label" :for="`${item.id}-label`"
            >Texto do botão
            <ContactTemplateInput
                :workspace-id="workspaceId"
                :fields="fields"
                :chips="button.kind !== 'reply'"
                :rows="1"
                :id="`${item.id}-label`"
                aria-label="Texto do botão"
                v-model="button.label"
                maxlength="120"
                :aria-invalid="!!errors.label"
                :aria-describedby="
                    errors.label ? `${item.id}-label-error` : undefined
                "
                placeholder="Ex.: Ver cursos"
                @input="changed"
            />
        </label>
        <p
            v-if="errors.label"
            :id="`${item.id}-label-error`"
            class="field-error"
            role="alert"
        >
            {{ errors.label }}
        </p>
        <template v-if="button.kind === 'url'">
            <label class="field-label" :for="`${item.id}-url`"
                >Endereço URL
                <ContactTemplateInput
                    :workspace-id="workspaceId"
                    :fields="fields"
                    :chips="button.kind !== 'reply'"
                    :rows="1"
                    :id="`${item.id}-url`"
                    aria-label="Endereço URL"
                    v-model="button.url"
                    placeholder="https://exemplo.com/cursos"
                    maxlength="4000"
                    :aria-invalid="!!errors.url"
                    :aria-describedby="
                        errors.url ? `${item.id}-url-error` : undefined
                    "
                    @input="changed"
                />
            </label>
            <p
                v-if="errors.url"
                :id="`${item.id}-url-error`"
                class="field-error"
                role="alert"
            >
                {{ errors.url }}
            </p>
        </template>
        <template v-else-if="button.kind === 'copy'">
            <label class="field-label" :for="`${item.id}-copy`"
                >Texto para copiar
                <ContactTemplateInput
                    :workspace-id="workspaceId"
                    :fields="fields"
                    :rows="1"
                    :id="`${item.id}-copy`"
                    aria-label="Texto para copiar"
                    v-model="button.copy_text"
                    placeholder="Ex.: CUPOM10"
                    maxlength="4000"
                    :aria-invalid="!!errors.copy_text"
                    :aria-describedby="
                        errors.copy_text ? `${item.id}-copy-error` : undefined
                    "
                    @input="changed"
                />
            </label>
            <p
                v-if="errors.copy_text"
                :id="`${item.id}-copy-error`"
                class="field-error"
                role="alert"
            >
                {{ errors.copy_text }}
            </p>
        </template>
        <div class="button-preview" aria-label="Prévia do elemento Botão">
            <small>Prévia da mensagem</small>
            <p>
                {{
                    templateDisplay(item.text, fields).trim() ||
                    'Sua mensagem aparecerá aqui'
                }}
            </p>
            <div class="preview-action">
                <component :is="icon" :size="15" /><span>{{
                    templateDisplay(button.label, fields).trim() ||
                    'Texto do botão'
                }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.content-button-editor {
    display: grid;
    gap: 12px;
    min-width: 0;
}
.field-label {
    display: grid;
    gap: 6px;
    font-size: 14px;
}
.button-help,
.button-preview small {
    color: var(--muted-foreground);
    font-size: 12px;
    line-height: 1.5;
}
.field-error {
    color: var(--destructive);
    font-size: 12px;
    overflow-wrap: anywhere;
}
.button-preview {
    padding: 12px;
    border: 1px solid var(--border);
    background: var(--muted);
    border-radius: 8px;
    min-width: 0;
}
.button-preview p {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    margin: 8px 0 12px;
    font-size: 14px;
}
.preview-action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-top: 1px solid var(--border);
    padding-top: 10px;
    color: var(--brand-strong);
    font-size: 14px;
}
.preview-action span {
    overflow-wrap: anywhere;
    min-width: 0;
}
.preview-action svg {
    flex-shrink: 0;
}
</style>

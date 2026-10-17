<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ChevronUp, ChevronDown, Plus, Trash2 } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppIconButton from '@/components/AppIconButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { menuErrors, type FlowMenuData } from '@/lib/flowMenu';

const props = defineProps<{
    data: FlowMenuData;
    nodeId: string;
    errors: Record<string, string>;
}>();
const emit = defineEmits<{ change: []; remove: [index: number] }>();
const touched = ref(false);
const unit = ref(60);
const settingsOpen = ref(false);
watch(
    () => props.nodeId,
    () => {
        touched.value = false;
        settingsOpen.value = false;
        unit.value = 60;
    },
);
const errors = computed(() => ({
    ...(touched.value ? menuErrors(props.data) : {}),
    ...props.errors,
}));
const duration = computed({
    get: () => (props.data.timeout_seconds ?? 300) / unit.value,
    set: (value: number) => {
        props.data.timeout_seconds = value * unit.value;
        changed();
    },
});
function changed() {
    touched.value = true;
    emit('change');
}
function add() {
    (props.data.options ??= []).push({ id: crypto.randomUUID(), label: '' });
    changed();
}
function move(index: number, direction: number) {
    const options = props.data.options || [];
    if (index + direction < 0 || index + direction >= options.length) return;
    [options[index], options[index + direction]] = [
        options[index + direction],
        options[index],
    ];
    changed();
}
</script>

<template>
    <section class="menu-config">
        <h2>Menu para o contato</h2>
        <label class="field-label" for="menu-format"
            >Formato
            <AppSelect
                id="menu-format"
                :aria-invalid="!!errors.menu_format"
                :aria-describedby="
                    errors.menu_format ? 'menu-format-error' : undefined
                "
                aria-label="Formato"
                v-model="data.menu_format"
                @change="changed"
            >
                <option value="numeric">Numérico</option>
                <option value="button">Botões</option>
                <option value="list">Lista</option>
            </AppSelect>
        </label>
        <small v-if="data.menu_format === 'numeric'"
            >O contato responde somente com o número da opção.</small
        >
        <small v-else
            >O contato precisa selecionar uma opção pelo toque. Publicação
            depende da homologação da conexão WhatsApp.</small
        >
        <p
            v-if="errors.menu_format"
            id="menu-format-error"
            class="field-error"
            role="alert"
        >
            {{ errors.menu_format }}
        </p>
        <label class="field-label" for="menu-question"
            >Pergunta para o contato
            <Textarea
                id="menu-question"
                v-model="data.question"
                rows="3"
                maxlength="4000"
                placeholder="Como podemos ajudar?"
                :aria-invalid="!!errors.question"
                :aria-describedby="
                    errors.question ? 'menu-question-error' : undefined
                "
                @input="changed"
            />
        </label>
        <p
            v-if="errors.question"
            id="menu-question-error"
            class="field-error"
            role="alert"
        >
            {{ errors.question }}
        </p>
        <label
            v-if="data.menu_format === 'list'"
            class="field-label"
            for="menu-list-button"
            >Texto do botão de abertura
            <Input
                id="menu-list-button"
                :aria-invalid="!!errors.list_button_text"
                :aria-describedby="
                    errors.list_button_text
                        ? 'menu-list-button-error'
                        : undefined
                "
                v-model="data.list_button_text"
                maxlength="120"
                @input="changed"
            />
        </label>
        <p
            v-if="errors.list_button_text"
            id="menu-list-button-error"
            class="field-error"
            role="alert"
        >
            {{ errors.list_button_text }}
        </p>
        <h3>Opções</h3>
        <small
            >Conecte cada opção ao próximo bloco no canvas. Reordenar preserva
            as conexões.</small
        >
        <p v-if="!data.options?.length" class="empty-options">
            Adicione as escolhas que o contato poderá selecionar.
        </p>
        <p v-if="errors.options" class="field-error" role="alert">
            {{ errors.options }}
        </p>
        <div
            v-for="(option, index) in data.options"
            :key="option.id"
            class="menu-option"
        >
            <label class="field-label" :for="`menu-option-${option.id}`"
                >Opção {{ index + 1 }}
                <Input
                    :id="`menu-option-${option.id}`"
                    v-model="option.label"
                    maxlength="120"
                    placeholder="Ex.: Financeiro"
                    :aria-invalid="!!errors[`options.${index}.label`]"
                    :aria-describedby="
                        errors[`options.${index}.label`]
                            ? `menu-option-error-${option.id}`
                            : undefined
                    "
                    @input="changed"
                />
            </label>
            <p
                v-if="errors[`options.${index}.label`]"
                :id="`menu-option-error-${option.id}`"
                class="field-error"
                role="alert"
            >
                {{ errors[`options.${index}.label`] }}
            </p>
            <div class="option-actions">
                <AppIconButton
                    :label="`Mover opção ${index + 1} para cima`"
                    :disabled="index === 0"
                    @click="move(index, -1)"
                    ><ChevronUp :size="16"
                /></AppIconButton>
                <AppIconButton
                    :label="`Mover opção ${index + 1} para baixo`"
                    :disabled="index === (data.options?.length || 0) - 1"
                    @click="move(index, 1)"
                    ><ChevronDown :size="16"
                /></AppIconButton>
                <AppIconButton
                    :label="`Remover opção ${index + 1}`"
                    @click="emit('remove', index)"
                    ><Trash2 :size="16"
                /></AppIconButton>
            </div>
        </div>
        <AppButton variant="secondary" @click="add"
            ><Plus :size="16" />Adicionar opção</AppButton
        >
        <details
            :open="
                settingsOpen ||
                !!errors.timeout_seconds ||
                !!errors.max_invalid_replies ||
                !!errors.invalid_reply_text
            "
            @toggle="settingsOpen = ($event.target as HTMLDetailsElement).open"
        >
            <summary>
                Espera e respostas inválidas<ChevronDown
                    :size="16"
                    aria-hidden="true"
                />
            </summary>
            <label class="field-label" for="menu-timeout"
                >Tempo máximo de espera
                <div class="duration-fields">
                    <Input
                        id="menu-timeout"
                        aria-label="Tempo máximo de espera"
                        v-model.number="duration"
                        type="number"
                        :min="60 / unit"
                        :max="604800 / unit"
                        :step="60 / unit"
                        :aria-invalid="!!errors.timeout_seconds"
                        :aria-describedby="
                            errors.timeout_seconds
                                ? 'menu-timeout-error'
                                : undefined
                        "
                        @input="changed"
                    />
                    <AppSelect
                        v-model.number="unit"
                        aria-label="Unidade do tempo de espera"
                        ><option :value="60">Minutos</option>
                        <option :value="3600">Horas</option>
                        <option :value="86400">Dias</option></AppSelect
                    >
                </div>
            </label>
            <small
                >De 1 minuto a 7 dias. Repetir o menu não renova este
                prazo.</small
            >
            <p
                v-if="errors.timeout_seconds"
                id="menu-timeout-error"
                class="field-error"
                role="alert"
            >
                {{ errors.timeout_seconds }}
            </p>
            <label class="field-label" for="menu-invalid-limit"
                >Limite de respostas inválidas
                <Input
                    id="menu-invalid-limit"
                    v-model.number="data.max_invalid_replies"
                    type="number"
                    min="1"
                    max="10"
                    :aria-invalid="!!errors.max_invalid_replies"
                    :aria-describedby="
                        errors.max_invalid_replies
                            ? 'menu-invalid-limit-error'
                            : undefined
                    "
                    @input="changed"
                />
            </label>
            <p
                v-if="errors.max_invalid_replies"
                id="menu-invalid-limit-error"
                class="field-error"
                role="alert"
            >
                {{ errors.max_invalid_replies }}
            </p>
            <label class="field-label" for="menu-invalid-text"
                >Mensagem para resposta inválida
                <Textarea
                    id="menu-invalid-text"
                    v-model="data.invalid_reply_text"
                    maxlength="4000"
                    rows="3"
                    :aria-invalid="!!errors.invalid_reply_text"
                    :aria-describedby="
                        errors.invalid_reply_text
                            ? 'menu-invalid-text-error'
                            : undefined
                    "
                    @input="changed"
                />
            </label>
            <p
                v-if="errors.invalid_reply_text"
                id="menu-invalid-text-error"
                class="field-error"
                role="alert"
            >
                {{ errors.invalid_reply_text }}
            </p>
            <small
                >Antes do limite, envia esta mensagem e repete as opções. Ao
                esgotar o prazo ou as respostas inválidas, segue por
                Continuar.</small
            >
        </details>
    </section>
</template>

<style scoped>
.menu-config {
    display: grid;
    gap: 12px;
}
.menu-config h2 {
    font-size: 15px;
    font-weight: 600;
}
.menu-config h3 {
    font-size: 14px;
    font-weight: 600;
    margin-top: 8px;
}
.field-label {
    display: grid;
    gap: 6px;
    font-size: 13px;
}
.menu-config small,
.empty-options {
    color: var(--muted-foreground);
    font-size: 12px;
    line-height: 1.5;
}
.menu-option {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    display: grid;
    gap: 8px;
}
.option-actions {
    display: flex;
    justify-content: flex-end;
    gap: 4px;
}
.field-error {
    color: var(--destructive);
    font-size: 12px;
    line-height: 1.5;
}
.duration-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 8px;
}
.menu-config details {
    border-top: 1px solid var(--border);
    margin-top: 8px;
    padding-top: 12px;
}
.menu-config details[open] {
    display: grid;
    gap: 12px;
}
.menu-config summary {
    cursor: pointer;
    font-size: 13px;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.menu-config details[open] summary svg {
    transform: rotate(180deg);
}
.menu-config summary:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>

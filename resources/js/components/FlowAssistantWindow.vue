<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Bot, X, Send, Square, RefreshCw } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppIconButton from '@/components/AppIconButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import { Textarea } from '@/components/ui/textarea';
import type { AssistantState } from '@/composables/useFlowAssistant';

const props = defineProps<{
    open: boolean;
    state: AssistantState | null;
    busy: boolean;
    running: boolean;
    error: string;
    models: string[];
    model: string;
}>();
const emit = defineEmits<{
    close: [];
    send: [content: string];
    approve: [plan: number];
    stop: [];
    resume: [];
    'update:model': [model: string];
    'load-models': [];
    older: [];
}>();
const content = ref('');
const panel = ref<HTMLElement>();
const composer = ref<HTMLTextAreaElement>();
const history = ref<HTMLElement>();
let previousFocus: HTMLElement | null = null;
const pending = computed(() =>
    props.state?.messages.findLast(
        (m) => m.kind === 'plan' && m.status === 'pending',
    ),
);
const permitted = computed(
    () => props.state?.can_use_ai === true && props.state.configured,
);
const disabled = computed(
    () => props.busy || props.running || !permitted.value,
);
const statuses: Record<string, string> = {
    pending: 'Aguardando aprovação',
    approved: 'Plano aprovado',
    temporary: 'Prévia não salva',
    saved: 'Salvo no rascunho',
    discarded: 'Descartado',
    superseded: 'Plano substituído',
};
function send() {
    if (!content.value.trim() || disabled.value) return;
    emit('send', content.value.trim());
}
function clearComposer() {
    content.value = '';
}
defineExpose({ clearComposer });
function close() {
    emit('close');
    previousFocus?.focus();
}
function keydown(event: KeyboardEvent) {
    if (!props.open || !panel.value) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
    // Keep keyboard navigation inside the floating conversation while it has focus.
    if (event.key === 'Tab') {
        const controls = [
            ...panel.value.querySelectorAll<HTMLElement>(
                'button:not(:disabled),textarea:not(:disabled),select:not(:disabled),a[href]',
            ),
        ];
        const first = controls[0],
            last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    }
}
watch(
    () => props.open,
    async (open) => {
        if (open) {
            previousFocus = document.activeElement as HTMLElement;
            emit('load-models');
            await nextTick();
            panel.value?.querySelector<HTMLElement>('textarea,button')?.focus();
        }
    },
);
watch(
    () => props.state?.messages.at(-1)?.id,
    async () => {
        await nextTick();
        if (history.value) history.value.scrollTop = history.value.scrollHeight;
    },
);
function globalEscape(event: KeyboardEvent) {
    if (
        props.open &&
        event.key === 'Escape' &&
        !event.defaultPrevented &&
        !document.querySelector('[role="dialog"][aria-modal="true"]')
    ) {
        event.preventDefault();
        close();
    }
}
onMounted(() => document.addEventListener('keydown', globalEscape));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', globalEscape);
    previousFocus = null;
});
</script>

<template>
    <section
        v-if="open"
        ref="panel"
        class="flow-assistant-window"
        role="dialog"
        aria-labelledby="assistant-title"
        @keydown="keydown"
    >
        <header>
            <div>
                <Bot :size="20" />
                <h2 id="assistant-title">Assistente IA</h2>
            </div>
            <AppIconButton label="Recolher assistente" @click="close"
                ><X :size="18"
            /></AppIconButton>
        </header>
        <p class="assistant-help">
            Conversa e prévia compartilhadas com a equipe. Revise e salve o
            resultado no editor.
        </p>
        <div
            ref="history"
            class="assistant-history"
            aria-live="polite"
            aria-relevant="additions"
        >
            <AppButton
                v-if="state?.messages.length"
                variant="ghost"
                @click="emit('older')"
                >Ver mensagens anteriores</AppButton
            >
            <p v-if="!state?.messages.length" class="assistant-empty">
                Descreva o atendimento que deseja criar ou a alteração que
                precisa fazer.
            </p>
            <article
                v-for="message in state?.messages"
                :key="message.id"
                :class="['assistant-message', message.role, message.kind]"
            >
                <small>{{
                    message.role === 'user'
                        ? message.author?.name || 'Equipe'
                        : message.role === 'assistant'
                          ? 'Assistente'
                          : 'Atualização'
                }}</small>
                <p>{{ message.content }}</p>
                <small v-if="message.status">{{
                    statuses[message.status] || message.status
                }}</small>
                <AppButton
                    v-if="message.id === pending?.id"
                    :disabled="disabled"
                    @click="emit('approve', message.id)"
                    >Construir</AppButton
                >
            </article>
            <p v-if="running" class="assistant-progress" role="status">
                A IA está trabalhando na prévia. A edição manual está pausada
                para a equipe.
            </p>
        </div>
        <div class="assistant-controls">
            <p
                v-if="error || state?.run?.error"
                role="alert"
                class="assistant-error"
            >
                {{ error || state?.run?.error }}
            </p>
            <p v-if="state && !state.can_use_ai" class="assistant-help">
                Você pode ler o histórico. Solicite a permissão “Utilizar
                Assistente IA” para conversar.
            </p>
            <p v-else-if="state && !state.configured" class="assistant-help">
                Configure a chave OpenAI nas
                <a href="/settings/openai">Configurações</a> para começar.
            </p>
            <label for="flow-assistant-model">Modelo do assistente</label>
            <AppSelect
                id="flow-assistant-model"
                :model-value="model"
                :disabled="disabled"
                @update:model-value="emit('update:model', String($event))"
            >
                <option value="auto">Automático</option>
                <option
                    v-for="available in models"
                    :key="available"
                    :value="available"
                >
                    {{ available }}
                </option>
                <option
                    v-if="model !== 'auto' && !models.includes(model)"
                    :value="model"
                >
                    {{ model }} — indisponível
                </option>
            </AppSelect>
            <form @submit.prevent="send">
                <Textarea
                    ref="composer"
                    v-model="content"
                    aria-label="Pedido para o Assistente IA"
                    placeholder="Descreva seu fluxo…"
                    :maxlength="8000"
                    :disabled="disabled"
                    @keydown.ctrl.enter.prevent="send"
                    @keydown.meta.enter.prevent="send"
                />
                <div class="assistant-actions">
                    <AppButton
                        v-if="running"
                        variant="secondary"
                        :disabled="busy"
                        @click="emit('stop')"
                        ><Square :size="14" />Parar</AppButton
                    >
                    <AppButton
                        v-else-if="state?.run?.status === 'paused'"
                        variant="secondary"
                        :disabled="disabled"
                        @click="emit('resume')"
                        ><RefreshCw :size="14" />Retomar</AppButton
                    >
                    <AppButton
                        type="submit"
                        :disabled="disabled || !content.trim()"
                        ><Send :size="14" />{{
                            busy ? 'Enviando…' : 'Enviar'
                        }}</AppButton
                    >
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
.flow-assistant-window {
    position: fixed;
    z-index: 45;
    right: 24px;
    bottom: 24px;
    width: min(440px, calc(100vw - 32px));
    height: min(700px, calc(100dvh - 100px));
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--card);
    color: var(--foreground);
    box-shadow: 0 12px 48px #0003;
    overflow: hidden;
}
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
}
header > div {
    display: flex;
    gap: 10px;
    align-items: center;
}
h2 {
    font-size: 15px;
    font-weight: 600;
}
.assistant-help {
    font-size: 12px;
    line-height: 1.6;
    color: var(--muted-foreground);
    margin: 12px 18px;
}
.assistant-history {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 0 18px 16px;
}
.assistant-empty {
    padding: 20px 0;
    font-size: 14px;
    line-height: 1.7;
    color: var(--muted-foreground);
}
.assistant-message {
    border: 1px solid var(--border);
    background: var(--secondary);
    padding: 12px;
    border-radius: 10px;
    margin-top: 12px;
    overflow-wrap: anywhere;
}
.assistant-message.user {
    margin-left: 24px;
}
.assistant-message.assistant {
    background: var(--card);
    margin-right: 12px;
}
.assistant-message.system {
    background: transparent;
    border-style: dashed;
}
.assistant-message small {
    display: block;
    font-size: 11px;
    color: var(--muted-foreground);
}
.assistant-message p {
    white-space: pre-wrap;
    font-size: 13px;
    line-height: 1.7;
    margin: 7px 0;
}
.assistant-message button {
    margin-top: 10px;
}
.assistant-controls {
    border-top: 1px solid var(--border);
    padding: 14px 18px;
    display: grid;
    gap: 8px;
}
.assistant-controls label {
    font-size: 12px;
}
.assistant-controls textarea {
    margin-top: var(--form-label-gap);
    max-height: 130px;
    min-height: 80px;
}
.assistant-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--form-button-gap);
    justify-content: flex-end;
    margin-top: var(--form-actions-space);
}
.assistant-error {
    color: var(--danger);
    font-size: 12px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.assistant-progress {
    font-size: 12px;
    color: var(--muted-foreground);
    padding: 12px 0;
}
@media (max-width: 640px) {
    .flow-assistant-window {
        inset: 0;
        height: 100dvh;
        width: 100%;
        border-radius: 0;
    }
    .assistant-controls {
        padding-bottom: max(16px, env(safe-area-inset-bottom));
    }
}
</style>

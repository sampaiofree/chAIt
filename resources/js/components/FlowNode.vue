<script setup lang="ts">
import { templateDisplay, type ContactTemplate } from '@/lib/contactTemplate';
import {
    Plus,
    MessageSquare,
    MousePointer2,
    Clock3,
    Image as ImageIcon,
    Video,
    Mic,
    FileText,
} from '@lucide/vue';
import { Handle, Position, useVueFlow } from '@vue-flow/core';
import {
    conditionCheckSummary,
    type ConditionCheck,
} from '@/lib/flowCondition';
import type { ContentButton } from '@/lib/flowContentButton';
import { computed, watch, nextTick } from 'vue';
import { flowPendingReasons } from '@/lib/flowPending';
import { flowOutputs, menuErrors, type FlowMenuData } from '@/lib/flowMenu';
import type { FlowCatalog } from '@/composables/useFlowAssistant';

type PreviewItem = {
    id: string;
    kind: string;
    button?: ContentButton;
    text?: ContactTemplate;
    original_name?: string;
    media_type?: string;
    caption?: ContactTemplate;
    seconds?: number;
};
const props = defineProps<{
    catalog: FlowCatalog;
    id: string;
    data: FlowMenuData & {
        label: string;
        description?: string;
        items?: PreviewItem[];
        nodeType: string;
        ai_prompt?: ContactTemplate;
        timeout_seconds?: number;
        field_id?: number | null;
        actions?: { kind: string; [key: string]: unknown }[];
        ai_media?: Record<string, unknown>[];
        ai_tags?: Record<string, unknown>[];
        ai_fields?: Record<string, unknown>[];
        checks?: ConditionCheck[];
        tag_ids?: number[];
        condition_operator?: string;
        target_flow_id?: number | null;
        ai_inactivity_seconds?: number;
    };
    reference?: {
        fields?: { id: number; name: string; type?: string }[];
        tags?: { id: number; name: string }[];
        flows?: { id: number; name: string }[];
        members?: { id: number }[];
        sequences?: { id: number }[];
    };
}>();
const pendingReasons = computed(() =>
    flowPendingReasons(props.data.nodeType, props.data, props.reference || {}),
);
const kind = computed(
    () =>
        ({
            start: 'Início',
            content: 'Conteúdo',
            wait_reply: 'Aguardar resposta',
            operations: 'Operações',
            jump_flow: 'Ir para fluxo',
            ai: 'IA',
            condition: 'Condição',
            menu: 'Menu',
        })[props.data.nodeType] ?? 'Bloco',
);
const previewItems = computed(() => (props.data.items ?? []).slice(0, 2));
const remaining = computed(() =>
    Math.max(0, (props.data.items?.length ?? 0) - 2),
);
function duration(seconds: number) {
    for (const [unit, singular, plural] of [
        [86400, 'dia', 'dias'],
        [3600, 'hora', 'horas'],
        [60, 'minuto', 'minutos'],
        [1, 'segundo', 'segundos'],
    ] as const) {
        if (seconds > 0 && seconds % unit === 0) {
            const amount = seconds / unit;
            return `${amount} ${amount === 1 ? singular : plural}`;
        }
    }
    return 'prazo definido';
}
const operationLabels: Record<string, string> = {
    add_to_sequence: 'Adicionar à sequência',
    add_tag: 'Adicionar etiqueta',
    remove_tag: 'Remover etiqueta',
    set_field: 'Atualizar campo',
    notify_member: 'Notificar membro por WhatsApp',
    open_human: 'Abrir atendimento humano',
    pause_automation: 'Pausar automações',
};
const summary = computed(() => {
    const data = props.data;
    switch (data.nodeType) {
        case 'start':
            return 'Início da sequência do fluxo.';
        case 'wait_reply': {
            const field = props.reference?.fields?.find(
                (item) => item.id === data.field_id,
            );
            const destination = field
                ? `Salvar resposta em ${field.name}.`
                : data.field_id
                  ? 'Salvar resposta no campo selecionado.'
                  : 'Escolha onde salvar a resposta.';
            return `${destination} Prazo: ${duration(data.timeout_seconds ?? 86400)}.`;
        }
        case 'operations': {
            const actions = data.actions ?? [];
            if (!actions.length)
                return 'Adicione uma operação para este bloco.';
            return (
                actions
                    .slice(0, 2)
                    .map(
                        (action) =>
                            operationLabels[action.kind] ??
                            'Operação configurada',
                    )
                    .join(' · ') +
                (actions.length > 2
                    ? ` · +${actions.length - 2} operação(ões)`
                    : '')
            );
        }
        case 'condition': {
            const checks = data.checks || [];
            if (!checks.length) return 'Adicione uma verificação.';
            return `${checks.length} ${checks.length === 1 ? 'verificação' : 'verificações'} · ${data.condition_operator === 'any' ? 'Pelo menos uma (OU)' : 'Todas (E)'}`;
        }
        case 'jump_flow':
            return data.target_flow_id
                ? `Ir para ${props.reference?.flows?.find((flow) => flow.id === data.target_flow_id)?.name ?? 'o fluxo selecionado'}.`
                : 'Escolha o fluxo de destino.';
        case 'ai':
            return templateDisplay(data.ai_prompt).trim()
                ? `Conversa com IA configurada. Inatividade: ${duration(data.ai_inactivity_seconds ?? 3600)}.`
                : 'Defina as instruções para a conversa com IA.';
        default:
            return 'Configure as ações deste bloco.';
    }
});
function mediaLabel(item: PreviewItem) {
    return (
        {
            image: 'Imagem',
            video: 'Vídeo',
            audio: 'Áudio',
            document: 'Documento',
        }[item.media_type ?? ''] ?? 'Arquivo'
    );
}
function mediaIcon(item: PreviewItem) {
    return (
        { image: ImageIcon, video: Video, audio: Mic }[item.media_type ?? ''] ??
        FileText
    );
}
const { updateNodeInternals } = useVueFlow();
watch(
    () =>
        props.data.options?.map((option) => option.id + option.label).join('|'),
    async () => {
        await nextTick();
        updateNodeInternals([props.id]);
    },
);
const emits = defineEmits<{ add: [nodeId: string, handle: string] }>();
const exits = computed(() => [
    ...flowOutputs(props.catalog, props.data.nodeType, props.data),
    ...(props.data.nodeType === 'ai'
        ? [{ id: 'human', label: 'Atendimento Humanizado' }]
        : []),
]);
</script>

<template>
    <article class="flow-node" :class="`node-${data.nodeType}`">
        <Handle
            v-if="data.nodeType !== 'start'"
            id="input"
            type="target"
            :position="Position.Top"
        />
        <header class="node-header">
            <small>{{ kind }}</small
            ><strong :title="data.label">{{ data.label }}</strong>
        </header>
        <div v-if="data.nodeType === 'content'" class="node-preview">
            <p v-if="!previewItems.length" class="node-empty">
                Adicione uma mensagem ou arquivo
            </p>
            <div
                v-for="item in previewItems"
                :key="item.id"
                class="preview-item"
                :data-kind="item.kind"
            >
                <template v-if="item.kind === 'text'"
                    ><span class="preview-label"
                        ><MessageSquare :size="12" />Mensagem</span
                    >
                    <p
                        class="message-preview"
                        :class="{
                            'preview-empty': !templateDisplay(
                                item.text,
                                catalog.contact_fields,
                            ).trim(),
                        }"
                    >
                        <span class="preview-text">{{
                            templateDisplay(
                                item.text,
                                catalog.contact_fields,
                            ).trim()
                                ? templateDisplay(
                                      item.text,
                                      catalog.contact_fields,
                                  )
                                : 'Escreva o texto da mensagem'
                        }}</span>
                    </p></template
                >
                <template v-else-if="item.kind === 'delay'"
                    ><span class="preview-label"
                        ><Clock3 :size="12" />Espera</span
                    >
                    <p class="action-preview">
                        <span class="preview-text"
                            >Aguardar {{ duration(item.seconds ?? 3) }}</span
                        >
                    </p></template
                >
                <template v-else-if="item.kind === 'button'">
                    <span class="preview-label"
                        ><MousePointer2 :size="12" />Botão ·
                        {{
                            { url: 'URL', reply: 'Resposta', copy: 'Copiar' }[
                                item.button?.kind || 'url'
                            ]
                        }}</span
                    >
                    <p class="message-preview">
                        <span class="preview-text">{{
                            templateDisplay(
                                item.text,
                                catalog.contact_fields,
                            ).trim() || 'Escreva o texto da mensagem'
                        }}</span>
                    </p>
                    <p class="content-button-preview">
                        {{
                            templateDisplay(
                                item.button?.label,
                                catalog.contact_fields,
                            ).trim() || 'Texto do botão'
                        }}
                    </p>
                </template>
                <template v-else
                    ><span class="preview-label"
                        ><component :is="mediaIcon(item)" :size="12" />{{
                            mediaLabel(item)
                        }}</span
                    >
                    <p class="action-preview">
                        <span class="preview-text">{{
                            item.original_name || 'Selecione um arquivo'
                        }}</span>
                    </p>
                    <p
                        v-if="
                            templateDisplay(
                                item.caption,
                                catalog.contact_fields,
                            ).trim()
                        "
                        class="caption-preview"
                    >
                        Legenda:
                        {{
                            templateDisplay(
                                item.caption,
                                catalog.contact_fields,
                            )
                        }}
                    </p></template
                >
            </div>
            <p
                v-if="
                    data.items?.slice(2).some((item) => item.kind === 'button')
                "
                class="preview-label"
            >
                <MousePointer2 :size="12" />Inclui Botão ·
                {{
                    data.items?.find((item) => item.kind === 'button')?.button
                        ?.label
                        ? templateDisplay(
                              data.items?.find((item) => item.kind === 'button')
                                  ?.button?.label,
                              catalog.contact_fields,
                          )
                        : 'Configure o botão'
                }}
            </p>
            <small v-if="remaining" class="remaining-items"
                >+ {{ remaining }}
                {{
                    remaining === 1 ? 'item na sequência' : 'itens na sequência'
                }}</small
            >
        </div>
        <div v-else-if="data.nodeType === 'menu'" class="node-preview">
            <small class="preview-label">{{
                { numeric: 'Numérico', button: 'Botões', list: 'Lista' }[
                    data.menu_format || 'numeric'
                ]
            }}</small>
            <p class="message-preview preview-text">
                {{ data.question || 'Adicione uma pergunta para o contato' }}
            </p>
            <small class="remaining-items"
                >Continuar após {{ duration(data.timeout_seconds || 300) }} ou
                {{ data.max_invalid_replies || 3 }} respostas inválidas.</small
            >
            <small
                v-if="Object.keys(menuErrors(data)).length"
                class="condition-pending"
                >Configuração pendente</small
            >
        </div>
        <div
            v-else-if="data.nodeType === 'condition'"
            class="condition-preview"
        >
            <p class="node-summary">{{ summary }}</p>
            <p
                v-for="(check, index) in data.checks?.slice(0, 2)"
                :key="index"
                class="condition-preview-line"
                :title="conditionCheckSummary(check, reference || {})"
            >
                {{ conditionCheckSummary(check, reference || {}) }}
            </p>
            <small v-if="(data.checks?.length || 0) > 2"
                >+ {{ (data.checks?.length || 0) - 2 }} verificações</small
            >
            <small v-if="pendingReasons.length" class="condition-pending"
                >Configuração pendente</small
            >
        </div>
        <p v-else class="node-summary">{{ summary }}</p>
        <small
            v-if="
                pendingReasons.length &&
                !['condition', 'menu'].includes(data.nodeType)
            "
            class="node-pending"
            :title="pendingReasons.join(' ')"
            >Configuração pendente</small
        >
        <div v-if="exits.length" class="node-exits">
            <div
                v-for="(exit, index) in exits"
                :key="exit.id"
                class="node-exit"
            >
                <span>{{ exit.label }}</span>
                <span
                    v-if="exit.id === 'human'"
                    class="terminal-mark"
                    aria-label="Saída terminal"
                    >●</span
                >
                <button
                    v-if="exit.id !== 'human'"
                    class="add-step"
                    :aria-label="`Adicionar bloco após ${exit.label}`"
                    :title="`Adicionar bloco após ${exit.label}`"
                    @click.stop="emits('add', id, exit.id)"
                >
                    <Plus :size="14" />
                </button>
                <Handle
                    v-if="exit.id !== 'human'"
                    :id="exit.id"
                    type="source"
                    :position="
                        data.nodeType === 'menu'
                            ? Position.Right
                            : Position.Bottom
                    "
                    :style="{
                        top: data.nodeType === 'menu' ? '50%' : undefined,
                        left:
                            data.nodeType === 'menu'
                                ? undefined
                                : exits.length === 1
                                  ? '50%'
                                  : index === 0
                                    ? '25%'
                                    : '75%',
                    }"
                />
            </div>
        </div>
    </article>
</template>

<style scoped>
.node-pending {
    display: block;
    margin-top: 8px;
    color: var(--destructive);
    font-size: 11px;
}

.content-button-preview {
    color: var(--brand-strong);
    border-top: 1px solid var(--border);
    margin-top: 6px;
    padding-top: 6px;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.condition-preview-line {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    margin: 6px 14px;
}
.condition-preview > small {
    display: block;
    margin: 6px 14px 12px;
    color: var(--muted-foreground);
    font-size: 11px;
}
.condition-preview > .condition-pending {
    color: var(--destructive);
}

.flow-node {
    position: relative;
    width: 224px;
    padding: 15px 14px 10px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: var(--card);
    box-shadow: 0 5px 16px #1d402a0c;
    color: var(--foreground);
}
.node-header {
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 12px;
}
.node-header small {
    display: block;
    color: var(--muted-foreground);
    font-size: 10px;
    font-weight: 500;
    margin-bottom: 3px;
}
.node-header strong {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.5;
}
.node-preview {
    display: grid;
    gap: 10px;
}
.preview-label {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--muted-foreground);
    font-size: 10px;
    margin-bottom: 5px;
}
.preview-label svg {
    flex: none;
}
.message-preview {
    padding: 10px;
    border-radius: 8px 8px 8px 2px;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 13px;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    margin: 0;
}
.preview-text,
.caption-preview,
.node-summary {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
}
.action-preview {
    font-size: 12px;
    line-height: 1.5;
    color: var(--foreground);
    padding: 8px;
    background: var(--secondary);
    border-radius: var(--radius);
    margin: 0;
}
.caption-preview {
    font-size: 12px;
    line-height: 1.5;
    color: var(--muted-foreground);
    margin: 4px 0 0;
}
.node-summary,
.node-empty {
    font-size: 12px;
    line-height: 1.5;
    color: var(--muted-foreground);
    margin: 0;
}
.preview-empty {
    color: var(--muted-foreground);
    background: var(--secondary);
    font-style: italic;
}
.remaining-items {
    font-size: 11px;
    color: var(--muted-foreground);
}
.node-start {
    border-color: var(--primary);
}
.node-start .node-header small {
    color: var(--brand-strong);
}
.node-exits {
    display: flex;
    justify-content: space-around;
    gap: 10px;
    margin: 11px -4px 0;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}
.node-menu .node-exits {
    display: grid;
    gap: 4px;
}
.node-menu .node-exit {
    position: relative;
    justify-content: space-between;
    padding-right: 8px;
}
.node-menu .node-exit > span {
    overflow-wrap: anywhere;
}
.node-menu .condition-pending {
    color: var(--destructive);
    font-size: 12px;
}
.node-exit {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--muted-foreground);
    font-size: 11px;
}
.terminal-mark {
    color: var(--primary);
    font-size: 12px;
}
.add-step {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--secondary);
    color: var(--brand-strong);
    font-weight: 700;
    cursor: pointer;
}
.flow-node :deep(.vue-flow__handle) {
    width: 9px;
    height: 9px;
    background: var(--primary);
    border: 2px solid var(--card);
}
</style>

<style scoped>
@media (max-width: 1023px) {
    .add-step {
        width: 44px;
        height: 44px;
    }
}
</style>

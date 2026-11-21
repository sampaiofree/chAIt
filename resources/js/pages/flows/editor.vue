<script setup lang="ts">
import AppNotice from '@/components/AppNotice.vue';
import AppFieldHelp from '@/components/AppFieldHelp.vue';
import { useMediaQuery } from '@vueuse/core';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import FlowExecutionEvents from '@/components/FlowExecutionEvents.vue';
import AiInactivityInput from '@/components/AiInactivityInput.vue';
import FlowFieldHelp from '@/components/FlowFieldHelp.vue';
import ContactTemplateInput from '@/components/ContactTemplateInput.vue';
import { type ContactTemplate } from '@/lib/contactTemplate';
import {
    conditionCheckErrors,
    standardConditionFields,
    type ConditionCheck,
} from '@/lib/flowCondition';
import FlowContentButtonEditor from '@/components/FlowContentButtonEditor.vue';
import type { ContentButton } from '@/lib/flowContentButton';
import FlowMenuEditor from '@/components/FlowMenuEditor.vue';
import FlowTransferActions, {
    type FlowImportResult,
} from '@/components/FlowTransferActions.vue';
import { flowPendingIssues } from '@/lib/flowPending';
import { flowOutputs, type FlowMenuData } from '@/lib/flowMenu';
import { Textarea } from '@/components/ui/textarea';
import {
    ArrowLeft,
    Maximize,
    Sparkles,
    GitBranch,
    SlidersHorizontal,
    Bot,
    Plus,
    Minus,
    X,
    ChevronUp,
    ChevronDown,
    Bold,
    Italic,
    Strikethrough,
} from '@lucide/vue';
import AppIconButton from '@/components/AppIconButton.vue';
import { Input } from '@/components/ui/input';
import AppSelect from '@/components/AppSelect.vue';
import FlowNode from '@/components/FlowNode.vue';
import FlowAssistantWindow from '@/components/FlowAssistantWindow.vue';
import {
    useFlowAssistant,
    type FlowCatalog,
    type FlowGraph,
    type AssistantState,
} from '@/composables/useFlowAssistant';
import FlowBlockHeader from '@/components/FlowBlockHeader.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppButton from '@/components/AppButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useAppDialog } from '@/composables/useAppDialog';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import {
    VueFlow,
    useVueFlow,
    type Connection,
    type Edge,
} from '@vue-flow/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';

type FlowKind =
    | 'start'
    | 'content'
    | 'wait_reply'
    | 'operations'
    | 'jump_flow'
    | 'condition'
    | 'ai'
    | 'menu';
type FlowItem = {
    id: string;
    kind: 'text' | 'media' | 'delay' | 'button';
    button?: ContentButton;
    text?: ContactTemplate;
    seconds?: number;
    asset_id?: number;
    media_type?: 'image' | 'video' | 'audio' | 'document';
    caption?: ContactTemplate;
    original_name?: string;
};
type FlowAction = {
    id: string;
    kind:
        | 'add_tag'
        | 'remove_tag'
        | 'set_field'
        | 'notify_member'
        | 'open_human'
        | 'pause_automation'
        | 'add_to_sequence';
    sequence_id?: number;
    tag_id?: number;
    field_id?: number;
    value?: string;
    user_id?: number;
    text?: string;
    pause_mode?: 'timed' | 'indefinite';
    pause_seconds?: number;
};
type AiMedia = { title?: string | null;
    id: string;
    asset_id?: number;
    media_type?: FlowItem['media_type'];
    original_name?: string;
    instruction: string;
};
type AiTag = { title?: string | null; id: string; tag_id?: number; instruction: string };
type AiField = { title?: string | null;
    id: string;
    field_id?: number;
    instruction: string;
    allow_overwrite: boolean;
};
type FlowData = FlowMenuData & {
    label: string;
    description: string;
    nodeType: FlowKind;
    messages?: { id: string; text: string }[];
    items?: FlowItem[];
    field_id?: number | null;
    timeout_seconds?: number;
    reply_grouping_seconds?: number;
    condition_operator?: 'all' | 'any';
    tag_ids?: number[];
    checks?: ConditionCheck[];
    target_flow_id?: number | null;
    actions?: FlowAction[];
    ai_initial_context?: ContactTemplate;
    ai_load_history?: boolean;
    ai_outputs?: { id: string; label: string; instruction: string }[];
    ai_prompt?: ContactTemplate;
    ai_model?: 'gpt-6-luna' | 'gpt-6.1-sol' | 'gpt-6-astra';
    ai_grouping_seconds?: number;
    ai_inactivity_seconds?: number;
    ai_expiry_action?: 'end' | 'human';
    ai_max_messages?: number | null;
    ai_limit_action?: 'continue' | 'human';
    ai_media?: AiMedia[];
    ai_tags?: AiTag[];
    ai_fields?: AiField[];
};
type FlowNodeRecord = {
    id: string;
    type: string;
    position: { x: number; y: number };
    data: FlowData;
};
type FlowEdgeRecord = {
    id: string;
    source: string;
    target: string;
    sourceHandle: string | null;
    targetHandle: string | null;
    type?: string;
    label?: string;
};
type Graph = {
    schema_version: 2;
    nodes: FlowNodeRecord[];
    edges: FlowEdgeRecord[];
    viewport: { x: number; y: number; zoom: number };
};
type FlowRecord = {
    id: number;
    folder_id: number | null;
    name: string;
    graph: Graph;
    revision: number;
};
const props = defineProps<{
    workspace: { id: number; name: string };
    flow: FlowRecord;
    catalog: FlowCatalog;
    reference: {
        tags: { id: number; name: string }[];
        fields: { id: number; name: string; type: string }[];
        members: { id: number; name: string; phone: string | null }[];
        flows: { id: number; name: string }[];
        inactive_flows: { id: number; name: string }[];
        sequences?: { id: number; name: string }[];
        assets: {
            id: number;
            original_name: string;
            mime_type: string;
            size: number;
            available?: boolean;
        }[];
        is_active: boolean;
    };
    recent_executions: {
        id: number;
        status: string;
        trigger_source: string;
        started_at: string;
        finished_at: string | null;
        error_message: string | null;
    }[];
}>();
const definitions = props.catalog.nodes
    .filter((node) => node.type !== 'start')
    .map((node) => ({ type: node.type as FlowKind, label: node.label }));
const { dialog, alert, confirm, prompt, finish } = useAppDialog();
const kindLabels = Object.fromEntries(
    props.catalog.nodes.map((node) => [node.type, node.label]),
) as Record<FlowKind, string>;
const { getViewport, fitView, zoomIn, zoomOut, screenToFlowCoordinate, findNode } = useVueFlow();
const reference = ref(props.reference);
const liveCatalog = ref(props.catalog);
const catalog = computed(() => ({
    ...liveCatalog.value,
    contact_fields: liveCatalog.value.contact_fields
        ? {
              ...liveCatalog.value.contact_fields,
              custom: reference.value.fields,
          }
        : undefined,
}));
const flowIsActive = ref(props.reference.is_active);
const editToken = crypto.randomUUID();
const editLocked = ref(false);
const editingBy = ref('');
const editLockError = ref('');
const canEdit = computed(() => !flowIsActive.value && editLocked.value);
let lockTimer: ReturnType<typeof setTimeout> | undefined;
let lockDisposed = false;
async function renewEditLock() {
    if (lockDisposed || flowIsActive.value) return;
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/flows/${props.flow.id}/edit-lock`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                body: JSON.stringify({ token: editToken }),
            },
        );
        const result = await response.json();
        editLocked.value = response.ok && result.data?.acquired === true;
        editingBy.value = result.data?.editor || '';
        editLockError.value =
            response.status === 422
                ? Object.values(result.errors || {})
                      .flat()
                      .join(' ') || result.message
                : '';
        if (editLocked.value && result.data?.converted) {
            revision.value = result.data.revision;
            hydrate(result.data.graph as Graph);
            serverGraphSnapshot = stableGraph(makeGraph(viewport.value));
            await alert(
                'Blocos IA convertidos: falhas, inatividade e limite de mensagens agora usam Continuar. Atendimento humano usa Operações.',
                'Saídas da IA atualizadas',
            );
        }
    } catch {
        editLocked.value = false;
    }
    if (!lockDisposed && !flowIsActive.value)
        lockTimer = setTimeout(renewEditLock, editLocked.value ? 20000 : 5000);
}
function releaseEditLock() {
    if (!editLocked.value) return;
    editLocked.value = false;
    void fetch(
        `/workspaces/${props.workspace.id}/flows/${props.flow.id}/edit-lock`,
        {
            method: 'DELETE',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content || '',
            },
            body: JSON.stringify({ token: editToken }),
        },
    );
}
onMounted(() => {
    void renewEditLock();
    window.addEventListener('pagehide', releaseEditLock);
});
onBeforeUnmount(() => {
    lockDisposed = true;
    clearTimeout(lockTimer);
    window.removeEventListener('pagehide', releaseEditLock);
    releaseEditLock();
});
const flowName = ref(props.flow.name);
const revision = ref(props.flow.revision);
const status = ref<'saved' | 'dirty' | 'saving' | 'error' | 'conflict'>(
    'saved',
);
const selectedId = ref<string | null>(null);
const blockNameInvalid = ref(false);
const aiTabs = [
    { id: 'conversation', label: 'Conversa', icon: Sparkles },
    { id: 'outputs', label: 'Saídas e recursos', icon: GitBranch },
    { id: 'settings', label: 'Configurações', icon: SlidersHorizontal },
] as const;
type AiTab = (typeof aiTabs)[number]['id'];
const aiTab = ref<AiTab>('conversation');
const promptDialog = ref<HTMLDialogElement | null>(null);
const promptTrigger = ref<HTMLButtonElement | null>(null);
const promptExpanded = ref(false);

async function expandPrompt(event: MouseEvent) {
    if (!(event.currentTarget instanceof HTMLButtonElement)) return;
    promptTrigger.value = event.currentTarget;
    promptDialog.value = event.currentTarget
        .closest('.ai-editor-tab-panel')
        ?.querySelector<HTMLDialogElement>('.prompt-dialog') ?? null;
    if (!promptDialog.value) return;

    promptExpanded.value = true;
    await nextTick();
    promptDialog.value?.showModal();
}

function restorePrompt() {
    promptExpanded.value = false;
    void nextTick(() => promptTrigger.value?.focus());
}

watch(aiTab, () => promptDialog.value?.close());
const aiBackButton = ref<HTMLButtonElement | null>(null);
const editorPanel = ref<HTMLElement | null>(null);
const canvas = ref<HTMLElement | null>(null);
watch(
    selectedId,
    () => {
        promptDialog.value?.close();
        promptExpanded.value = false;
        blockNameInvalid.value = false;
        aiTab.value = 'conversation';
    },
    { flush: 'sync' },
);
const selectedEdgeId = ref<string | null>(null);
const nodes = ref<FlowNodeRecord[]>([]);
const edges = shallowRef<FlowEdgeRecord[]>([]);
const viewport = ref<Graph['viewport']>({ x: 0, y: 0, zoom: 1 });
const menu = ref<{
    open: boolean;
    context: { source: string; handle: string } | null;
}>({
    open: false,
    context: null,
});
const canvasNotice = ref('');
const messageInputs = new Map<
    string,
    InstanceType<typeof ContactTemplateInput>
>();
const selected = computed(
    () => nodes.value.find((node) => node.id === selectedId.value) || null,
);
const aiExpanded = ref(false);
const selectedIsAi = computed(() => selected.value?.data.nodeType === 'ai');
const compactEditor = useMediaQuery('(max-width: 767px)');
const aiEditorOpen = computed(
    () => selectedIsAi.value && aiExpanded.value,
);
const blockPanelOpen = computed(
    () => !!selected.value,
);
watch([blockPanelOpen, compactEditor], async ([open, compact]) => {
    if (!open || !compact || aiEditorOpen.value) return;
    await nextTick();
    editorPanel.value?.focus({ preventScroll: true });
});

async function focusCanvasBlock(id: string | null) {
    await nextTick();
    const block = id && canvas.value?.querySelector<HTMLElement>(
        `.vue-flow__node[data-id="${CSS.escape(id)}"]`,
    );
    (block || canvas.value)?.focus({ preventScroll: true });
}

function backToCanvas() {
    aiExpanded.value = false;
    void nextTick(() => editorPanel.value?.querySelector<HTMLElement>('[data-expand-ai]')?.focus());
}

function closeBlockPanel() {
    const id = selectedId.value;
    selectedId.value = null;
    void focusCanvasBlock(id);
}
watch(selectedId, () => { aiExpanded.value = false; }, { flush: 'sync' });

const hasUnsavedChanges = computed(() =>
    ['dirty', 'error', 'conflict'].includes(status.value),
);
let hydrated = false;
let serverGraphSnapshot = '';
let serverFlowName = props.flow.name;
let skipBeforeUnloadConfirmation = false;

const conditionTouched = ref(new Set<string>());
const publicationErrors = ref<Record<string, string[]>>({});
const draftErrors = ref<Record<string, string[]>>({});

function errorLocation(path: string) {
    const draft = /^nodes\.(\d+)\.data\.(.+)$/.exec(path);
    if (draft) return { node: nodes.value[Number(draft[1])], field: draft[2] };

    const prefix = 'graph.nodes.';
    if (!path.startsWith(prefix)) return;
    const node = nodes.value.find(
        ({ id }) =>
            path === `${prefix}${id}` || path.startsWith(`${prefix}${id}.`),
    );
    if (node)
        return {
            node,
            field: path
                .slice(prefix.length + node.id.length + 1)
                .replace(/^data\./, ''),
        };
}

function aiTabForField(field: string): AiTab {
    const [key] = field.split('.');
    if (key === 'ai_prompt') return 'conversation';
    if (key === 'ai_initial_context' || key === 'ai_load_history') return 'conversation';
    if (key === 'ai_outputs') return 'outputs';
    if (key === 'ai_media' || key === 'ai_tags' || key === 'ai_fields')
        return 'outputs';
    return 'settings';
}

const aiTabErrors = computed(() => {
    const errors: Partial<Record<AiTab, string[]>> = {};
    if (!selectedIsAi.value) return errors;
    for (const [path, messages] of Object.entries({
        ...publicationErrors.value,
        ...draftErrors.value,
    })) {
        const location = errorLocation(path);
        if (location?.node?.id !== selectedId.value) continue;
        const tab = aiTabForField(location.field);
        errors[tab] = [...new Set([...(errors[tab] || []), ...messages])];
    }
    return errors;
});

watch(aiEditorOpen, (open) => {
    if (open)
        void nextTick(() => {
            if (!aiTabErrors.value[aiTab.value]?.length) {
                editorPanel.value?.scrollTo({ top: 0 });
                aiBackButton.value?.focus({ preventScroll: true });
            }
        });
});
async function focusPendingField(field: string) {
    if (selectedIsAi.value) aiTab.value = aiTabForField(field);
    await nextTick();
    const panel = editorPanel.value;
    const section = selectedIsAi.value
        ? panel?.querySelector(`#ai-section-${aiTab.value}`)
        : panel;
    const group = section?.querySelector(
        `[data-issue-field="${field}"], [data-ai-field="${field}"]`,
    );
    const details = group instanceof HTMLDetailsElement ? group : group?.querySelector('details');
    if (details) details.open = true;
    const fields =
        '[role="textbox"], input:not([type="hidden"]), select, textarea, [contenteditable="true"]';
    const control = `${fields}, button`;
    const target = group?.matches(control)
        ? group
        : group?.querySelector('[aria-invalid="true"]')
          || group?.querySelector(fields)
          || group?.querySelector('button');
    (target as HTMLElement | null)?.focus();
    if (!target && section instanceof HTMLElement) section.focus();
}

watch(aiTab, () => {
    void nextTick(() => {
        editorPanel.value?.querySelector('[role="tab"][data-state="active"]')
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });
});

function revealValidationError(errors: Record<string, string[]>) {
    for (const path of Object.keys(errors)) {
        const location = errorLocation(path);
        if (!location?.node) continue;
        aiExpanded.value = false;
        selectedId.value = location.node.id;
        if (location.node.data.nodeType !== 'ai') return false;
        aiTab.value = aiTabForField(location.field);
        void nextTick(() => {
            const panel = document.getElementById(
                `ai-section-${aiTab.value}`,
            );
            const field = Array.from(
                panel?.querySelectorAll<HTMLElement>('[data-ai-field]') ?? [],
            ).find(
                (element) => element.dataset.aiField === location.field.split('.')[0],
            );
            const target = field?.querySelector<HTMLElement>(
                'input, select, textarea, [contenteditable="true"]',
            );
            (target ?? panel)?.focus();
        });
        return true;
    }
    return false;
}

const selectedConditionErrors = computed(() => {
    const node = selected.value;
    if (!node || node.data.nodeType !== 'condition')
        return {} as Record<string, string>;
    const errors: Record<string, string> = {};
    if (conditionTouched.value.has(node.id)) {
        for (const [index, check] of (node.data.checks || []).entries()) {
            for (const [field, message] of Object.entries(
                conditionCheckErrors(check, reference.value),
            ))
                errors[`checks.${index}.${field}`] = message;
        }
    }
    for (const [path, messages] of Object.entries(publicationErrors.value)) {
        const prefix = `graph.nodes.${node.id}.`;
        if (path.startsWith(prefix))
            errors[path.slice(prefix.length)] = messages.join(' ');
    }
    return errors;
});
const selectedMenuErrors = computed(() => {
    const node = selected.value;
    const errors: Record<string, string> = {};
    if (!node || node.data.nodeType !== 'menu') return errors;
    for (const [path, messages] of Object.entries(publicationErrors.value)) {
        const prefix = `graph.nodes.${node.id}.`;
        if (path.startsWith(prefix))
            errors[path.slice(prefix.length)] = messages.join(' ');
    }
    return errors;
});
function buttonFieldErrors(index: number): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const [path, messages] of Object.entries(publicationErrors.value)) {
        const prefix = `graph.nodes.${selected.value?.id}.items.${index}.`;
        if (path.startsWith(prefix))
            errors[path.slice(prefix.length).replace(/^button\./, '')] =
                messages.join(' ');
    }
    return errors;
}
function aiDestination(handle: string) {
    const edge = edges.value.find(
        (e) => e.source === selected.value?.id && e.sourceHandle === handle,
    );
    const target = nodes.value.find((n) => n.id === edge?.target);
    return target ? `Segue para: ${target.data.label}` : 'Encerra o fluxo';
}
function addAiOutput() {
    if (!selected.value) return;
    selected.value.data.ai_outputs ??= [];
    const id = crypto.randomUUID();
    selected.value.data.ai_outputs.push({
        id,
        label: '',
        instruction: '',
    });
    markDirty();
    void nextTick(() => {
        const path = editorPanel.value?.querySelector<HTMLDetailsElement>(`[data-ai-path-id="${CSS.escape(id)}"]`);
        if (path) {
            path.open = true;
            path.querySelector<HTMLInputElement>('input')?.focus();
        }
    });
}
function moveAiOutput(index: number, offset: number) {
    const outputs = selected.value?.data.ai_outputs;
    if (!outputs || index + offset < 0 || index + offset >= outputs.length)
        return;
    [outputs[index], outputs[index + offset]] = [
        outputs[index + offset],
        outputs[index],
    ];
    markDirty();
}
async function removeAiOutput(index: number) {
    const node = selected.value;
    const output = node?.data.ai_outputs?.[index];
    if (!node || !output) return;
    const handle = `ai_exit:${output.id}`;
    if (
        edges.value.some(
            (e) => e.source === node.id && e.sourceHandle === handle,
        ) &&
        !(await confirm(
            'A conexão será removida. O bloco de destino será preservado.',
            'Remover saída conectada?',
        ))
    )
        return;
    if (aiRunning.value || assistantBusy.value) return;
    const current = nodes.value.find((n) => n.id === node.id);
    if (!current?.data.ai_outputs) return;
    current.data.ai_outputs = current.data.ai_outputs.filter(
        (o) => o.id !== output.id,
    );
    edges.value = edges.value.filter(
        (e) => !(e.source === node.id && e.sourceHandle === handle),
    );
    markDirty();
}
async function removeMenuOption(index: number) {
    const node = selected.value;
    const option = node?.data.options?.[index];
    if (!node || !option) return;
    const attached = edges.value.filter(
        (edge) =>
            edge.source === node.id &&
            edge.sourceHandle === `option:${option.id}`,
    );
    if (
        attached.length &&
        !(await confirm(
            'A conexão desta opção será removida. O bloco de destino será preservado.',
            'Remover opção conectada?',
        ))
    )
        return;
    if (aiRunning.value || assistantBusy.value) return;
    const current = nodes.value.find((item) => item.id === node.id);
    if (!current?.data.options?.some((item) => item.id === option.id)) return;
    current.data.options = current.data.options.filter(
        (item) => item.id !== option.id,
    );
    edges.value = edges.value.filter(
        (edge) =>
            !(
                edge.source === node.id &&
                edge.sourceHandle === `option:${option.id}`
            ),
    );
    publicationErrors.value = {};
    markDirty();
}
function changeMenu() {
    publicationErrors.value = {};
    const node = selected.value;
    if (node) {
        const outputs = flowOutputs(
            props.catalog,
            node.data.nodeType,
            node.data,
        );
        edges.value = edges.value.map((edge) =>
            edge.source === node.id
                ? {
                      ...edge,
                      label: outputs.find(
                          (output) => output.id === edge.sourceHandle,
                      )?.label,
                  }
                : edge,
        );
    }
    markDirty();
}

function changeCondition() {
    if (selected.value) conditionTouched.value.add(selected.value.id);
    publicationErrors.value = {};
    markDirty();
}
function addConditionCheck() {
    const data = selected.value?.data;
    if (!data || (data.checks?.length || 0) >= 50) return;
    (data.checks ??= []).push({ kind: 'tag', operator: 'has' });
    changeCondition();
}
function changeConditionKind(check: ConditionCheck) {
    const kind = check.kind;
    for (const key of Object.keys(check))
        delete (check as Record<string, unknown>)[key];
    check.kind = kind;
    check.operator = kind === 'tag' ? 'has' : 'equals';
    if (kind === 'field') check.value = '';
    changeCondition();
}
function conditionFieldValue(check: ConditionCheck) {
    return check.field_id
        ? `custom:${check.field_id}`
        : check.field_key
          ? `native:${check.field_key}`
          : '';
}
function selectConditionField(check: ConditionCheck, value: string) {
    delete check.field_id;
    delete check.field_key;
    if (value.startsWith('custom:')) check.field_id = Number(value.slice(7));
    if (value.startsWith('native:')) check.field_key = value.slice(7);
    changeCondition();
}
function conditionError(index: number, field: string) {
    const errors = selectedConditionErrors.value;
    return (
        errors[`checks.${index}.${field}`] ||
        (field === 'field'
            ? errors[`checks.${index}.field_id`] ||
              errors[`checks.${index}.field_key`]
            : '') ||
        ''
    );
}

function makeGraph(view = getViewport()): Graph {
    return {
        schema_version: 2,
        nodes: nodes.value.map(
            (node) =>
                ({
                    id: node.id,
                    type: node.data.nodeType,
                    position: { x: node.position.x, y: node.position.y },
                    data: (() => {
                        const data = { ...node.data } as Record<
                            string,
                            unknown
                        >;
                        delete data.nodeType;
                        delete data.messages;
                        if (node.data.nodeType === 'condition')
                            delete data.tag_ids;
                        if (
                            node.data.nodeType === 'ai' &&
                            data.ai_max_messages === ''
                        )
                            data.ai_max_messages = null;
                        return data;
                    })(),
                }) as FlowNodeRecord,
        ),
        edges: edges.value.map(
            (edge) =>
                ({
                    id: edge.id,
                    source: edge.source,
                    target: edge.target,
                    sourceHandle: edge.sourceHandle || null,
                    targetHandle: edge.targetHandle || null,
                    type: 'smoothstep',
                    label: edge.label || undefined,
                }) as FlowEdgeRecord,
        ),
        viewport: { x: view.x, y: view.y, zoom: view.zoom },
    };
}
function stableGraph(graph: Graph) {
    return JSON.stringify(graph, (key, value) =>
        key === 'description' && value == null ? '' : value,
    );
}
function hydrate(graph: Graph) {
    nodes.value = graph.nodes.map((node) => ({
        ...node,
        type: 'flowNode',
        data: {
            ...node.data,
            nodeType: node.type as FlowKind,
            description: node.data.description || '',
            items:
                node.data.items ||
                (node.data.messages || []).map((message) => ({
                    ...message,
                    kind: 'text' as const,
                })),
            actions: node.data.actions || [],
            ...(node.type === 'condition'
                ? {
                      checks:
                          node.data.checks ??
                          (node.data.tag_ids || []).map((tag_id) => ({
                              kind: 'tag' as const,
                              tag_id,
                              operator: 'has' as const,
                          })),
                  }
                : {}),
            timeout_seconds: node.data.timeout_seconds,
            condition_operator: node.data.condition_operator,
            ...(node.type === 'ai'
                ? {
                      ai_media: (node.data.ai_media || []).map((item) => ({
                          ...item,
                          id: item.id || crypto.randomUUID(),
                      })),
                      ai_tags: (node.data.ai_tags || []).map((item) => ({
                          ...item,
                          id: item.id || crypto.randomUUID(),
                      })),
                      ai_fields: (node.data.ai_fields || []).map((item) => ({
                          ...item,
                          id: item.id || crypto.randomUUID(),
                          allow_overwrite: item.allow_overwrite ?? false,
                      })),
                  }
                : {}),
        },
    }));
    edges.value = graph.edges;
    viewport.value = { ...graph.viewport };
    serverGraphSnapshot = stableGraph(makeGraph(graph.viewport));
    serverFlowName = flowName.value;
    status.value = 'saved';
    requestAnimationFrame(() => {
        window.dispatchEvent(new Event('resize'));
    });
}
hydrate(props.flow.graph);
const assistantOpen = ref(false);
const assistantWindow = ref<InstanceType<typeof FlowAssistantWindow>>();
const assistant = useFlowAssistant({
    workspaceId: props.workspace.id,
    flowId: props.flow.id,
    initialRevision: props.flow.revision,
    editToken,
    canEdit: () => canEdit.value,
    onSaving: () => {
        status.value = 'saving';
    },
    onError: (errors) => {
        draftErrors.value = errors;
        status.value = 'error';
    },
    onSaved: (snapshot, incoming) => {
        draftErrors.value = {};
        revision.value = incoming.flow.revision;
        serverGraphSnapshot = stableGraph(snapshot.graph as Graph);
        serverFlowName = snapshot.name;
        status.value =
            stableGraph(makeGraph(viewport.value)) === serverGraphSnapshot &&
            flowName.value === serverFlowName
                ? 'saved'
                : 'dirty';
    },
    userId: (usePage().props.auth as { user: { id: number } }).user.id,
    snapshot: () => ({
        name: flowName.value,
        graph: makeGraph(viewport.value) as FlowGraph,
    }),
    apply: (incoming: AssistantState) => {
        const wasActive = flowIsActive.value;
        flowIsActive.value = incoming.flow.is_active ?? flowIsActive.value;
        if (flowIsActive.value) {
            assistantOpen.value = false;
            editLocked.value = false;
            clearTimeout(lockTimer);
        } else if (wasActive && !editLocked.value) {
            void renewEditLock();
        }
        liveCatalog.value = incoming.catalog;
        if (incoming.catalog.contact_fields)
            reference.value.fields = incoming.catalog.contact_fields.custom.map(
                (field) => ({ ...field, type: field.type || 'text' }),
            );
        const currentSelection = selectedId.value;
        flowName.value = incoming.flow.name;
        hydrate(incoming.flow.graph as Graph);
        const savedSnapshot = serverGraphSnapshot;
        const savedName = incoming.flow.name;
        revision.value = incoming.flow.revision;
        if (incoming.session) {
            flowName.value = incoming.session.name;
            hydrate(incoming.session.graph as Graph);
        }
        serverGraphSnapshot = savedSnapshot;
        serverFlowName = savedName;
        status.value =
            stableGraph(makeGraph(viewport.value)) === savedSnapshot &&
            flowName.value === savedName
                ? 'saved'
                : 'dirty';
        selectedId.value = nodes.value.some(
            (node) => node.id === currentSelection,
        )
            ? currentSelection
            : null;
    },
});
const {
    state: assistantState,
    running: aiRunning,
    busy: assistantRequestBusy,
    error: assistantError,
    conflict: assistantConflict,
    models: assistantModels,
    model: assistantModel,
} = assistant;
const editorControlsDisabled = computed(
    () => !canEdit.value || aiRunning.value || assistantBusy.value,
);
const assistantBusy = computed(
    () => assistantRequestBusy.value || assistant.replacing.value,
);
watch(editorControlsDisabled, (disabled) => {
    if (disabled) promptDialog.value?.close();
});
const selectedPending = computed(() =>
    selected.value
        ? flowPendingIssues(
              selected.value.data.nodeType,
              selected.value.data,
              reference.value,
          )
        : [],
);
async function applyImportedFlow(result: FlowImportResult) {
    reference.value = {
        ...reference.value,
        tags: result.tags,
        fields: result.fields,
    };
    selectedId.value = null;
    selectedEdgeId.value = null;
    publicationErrors.value = {};
    conditionTouched.value = new Set();
    await assistant.acceptReplacement(result.state);
}

async function sendAssistant(content: string) {
    if (await assistant.action('/assistant/messages', { content }))
        assistantWindow.value?.clearComposer();
}
async function reapplyChanges() {
    if (
        await confirm(
            'Reaplicar suas alterações locais sobre a fluxo atual? Campos alterados por você terão prioridade; alterações em outros campos serão preservadas.',
            'Reaplicar alterações',
        )
    ) {
        try {
            await assistant.reapply();
        } catch (error) {
            await alert(
                error instanceof Error ? error.message : 'Falha ao reaplicar.',
            );
        }
    }
}
onMounted(() => {
    hydrated = true;
});
watch(
    [nodes, edges, flowName, viewport],
    () => {
        if (!hydrated) return;
        status.value =
            stableGraph(makeGraph(viewport.value)) === serverGraphSnapshot &&
            flowName.value === serverFlowName
                ? 'saved'
                : 'dirty';
        assistant.changed();
    },
    { deep: true },
);

function markDirty() {
    assistant.changed();
    if (hydrated)
        status.value =
            stableGraph(makeGraph(viewport.value)) === serverGraphSnapshot &&
            flowName.value === serverFlowName
                ? 'saved'
                : 'dirty';
}
function validConnection(
    connection: Connection | Edge,
    graphNodes = nodes.value,
    graphEdges = edges.value,
): boolean {
    if (
        !connection.source ||
        !connection.target ||
        !connection.sourceHandle ||
        !connection.targetHandle ||
        connection.source === connection.target
    )
        return false;
    const source = graphNodes.find((node) => node.id === connection.source);
    const target = graphNodes.find((node) => node.id === connection.target);
    if (
        !source ||
        !target ||
        source.data.nodeType === 'jump_flow' ||
        target.data.nodeType === 'start'
    )
        return false;
    if (
        !flowOutputs(props.catalog, source.data.nodeType, source.data).some(
            (output) => output.id === connection.sourceHandle,
        ) ||
        connection.targetHandle !== 'input'
    )
        return false;
    // Vue Flow also validates stored edges again when v-model updates. An edge
    // must not count as a competing connection against itself.
    const otherEdges = graphEdges.filter(
        (edge) => !('id' in connection && edge.id === connection.id),
    );
    if (
        otherEdges.some(
            (edge) =>
                edge.source === connection.source &&
                edge.sourceHandle === connection.sourceHandle,
        )
    )
        return false;
    const adjacency = new Map<string, string[]>();
    for (const edge of otherEdges)
        adjacency.set(edge.source, [
            ...(adjacency.get(edge.source) || []),
            edge.target,
        ]);
    const pending = [connection.target];
    const visited = new Set<string>();
    while (pending.length) {
        const id = pending.pop()!;
        if (id === connection.source) return false;
        if (visited.has(id)) continue;
        visited.add(id);
        pending.push(...(adjacency.get(id) || []));
    }
    return true;
}
function connectionAllowed(connection: Connection | Edge): boolean {
    return validConnection(connection);
}
function connect(connection: Connection) {
    if (aiRunning.value || assistantBusy.value) return;
    if (validConnection(connection)) {
        edges.value = [
            ...edges.value,
            {
                ...connection,
                id: crypto.randomUUID(),
                type: 'smoothstep',
            } as FlowEdgeRecord,
        ];
        selectedEdgeId.value = null;
        markDirty();
    }
}
function addNode(type: FlowKind) {
    if (aiRunning.value || assistantBusy.value) return;
    if (type === 'start') return;
    const context = menu.value.context;
    const source = context?.source;
    const handle = context?.handle;
    if (
        context &&
        (!nodes.value.some((node) => node.id === source) ||
            edges.value.some(
                (edge) =>
                    edge.source === source && edge.sourceHandle === handle,
            ))
    ) {
        canvasNotice.value =
            'Esta saída já está conectada. Remova a conexão antes de adicionar outro bloco.';
        menu.value = { open: false, context: null };
        return;
    }
    const id = crypto.randomUUID();
    const position = source
        ? (() => {
              const origin = nodes.value.find((node) => node.id === source)
                  ?.position || { x: 0, y: 0 };
              return {
                  x:
                      origin.x +
                      (nodes.value.find((node) => node.id === source)?.data
                          .nodeType === 'menu'
                          ? Math.max(
                                0,
                                flowOutputs(
                                    props.catalog,
                                    'menu',
                                    nodes.value.find(
                                        (node) => node.id === source,
                                    )!.data,
                                ).findIndex((output) => output.id === handle),
                            ) * 260
                          : 0) +
                      (handle === 'replied' || handle === 'yes'
                          ? -80
                          : handle === 'not_replied' || handle === 'no'
                            ? 180
                            : 60),
                  y:
                      origin.y +
                      (nodes.value.find((node) => node.id === source)?.data
                          .nodeType === 'menu'
                          ? Math.max(
                                175,
                                (findNode(source)?.dimensions.height || 175) +
                                    64,
                            )
                          : 175),
              };
          })()
        : screenToFlowCoordinate({
              x: window.innerWidth / 2,
              y: window.innerHeight / 2,
          });
    const data: FlowData = {
        label: kindLabels[type],
        description: type === 'content' ? '' : '',
        nodeType: type,
        ...(type === 'content' ? { items: [] } : {}),
        ...(type === 'menu'
            ? { ...props.catalog.menu?.defaults, options: [] }
            : {}),
        ...(type === 'wait_reply'
            ? { field_id: null, timeout_seconds: 86400, reply_grouping_seconds: 5 }
            : {}),
        ...(type === 'condition'
            ? { condition_operator: 'all', checks: [] }
            : {}),
        ...(type === 'operations' ? { actions: [] } : {}),
        ...(type === 'jump_flow' ? { target_flow_id: null } : {}),
        ...(type === 'ai'
            ? {
                  ...JSON.parse(
                      JSON.stringify(props.catalog.defaults?.ai || {}),
                  ),
                  ai_media: [],
                  ai_tags: [],
                  ai_fields: [],
              }
            : {}),
    };
    const node: FlowNodeRecord = { id, type: 'flowNode', position, data };
    const nextNodes = [...nodes.value, node];
    const connection = context
        ? {
              source: context.source,
              sourceHandle: context.handle,
              target: id,
              targetHandle: 'input',
          }
        : null;
    if (connection && !validConnection(connection, nextNodes)) {
        canvasNotice.value =
            'Não foi possível conectar esta saída. Nenhum bloco foi criado.';
        menu.value = { open: false, context: null };
        return;
    }
    nodes.value = nextNodes;
    if (connection) {
        edges.value = [
            ...edges.value,
            {
                id: crypto.randomUUID(),
                ...connection,
                type: 'smoothstep',
            } as FlowEdgeRecord,
        ];
    }
    selectedId.value = id;
    selectedEdgeId.value = null;
    menu.value = { open: false, context: null };
    canvasNotice.value = '';
    markDirty();
}
function startAdd(source: string, handle: string) {
    if (
        edges.value.some(
            (edge) => edge.source === source && edge.sourceHandle === handle,
        )
    ) {
        canvasNotice.value =
            'Esta saída já está conectada. Remova a conexão antes de adicionar outro bloco.';
        menu.value = { open: false, context: null };
        return;
    }
    canvasNotice.value = '';
    menu.value = { open: true, context: { source, handle } };
}
function toggleGlobalMenu() {
    canvasNotice.value = '';
    menu.value = { open: !menu.value.open, context: null };
}
function addText() {
    if (selected.value?.data.nodeType === 'content') {
        selected.value.data.items ||= [];
        selected.value.data.items.push({
            id: crypto.randomUUID(),
            kind: 'text',
            text: '',
        });
        markDirty();
    }
}
function removeText(index: number) {
    selected.value?.data.items?.splice(index, 1);
    markDirty();
}
function moveText(index: number, direction: number) {
    const messages = selected.value?.data.items;
    if (
        !messages ||
        index + direction < 0 ||
        index + direction >= messages.length
    )
        return;
    [messages[index], messages[index + direction]] = [
        messages[index + direction],
        messages[index],
    ];
    markDirty();
}
function formatText(message: FlowItem, format: 'bold' | 'italic' | 'strike') {
    messageInputs
        .get(message.id)
        ?.format({ bold: '*', italic: '_', strike: '~' }[format]);
}

function addContentItem(kind: FlowItem['kind']) {
    if (!selected.value || selected.value.data.nodeType !== 'content') return;
    selected.value.data.items ||= [];
    if (
        kind === 'button' &&
        selected.value.data.items.some((item) => item.kind === 'button')
    )
        return;
    selected.value.data.items.push({
        id: crypto.randomUUID(),
        kind,
        ...(kind === 'button'
            ? { text: '', button: { kind: 'url' as const, label: '', url: '' } }
            : {}),
        ...(kind === 'text' ? { text: '' } : {}),
        ...(kind === 'delay' ? { seconds: 3 } : {}),
    });
    markDirty();
}

function addAction(kind: FlowAction['kind']) {
    if (!selected.value || selected.value.data.nodeType !== 'operations')
        return;
    selected.value.data.actions ||= [];
    if (
        ['open_human', 'pause_automation'].includes(kind) &&
        selected.value.data.actions.some((action) =>
            ['open_human', 'pause_automation'].includes(action.kind),
        )
    ) {
        canvasNotice.value =
            'Este bloco já tem uma operação que encerra a execução.';
        return;
    }
    selected.value.data.actions.push({
        id: crypto.randomUUID(),
        kind,
        ...(kind === 'pause_automation'
            ? { pause_mode: 'timed', pause_seconds: 86400 }
            : {}),
    });
    markDirty();
}
function addActionFromEvent(event: Event) {
    const select = event.target as HTMLSelectElement;
    if (select.value) addAction(select.value as FlowAction['kind']);
    select.value = '';
}

function removeAction(index: number) {
    selected.value?.data.actions?.splice(index, 1);
    markDirty();
}
function moveAction(index: number, direction: number) {
    const actions = selected.value?.data.actions;
    if (
        !actions ||
        index + direction < 0 ||
        index + direction >= actions.length
    )
        return;
    [actions[index], actions[index + direction]] = [
        actions[index + direction],
        actions[index],
    ];
    markDirty();
}

async function createReference(kind: 'fields' | 'tags') {
    const name = (
        await prompt(
            kind === 'fields'
                ? 'Nome do campo personalizado'
                : 'Nome da etiqueta',
        )
    )?.trim();
    if (!name) return;
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/${kind === 'fields' ? 'flow-fields' : 'flow-tags'}`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ name }),
            },
        );
        const result = await response.json();
        if (!response.ok)
            throw new Error(result.message || 'Não foi possível criar.');
        if (kind === 'fields') reference.value.fields.push(result.data);
        else reference.value.tags.push(result.data);
    } catch (error) {
        await alert(
            error instanceof Error ? error.message : 'Não foi possível criar.',
        );
    }
}

function addAiResource(kind: 'media' | 'tags' | 'fields') {
    if (!selected.value) return;
    const data = selected.value.data;
    const item = { id: crypto.randomUUID(), instruction: '' };
    if (kind === 'media' && (data.ai_media?.length || 0) < 20)
        (data.ai_media ??= []).push(item);
    if (kind === 'tags' && (data.ai_tags?.length || 0) < 50)
        (data.ai_tags ??= []).push(item);
    if (kind === 'fields' && (data.ai_fields?.length || 0) < 50)
        (data.ai_fields ??= []).push({ ...item, allow_overwrite: false });
    markDirty();
    void nextTick(() => {
        const resource = editorPanel.value?.querySelector<HTMLDetailsElement>(
            `[data-ai-resource-id="${CSS.escape(item.id)}"]`,
        );
        if (!resource) return;
        resource.open = true;
        resource.querySelector<HTMLElement>('select, input[type="file"]')?.focus();
    });
}
function aiMediaName(item: AiMedia) {
    const asset = reference.value.assets.find(
        (asset) => asset.id === item.asset_id,
    );
    if (!item.asset_id)
        return item.original_name
            ? `${item.original_name} — arquivo pendente`
            : 'Selecione um arquivo';
    if (!asset || asset.available === false)
        return 'Arquivo indisponível — substitua antes de ativar';
    return asset.original_name;
}
async function uploadMedia(
    event: Event,
    item: Pick<FlowItem, 'asset_id' | 'media_type' | 'original_name'>,
) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/flows/${props.flow.id}/assets`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Flow-Edit-Token': editToken,
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                credentials: 'same-origin',
                body,
            },
        );
        const result = await response.json();
        if (!response.ok)
            throw new Error(
                result.message || 'Não foi possível enviar o arquivo.',
            );
        item.asset_id = result.data.id;
        item.media_type = result.data.media_type;
        item.original_name = result.data.original_name;
        if (
            !reference.value.assets.some((asset) => asset.id === result.data.id)
        )
            reference.value.assets.push(result.data);
        markDirty();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível enviar o arquivo.',
        );
    } finally {
        input.value = '';
    }
}
async function removeNode() {
    if (!selected.value || selected.value.data.nodeType === 'start') return;
    const connected = edges.value.some(
        (edge) =>
            edge.source === selectedId.value ||
            edge.target === selectedId.value,
    );
    if (
        connected &&
        !(await confirm(
            'Excluir este bloco também removerá suas conexões. Deseja continuar?',
            'Excluir bloco',
            'danger',
        ))
    )
        return;
    edges.value = edges.value.filter(
        (edge) =>
            edge.source !== selectedId.value &&
            edge.target !== selectedId.value,
    );
    nodes.value = nodes.value.filter((node) => node.id !== selectedId.value);
    selectedId.value = null;
    markDirty();
}
function onPaneClick() {
    selectedId.value = null;
    selectedEdgeId.value = null;
    menu.value = { open: false, context: null };
}
function onCanvasKeydown(event: KeyboardEvent) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.matches('.vue-flow__node')) return;
    const node = nodes.value.find(({ id }) => id === target.dataset.id);
    if (!node) return;
    event.preventDefault();
    onNodeClick({ node });
}
function onNodeClick(event: { node: FlowNodeRecord }) {
    aiExpanded.value = false;
    selectedId.value = event.node.id;
    selectedEdgeId.value = null;
    menu.value = { open: false, context: null };
}
function onEdgeClick(event: { edge: Edge }) {
    selectedEdgeId.value = event.edge.id;
    selectedId.value = null;
}
function removeSelectedEdge() {
    if (!selectedEdgeId.value) return;
    edges.value = edges.value.filter(
        (edge) => edge.id !== selectedEdgeId.value,
    );
    selectedEdgeId.value = null;
    markDirty();
}
async function goBack() {
    if (canEdit.value && !(await assistant.flush())) return;
    skipBeforeUnloadConfirmation = true;
    window.location.href =
        `/workspaces/${props.workspace.id}/flows` +
        (props.flow.folder_id === null
            ? ''
            : `?folder_id=${props.flow.folder_id}`);
}
function onMoveEnd() {
    const view = getViewport();
    if (
        view.x === viewport.value.x &&
        view.y === viewport.value.y &&
        view.zoom === viewport.value.zoom
    )
        return;
    if (canEdit.value) {
        viewport.value = { x: view.x, y: view.y, zoom: view.zoom };
        markDirty();
    }
}
async function reloadLatest() {
    if (
        !(await confirm(
            'Carregar a fluxo atual? Suas alterações locais ainda não sincronizadas serão perdidas.',
            'Recarregar fluxo',
        ))
    )
        return;
    try {
        await assistant.reload();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Falha ao carregar o fluxo.',
        );
    }
}
async function publish() {
    if (!canEdit.value || aiRunning.value || assistantBusy.value) return;
    if (!(await assistant.flush())) {
        revealValidationError(draftErrors.value);
        return;
    }
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/flows/${props.flow.id}/activate`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Flow-Edit-Token': editToken,
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ revision: revision.value }),
            },
        );
        const result = await response.json();
        publicationErrors.value = result.errors || {};
        if (!response.ok && revealValidationError(publicationErrors.value))
            return;
        if (!response.ok)
            throw new Error(
                Object.values(result.errors || {})
                    .flat()
                    .join(' ') ||
                    result.message ||
                    'Não foi possível ativar.',
            );
        flowIsActive.value = true;
        editLocked.value = false;
        clearTimeout(lockTimer);
        assistantOpen.value = false;
    } catch (error) {
        await alert(
            error instanceof Error ? error.message : 'Não foi possível ativar.',
        );
    }
}
async function deactivate() {
    if (
        !flowIsActive.value ||
        !(await confirm(
            'Inativar este fluxo? As execuções em andamento serão encerradas.',
            'Inativar fluxo',
        ))
    )
        return;
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/flows/${props.flow.id}/deactivate`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                credentials: 'same-origin',
            },
        );
        const result = await response.json();
        if (!response.ok)
            throw new Error(result.message || 'Não foi possível inativar.');
        flowIsActive.value = false;
        void renewEditLock();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível inativar.',
        );
    }
}
function onBeforeUnload(event: BeforeUnloadEvent) {
    if (
        !skipBeforeUnloadConfirmation &&
        (hasUnsavedChanges.value || status.value === 'saving')
    ) {
        event.preventDefault();
        event.returnValue = '';
    }
}
let approvedNavigation = false;
const stopNavigationGuard = router.on('before', (event) => {
    if (
        approvedNavigation ||
        (!hasUnsavedChanges.value && status.value !== 'saving')
    )
        return;
    event.preventDefault();
    const visit = event.detail.visit;
    void assistant.flush().then((saved) => {
        if (!saved) return;
        approvedNavigation = true;
        router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
            replace: visit.replace,
            only: visit.only,
            onFinish: () => {
                approvedNavigation = false;
            },
        });
    });
});
onBeforeUnmount(stopNavigationGuard);
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onBeforeUnmount(() =>
    window.removeEventListener('beforeunload', onBeforeUnload),
);
</script>

<template>
    <Head :title="flowName" />
    <main class="editor-shell" :class="{ 'editor-shell--ai': aiEditorOpen }">
        <header v-show="!aiEditorOpen" class="editor-topbar">
            <button
                class="back-link"
                aria-label="Voltar para Fluxos"
                title="Voltar para Fluxos"
                type="button"
                @click="goBack"
            >
                <ArrowLeft :size="18" /><span>Fluxos</span>
            </button>
            <div class="flow-title">
                <Input
                    v-model="flowName"
                    :disabled="!canEdit || aiRunning || assistantBusy"
                    aria-label="Nome do fluxo"
                    @input="markDirty"
                /><small role="status">{{
                    status === 'saved'
                        ? 'Todas as alterações salvas'
                        : status === 'dirty'
                          ? 'Alterações aguardando salvamento'
                          : status === 'saving'
                            ? 'Salvando…'
                            : status === 'conflict'
                              ? 'Conflito de edição'
                              : 'Falha ao salvar'
                }}</small>
            </div>
            <div class="top-actions">
                <FlowTransferActions
                    :endpoint="`/workspaces/${props.workspace.id}/flows/${props.flow.id}`"
                    :edit-token="editToken"
                    :disabled="
                        !canEdit ||
                        aiRunning ||
                        assistantBusy ||
                        assistantConflict ||
                        status === 'saving' ||
                        !assistant.loaded.value
                    "
                    :revisions="assistant.revisions"
                    :begin="assistant.beginReplacement"
                    :end="assistant.endReplacement"
                    :apply="applyImportedFlow"
                />
                <AppButton
                    variant="secondary"
                    :disabled="!canEdit"
                    @click="assistantOpen = !assistantOpen"
                    ><Bot :size="16" />Assistente IA</AppButton
                >
                <span
                    v-if="!flowIsActive && !editLocked"
                    class="published-pill"
                    >{{
                        editLockError ||
                        (editingBy
                            ? `${editingBy} está editando`
                            : 'Aguardando edição')
                    }}</span
                >
                <span class="published-pill flow-state" :class="{ 'published-pill--active': flowIsActive }">{{
                    flowIsActive ? 'Ativo' : 'Inativo'
                }}</span>
                <AppButton
                    v-if="flowIsActive"
                    variant="secondary"
                    @click="deactivate"
                >
                    Inativar
                </AppButton>
                <AppButton
                    v-else
                    class="publish-button"
                    :disabled="
                        !canEdit ||
                        !assistant.loaded.value ||
                        aiRunning ||
                        assistantBusy ||
                        status === 'saving' ||
                        status === 'conflict' ||
                        blockNameInvalid
                    "
                    @click="publish"
                    variant="primary"
                >
                    Ativar
                </AppButton>
            </div>
        </header>
        <section
            class="editor-workspace"
            :class="{ 'editor-workspace--ai': aiEditorOpen, 'editor-workspace--selected': blockPanelOpen && !aiEditorOpen }"
        >
            <Tabs v-model="aiTab" as-child>
            <aside
                ref="editorPanel"
                class="settings-panel"
                tabindex="-1"
                :class="{ 'settings-panel--ai': aiEditorOpen }"
                @keydown.esc="!aiEditorOpen && !promptExpanded && !$event.defaultPrevented && closeBlockPanel()"
            >
                <template v-if="selected">
                    <header v-if="aiEditorOpen" class="ai-screen-header">
                        <button
                            ref="aiBackButton"
                            type="button"
                            class="ai-return"
                            aria-label="Voltar ao painel"
                            title="Voltar ao painel"
                            @click="backToCanvas"
                        ><ArrowLeft :size="18" /></button>
                        <span class="ai-heading-icon"><Sparkles :size="22" /></span>
                        <div class="ai-heading-copy">
                            <div class="ai-heading-line">
                                <h1>Bloco IA</h1>
                                <span>{{ flowIsActive ? 'Ativo' : 'Rascunho' }}</span>
                            </div>
                            <p>Configure como a IA conversa e conduz o atendimento.</p>
                        </div>
                        <div class="ai-save-bar">
                            <AppButton
                                :loading="status === 'saving'"
                                :disabled="editorControlsDisabled || blockNameInvalid || status === 'conflict'"
                                @click="assistant.flush()"
                            >Salvar alterações</AppButton>
                            <small role="status">{{
                                status === 'saved' ? 'Todas as alterações salvas'
                                : status === 'dirty' ? 'Alterações aguardando salvamento'
                                : status === 'saving' ? 'Salvando…'
                                : status === 'conflict' ? 'Conflito de edição' : 'Falha ao salvar'
                            }}</small>
                        </div>
                    </header>
                    <FlowBlockHeader
                        v-if="!aiEditorOpen"
                        :key="selected.id"
                        :block-id="selected.id"
                        :node-type="selected.data.nodeType"
                        :kind="kindLabels[selected.data.nodeType]"
                        :name="selected.data.label"
                        :read-only="editorControlsDisabled"
                        @update:name="
                            selected.data.label = $event;
                            markDirty();
                        "
                        @validity-change="blockNameInvalid = $event"
                        ><template v-if="!aiEditorOpen" #close
                            ><AppIconButton v-if="selectedIsAi" data-expand-ai label="Expandir configuração" @click="aiExpanded = true"><Maximize :size="16" /></AppIconButton>
                            <AppIconButton
                                @click="closeBlockPanel"
                                label="Fechar painel"
                                ><X :size="16" /></AppIconButton></template
                    ></FlowBlockHeader>
                    <AppNotice v-if="selectedPending.length" variant="warning">
                        <strong>Configuração pendente</strong>
                        <ul>
                            <li v-for="{ message, field } in selectedPending" :key="message">
                                <button v-if="!editorControlsDisabled" type="button" @click="focusPendingField(field)">
                                    {{ message }}
                                </button>
                                <span v-else>{{ message }}</span>
                            </li>
                        </ul>
                    </AppNotice>
                    <TabsList v-if="selectedIsAi" class="ai-editor-tab-list" aria-label="Configuração do bloco de IA">
                        <TabsTrigger
                            v-for="tab in aiTabs"
                            :key="tab.id"
                            type="button"
                            :value="tab.id"
                            class="ai-editor-tab"
                        >
                            <component :is="tab.icon" :size="16" aria-hidden="true" />
                            {{ tab.label }}
                            <span v-if="aiTabErrors[tab.id]?.length" class="ai-tab-error-indicator">
                                <span class="sr-only">com erro</span>
                            </span>
                        </TabsTrigger>
                    </TabsList>
                    <div class="block-fields" :inert="!aiEditorOpen && editorControlsDisabled">
                    <section
                        v-if="selected.data.nodeType === 'content'"
                        class="content-items"
                    >
                        <h2>Mensagens para o contato</h2>
                        <h3 class="sequence-heading">
                            Sequência de mensagens e ações
                        </h3>
                        <p>
                            Mensagens e arquivos são enviados nesta ordem. As
                            esperas criam uma pausa entre os envios.
                        </p>
                        <article
                            v-for="(item, index) in selected.data.items"
                            :key="item.id"
                            class="message-item"
                            :data-issue-field="`item-${index}-${item.kind}`"
                        >
                            <div class="item-heading">
                                <strong>{{
                                    item.kind === 'text'
                                        ? `Texto da mensagem ${index + 1}`
                                        : item.kind === 'delay'
                                          ? 'Atraso'
                                          : item.kind === 'button'
                                            ? 'Botão'
                                            : 'Arquivo'
                                }}</strong>
                                <div>
                                    <AppIconButton
                                        :disabled="index === 0"
                                        @click="moveText(index, -1)"
                                        label="Mover para cima"
                                        ><ChevronUp :size="16" /></AppIconButton
                                    ><AppIconButton
                                        :disabled="
                                            index ===
                                            (selected.data.items?.length || 0) -
                                                1
                                        "
                                        @click="moveText(index, 1)"
                                        label="Mover para baixo"
                                        ><ChevronDown
                                            :size="16" /></AppIconButton
                                    ><AppIconButton
                                        @click="removeText(index)"
                                        :label="`Remover item ${index + 1}`"
                                        ><X :size="16"
                                    /></AppIconButton>
                                </div>
                            </div>
                            <div
                                v-if="item.kind === 'text'"
                                class="format-tools"
                            >
                                <AppIconButton
                                    @click="formatText(item, 'bold')"
                                    label="Negrito"
                                    ><Bold :size="16" /></AppIconButton
                                ><AppIconButton
                                    @click="formatText(item, 'italic')"
                                    label="Itálico"
                                    ><Italic :size="16" /></AppIconButton
                                ><AppIconButton
                                    @click="formatText(item, 'strike')"
                                    label="Tachado"
                                    ><Strikethrough :size="16"
                                /></AppIconButton>
                            </div>
                            <ContactTemplateInput
                                v-if="item.kind === 'text'"
                                :ref="
                                    (element) =>
                                        element
                                            ? messageInputs.set(
                                                  item.id,
                                                  element as InstanceType<
                                                      typeof ContactTemplateInput
                                                  >,
                                              )
                                            : messageInputs.delete(item.id)
                                "
                                v-model="item.text"
                                :workspace-id="workspace.id"
                                :fields="catalog.contact_fields"
                                rows="4"
                                maxlength="4000"
                                :aria-label="`Texto da mensagem ${index + 1}`"
                                placeholder="Escreva sua mensagem…"
                                @input="markDirty"
                            />
                            <label
                                v-else-if="item.kind === 'delay'"
                                class="field-label"
                                >Aguardar antes do próximo item
                                <Input
                                    v-model.number="item.seconds"
                                    type="number"
                                    min="3"
                                    max="10"
                                    @input="markDirty"
                                />
                            </label>
                            <FlowContentButtonEditor
                                v-else-if="item.kind === 'button'"
                                :item="item"
                                :workspace-id="workspace.id"
                                :fields="catalog.contact_fields"
                                :errors="buttonFieldErrors(index)"
                                @change="markDirty"
                            />
                            <template v-else>
                                <input
                                    type="file"
                                    :accept="
                                        item.media_type === 'image'
                                            ? 'image/jpeg,image/png,image/webp,image/gif'
                                            : item.media_type === 'video'
                                              ? 'video/mp4'
                                              : item.media_type === 'audio'
                                                ? 'audio/mpeg,audio/ogg,application/ogg'
                                                : undefined
                                    "
                                    @change="uploadMedia($event, item)"
                                />
                                <small v-if="item.original_name">{{
                                    item.original_name
                                }}</small>
                                <label class="field-label"
                                    >Legenda (opcional)<ContactTemplateInput
                                        :workspace-id="workspace.id"
                                        :fields="catalog.contact_fields"
                                        v-model="item.caption"
                                        aria-label="Legenda"
                                        rows="2"
                                        maxlength="1024"
                                        @input="markDirty"
                                    />
                                </label>
                            </template>
                        </article>
                        <div class="item-add-buttons">
                            <AppButton
                                class="add-text"
                                data-issue-field="content-add"
                                @click="addText"
                                variant="secondary"
                            >
                                <Plus :size="16" />Texto</AppButton
                            ><AppButton
                                class="add-text"
                                @click="addContentItem('media')"
                                variant="secondary"
                            >
                                <Plus :size="16" />Mídia</AppButton
                            ><AppButton
                                class="add-text"
                                @click="addContentItem('delay')"
                                variant="secondary"
                            >
                                <Plus :size="16" />Atraso
                            </AppButton>
                            <AppButton
                                variant="secondary"
                                @click="addContentItem('button')"
                                :disabled="
                                    selected.data.items?.some(
                                        (item) => item.kind === 'button',
                                    )
                                "
                                aria-describedby="content-button-limit"
                                ><Plus :size="16" />Botão</AppButton
                            >
                        </div>
                        <small
                            v-if="
                                selected.data.items?.some(
                                    (item) => item.kind === 'button',
                                )
                            "
                            id="content-button-limit"
                        >
                            Cada bloco Conteúdo permite um elemento Botão.
                            Remova o atual para adicionar outro.
                        </small>
                    </section>
                    <div
                        v-else-if="selected.data.nodeType === 'ai'"
                        :key="selected.id"
                        class="ai-editor-tabs"
                    >

                        <TabsContent
                            v-for="tab in aiTabs"
                            :id="`ai-section-${tab.id}`"
                            :key="tab.id"
                            :value="tab.id"
                            class="ai-editor-tab-panel"
                            tabindex="-1"
                        >
                            <h2 :id="`ai-title-${tab.id}`" class="ai-card-title">
                                <span><component :is="tab.icon" :size="18" aria-hidden="true" /></span>
                                {{ tab.id === 'conversation' ? 'Instruções da IA' : tab.label }}
                            </h2>
                            <ul
                                v-if="aiTabErrors[tab.id]?.length"
                                class="ai-tab-errors"
                                role="alert"
                            >
                                <li
                                    v-for="message in aiTabErrors[tab.id]"
                                    :key="message"
                                >
                                    {{ message }}
                                </li>
                            </ul>
                            <div :inert="editorControlsDisabled">
                                <template v-if="tab.id === 'conversation'">
                                    <div v-if="aiEditorOpen" class="ai-name-card">
                                        <FlowBlockHeader
                                            :key="selected.id"
                                            :block-id="selected.id"
                                            :node-type="selected.data.nodeType"
                                            :kind="kindLabels[selected.data.nodeType]"
                                            :name="selected.data.label"
                                            :read-only="editorControlsDisabled"
                                            @update:name="
                                                selected.data.label = $event;
                                                markDirty();
                                            "
                                            @validity-change="blockNameInvalid = $event"
                                            ><template v-if="!aiEditorOpen" #close
                                                ><AppIconButton
                                                    @click="selectedId = null"
                                                    label="Fechar painel"
                                                    ><X :size="16" /></AppIconButton></template
                                        ></FlowBlockHeader>

                                    </div>

                                    <div class="prompt-actions">
                                        <button
                                            type="button"
                                            class="prompt-expand"
                                            @click="expandPrompt"
                                        >
                                            Expandir instruções
                                        </button>
                                    </div>
                                    <dialog
                                        class="prompt-dialog"
                                        aria-labelledby="prompt-dialog-title"
                                        @close="restorePrompt"
                                    >
                                        <header>
                                            <h2 id="prompt-dialog-title">Instruções da IA</h2>
                                            <AppIconButton
                                                label="Fechar instruções"
                                                @click="promptDialog?.close()"
                                            ><X :size="18" /></AppIconButton>
                                        </header>
                                        <div id="expanded-ai-prompt"></div>
                                    </dialog>
                                    <Teleport
                                        defer
                                        to="#expanded-ai-prompt"
                                        :disabled="!promptExpanded"
                                    >
                                        <div class="field-label" data-ai-field="ai_prompt"><ContactTemplateInput
                                                :workspace-id="workspace.id"
                                                :fields="catalog.contact_fields"
                                                v-model="selected.data.ai_prompt"
                                                aria-labelledby="ai_prompt-label"
                                                rows="4"
                                                maxlength="8000"
                                                placeholder="Descreva o papel, as informações disponíveis e quando concluir ou encaminhar."
                                                @input="markDirty"
                                            ><template #label><span id="ai_prompt-label" @click="focusPendingField('ai_prompt')">O que a IA deve fazer?</span></template></ContactTemplateInput>
                                        </div>
                                    </Teleport>

                                    <div class="field-label" data-ai-field="ai_initial_context"><FlowFieldHelp label="Contexto inicial (opcional)">Enviado uma vez ao entrar no bloco. Última mensagem aceita texto e mídias suportadas.</FlowFieldHelp><ContactTemplateInput
                                            :workspace-id="workspace.id"
                                            :fields="catalog.contact_fields"
                                            v-model="
                                                selected.data.ai_initial_context
                                            "
                                            initial-context
                                            aria-label="Contexto inicial"
                                            rows="4"
                                            maxlength="8000"
                                            placeholder="Insira Última mensagem para contextualizar a abertura da IA."
                                            @input="markDirty"
                                        />
                                    </div>
                                    <div class="ai-history-option">
                                        <label class="ai-history-label" data-ai-field="ai_load_history">
                                            <input
                                                v-model="
                                                    selected.data
                                                        .ai_load_history
                                                "
                                                type="checkbox"
                                                aria-describedby="ai-history-help"
                                                @change="markDirty"
                                            />
                                            Carregar histórico da conversa
                                        </label>
                                        <FlowFieldHelp id="ai-history-help" label="O que entra no histórico?">Envia à OpenAI até 50 mensagens recentes de texto disponíveis no WhatsApp ao abrir a sessão.</FlowFieldHelp>
                                    </div>
                                </template>
                                <template v-else-if="tab.id === 'outputs'">
                                    <template
                                        v-if="
                                            selected.data.ai_outputs !==
                                            undefined
                                        "
                                    >
                                        <FlowFieldHelp label="Caminhos">A IA escolhe pelo contexto e pelas instruções. Pode enviar uma última mensagem antes de seguir. Sem conexão, a saída encerra o fluxo.</FlowFieldHelp>
                                        <details
                                            v-for="(output, index) in selected
                                                .data.ai_outputs"
                                            :key="output.id"
                                            class="ai-path"
                                            :data-ai-path-id="output.id"
                                            :data-issue-field="!output.label.trim() || !output.instruction.trim() ? 'ai_outputs' : undefined"
                                            :name="`ai-path-${selected.id}`"
                                        >
                                            <summary>
                                                <strong>{{
                                                    output.label ||
                                                    'Novo caminho'
                                                }}</strong
                                                ><small>{{
                                                    aiDestination(
                                                        `ai_exit:${output.id}`,
                                                    )
                                                }}</small>
                                            </summary>
                                            <label class="field-label"
                                                >Nome do caminho<Input
                                                    v-model="output.label"
                                                    maxlength="120"
                                                    @input="changeMenu"
                                            /></label>
                                            <label class="field-label"
                                                >Quando seguir este
                                                caminho?<Textarea
                                                    v-model="output.instruction"
                                                    rows="3"
                                                    maxlength="2000"
                                                    @input="changeMenu"
                                            /></label>
                                            <div class="item-heading">
                                                <strong
                                                    >Saída
                                                    {{ index + 1 }}</strong
                                                >
                                                <div>
                                                    <AppIconButton
                                                        :disabled="index === 0"
                                                        @click="
                                                            moveAiOutput(
                                                                index,
                                                                -1,
                                                            )
                                                        "
                                                        label="Mover saída para cima"
                                                        ><ChevronUp :size="16"
                                                    /></AppIconButton>
                                                    <AppIconButton
                                                        :disabled="
                                                            index ===
                                                            selected.data
                                                                .ai_outputs
                                                                .length -
                                                                1
                                                        "
                                                        @click="
                                                            moveAiOutput(
                                                                index,
                                                                1,
                                                            )
                                                        "
                                                        label="Mover saída para baixo"
                                                        ><ChevronDown
                                                            :size="16"
                                                    /></AppIconButton>
                                                    <AppIconButton
                                                        @click="
                                                            removeAiOutput(
                                                                index,
                                                            )
                                                        "
                                                        label="Remover saída"
                                                        ><X :size="16"
                                                    /></AppIconButton>
                                                </div>
                                            </div>
                                        </details>
                                        <AppButton
                                            variant="secondary"
                                            class="ai-add-path"
                                            @click="addAiOutput"
                                        >
                                            <Plus :size="16" aria-hidden="true" />
                                            Adicionar caminho
                                        </AppButton>
                                        <p
                                            v-if="
                                                !selected.data.ai_outputs.length
                                            "
                                            class="condition-help"
                                        >
                                            A IA continuará conversando até
                                            ocorrer uma saída automática ou
                                            alguém assumir o atendimento.
                                        </p>
                                        <div class="ai-automatic">
                                            <FlowFieldHelp label="Saída automática · Continuar">Usada em caso de falha, tempo sem resposta ou limite de mensagens.</FlowFieldHelp>
                                            <small>{{
                                                aiDestination('continue')
                                            }}</small>
                                        </div>
                                    </template>

                                    <div class="ai-resource-group" data-issue-field="ai_media">
                                        <h3><FlowFieldHelp label="Mídias">Até 20 arquivos de 20 MiB. Cada
                                            arquivo tem uma tentativa de envio
                                            por sessão, sem legenda.</FlowFieldHelp></h3>
                                        <details
                                            v-for="(item, index) in selected
                                                .data.ai_media"
                                            :key="item.id"
                                            class="ai-resource"
                                            :data-ai-resource-id="item.id"
                                        >
                                            <summary>{{ item.title?.trim() || (aiMediaName(item)) }}</summary>
                                            <small v-if="item.title?.trim()">{{ aiMediaName(item) }}</small>
                                            <label class="field-label">Título interno (opcional)
                                                <Input v-model="item.title" maxlength="120" @input="markDirty" />
                                            </label>

                                            <label class="field-label"
                                                >Arquivo
                                                <input
                                                    type="file"
                                                    accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mp3,.ogg,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                                                    @change="
                                                        uploadMedia(
                                                            $event,
                                                            item,
                                                        )
                                                    "
                                                />
                                            </label>
                                            <label class="field-label"
                                                >Quando enviar a mídia
                                                <Textarea
                                                    v-model="item.instruction"
                                                    maxlength="2000"
                                                    rows="3"
                                                    placeholder="Ex.: enviar quando o cliente solicitar o catálogo."
                                                    @input="markDirty"
                                                />
                                            </label>
                                            <AppButton
                                                variant="secondary"
                                                @click="
                                                    selected.data.ai_media?.splice(
                                                        index,
                                                        1,
                                                    );
                                                    markDirty();
                                                "
                                                >Remover mídia
                                                {{ index + 1 }}</AppButton
                                            >
                                        </details>
                                        <AppButton
                                            variant="secondary"
                                            :disabled="
                                                (selected.data.ai_media
                                                    ?.length || 0) >= 20
                                            "
                                            @click="addAiResource('media')"
                                            >Adicionar mídia</AppButton
                                        >
                                    </div>
                                    <div class="ai-resource-group" data-issue-field="ai_tags">
                                        <h3><FlowFieldHelp label="Tags">Autorize apenas as tags que a IA
                                            poderá adicionar. Tags existentes
                                            serão preservadas.</FlowFieldHelp></h3>
                                        <details
                                            v-for="(item, index) in selected
                                                .data.ai_tags"
                                            :key="item.id"
                                            class="ai-resource"
                                            :data-ai-resource-id="item.id"
                                        >
                                            <summary>{{ item.title?.trim() || (reference.tags.find(tag => tag.id === item.tag_id)?.name || 'Selecionar etiqueta') }}</summary>
                                            <small v-if="item.title?.trim()">{{ reference.tags.find(tag => tag.id === item.tag_id)?.name || 'Selecionar etiqueta' }}</small>
                                            <label class="field-label">Título interno (opcional)
                                                <Input v-model="item.title" maxlength="120" @input="markDirty" />
                                            </label>
                                            <label class="field-label"
                                                >Etiqueta autorizada
                                                <AppSelect
                                                    v-model.number="item.tag_id"
                                                    :aria-label="`Tag autorizada ${index + 1}`"
                                                    @change="markDirty"
                                                >
                                                    <option :value="undefined">
                                                        Selecione uma tag
                                                    </option>
                                                    <option
                                                        v-for="tag in reference.tags"
                                                        :key="tag.id"
                                                        :value="tag.id"
                                                    >
                                                        {{ tag.name }}
                                                    </option>
                                                </AppSelect>
                                            </label>
                                            <label class="field-label"
                                                >Quando aplicar a tag
                                                {{ index + 1 }}
                                                <Textarea
                                                    v-model="item.instruction"
                                                    maxlength="2000"
                                                    rows="3"
                                                    @input="markDirty"
                                                />
                                            </label>
                                            <AppButton
                                                variant="secondary"
                                                @click="
                                                    selected.data.ai_tags?.splice(
                                                        index,
                                                        1,
                                                    );
                                                    markDirty();
                                                "
                                                >Remover permissão de tag
                                                {{ index + 1 }}</AppButton
                                            >
                                        </details>
                                        <AppButton
                                            variant="secondary"
                                            :disabled="
                                                (selected.data.ai_tags
                                                    ?.length || 0) >= 50
                                            "
                                            @click="addAiResource('tags')"
                                            >Autorizar tag</AppButton
                                        >
                                    </div>
                                    <div class="ai-resource-group" data-issue-field="ai_fields">
                                        <h3><FlowFieldHelp label="Campos personalizados">Autorize campos de texto. Com
                                            sobrescrita permitida, a última
                                            gravação vence.</FlowFieldHelp></h3>
                                        <details
                                            v-for="(item, index) in selected
                                                .data.ai_fields"
                                            :key="item.id"
                                            class="ai-resource"
                                            :data-ai-resource-id="item.id"
                                        >
                                            <summary>{{ item.title?.trim() || (reference.fields.find(field => field.id === item.field_id)?.name || 'Selecionar campo') }}</summary>
                                            <small v-if="item.title?.trim()">{{ reference.fields.find(field => field.id === item.field_id)?.name || 'Selecionar campo' }}</small>
                                            <label class="field-label">Título interno (opcional)
                                                <Input v-model="item.title" maxlength="120" @input="markDirty" />
                                            </label>
                                            <label class="field-label"
                                                >Campo autorizado
                                                {{ index + 1 }}
                                                <AppSelect
                                                    v-model.number="
                                                        item.field_id
                                                    "
                                                    :aria-label="`Campo autorizado ${index + 1}`"
                                                    @change="markDirty"
                                                >
                                                    <option :value="undefined">
                                                        Selecione um campo
                                                    </option>
                                                    <option
                                                        v-for="field in reference.fields.filter(
                                                            (field) =>
                                                                field.type ===
                                                                'text',
                                                        )"
                                                        :key="field.id"
                                                        :value="field.id"
                                                    >
                                                        {{ field.name }}
                                                    </option>
                                                </AppSelect>
                                            </label>
                                            <label class="field-label"
                                                >Quando preencher o campo
                                                <Textarea
                                                    v-model="item.instruction"
                                                    maxlength="2000"
                                                    rows="3"
                                                    placeholder="Explique quando preencher e qual informação registrar."
                                                    @input="markDirty"
                                                />
                                            </label>
                                            <label class="ai-overwrite"
                                                ><input
                                                    v-model="
                                                        item.allow_overwrite
                                                    "
                                                    type="checkbox"
                                                    @change="markDirty"
                                                />Permitir sobrescrever valor
                                                existente</label
                                            >
                                            <FlowFieldHelp label="Quando permitir a sobrescrita?">Use quando o contato puder corrigir um valor já registrado. Com esta opção desativada, a IA só preenche campos vazios.</FlowFieldHelp>
                                            <AppButton
                                                variant="secondary"
                                                @click="
                                                    selected.data.ai_fields?.splice(
                                                        index,
                                                        1,
                                                    );
                                                    markDirty();
                                                "
                                                >Remover permissão de campo
                                                {{ index + 1 }}</AppButton
                                            >
                                        </details>
                                        <AppButton
                                            variant="secondary"
                                            :disabled="
                                                (selected.data.ai_fields
                                                    ?.length || 0) >= 50
                                            "
                                            @click="addAiResource('fields')"
                                            >Autorizar campo</AppButton
                                        >
                                    </div>
                                </template>
                                <template v-else>
                                    <label class="field-label" data-ai-field="ai_model"
                                        >Modelo OpenAI
                                        <AppSelect
                                            v-model="selected.data.ai_model"
                                            @change="markDirty"
                                        >
                                            <option value="gpt-6-luna">
                                                GPT-6 Luna
                                            </option>
                                            <option value="gpt-6.1-sol">
                                                GPT-6.1 Sol
                                            </option>
                                            <option value="gpt-6-astra">
                                                GPT-6 Astra
                                            </option>
                                        </AppSelect>
                                    </label>
                                    <div class="field-label" data-ai-field="ai_grouping_seconds">
                                        <FlowFieldHelp label="Intervalo para agrupar mensagens (segundos)">O lote fecha após esse tempo sem novos textos ou ao atingir três vezes o intervalo desde o primeiro texto.</FlowFieldHelp>
                                        <Input aria-label="Intervalo para agrupar mensagens (segundos)"
                                            :model-value="selected.data.ai_grouping_seconds ?? 5"
                                            type="number"
                                            min="1"
                                            max="90"
                                            step="1"
                                            @update:model-value="(value) => { selected!.data.ai_grouping_seconds = Number(value); markDirty(); }"
                                        />
                                    </div>
                                    <div class="field-label" data-ai-field="ai_inactivity_seconds">
                                        <FlowFieldHelp label="Tempo de inatividade">Prazo sem novas mensagens do contato antes de sair do bloco IA.</FlowFieldHelp>
                                        <AiInactivityInput :key="selected.id"
                                            v-model="selected.data.ai_inactivity_seconds" @update:model-value="markDirty" />
                                    </div>
                                    <label
                                        class="field-label"
                                        v-if="
                                            selected.data.ai_outputs ===
                                            undefined
                                        "
                                        >Ao expirar
                                        <AppSelect
                                            v-model="
                                                selected.data.ai_expiry_action
                                            "
                                            @change="markDirty"
                                        >
                                            <option value="end">
                                                Encerrar fluxo atual
                                            </option>
                                            <option value="human">
                                                Abrir atendimento humanizado
                                            </option>
                                        </AppSelect>
                                    </label>
                                    <div class="field-label" data-ai-field="ai_max_messages"
                                        >
                                        <FlowFieldHelp label="Limite de mensagens do contato">
                                            Conta as mensagens do contato nesta sessão, mesmo quando chegam agrupadas.
                                            Ao atingir o limite, {{ selected.data.ai_outputs !== undefined || selected.data.ai_limit_action === 'continue' ? 'o fluxo segue pela saída Continuar.' : 'o atendimento humano é acionado.' }}
                                        </FlowFieldHelp>
                                        <Input
                                            v-model.number="
                                                selected.data.ai_max_messages
                                            "
                                            aria-label="Limite de mensagens do contato"
                                            placeholder="Sem limite"
                                            type="number"
                                            min="1"
                                            max="200"
                                            @input="markDirty"
                                        />
                                    </div>
                                    <label
                                        v-if="
                                            selected.data.ai_max_messages &&
                                            selected.data.ai_outputs ===
                                                undefined
                                        "
                                        class="field-label"
                                        >Ao atingir o limite
                                        <AppSelect
                                            v-model="
                                                selected.data.ai_limit_action
                                            "
                                            @change="markDirty"
                                        >
                                            <option value="human">
                                                Abrir atendimento humanizado
                                            </option>
                                            <option value="continue">
                                                Continuar fluxo
                                            </option>
                                        </AppSelect>
                                    </label>
                                    <FlowFieldHelp label="Sobre a IA">A IA envia uma abertura pelo prompt. Vídeos não são analisados. Configure os próximos passos conectando as saídas.</FlowFieldHelp>
                                    <a href="/settings/openai"
                                        >Configurar chave OpenAI deste
                                        workspace</a
                                    >
                                </template>
                            </div>
                        </TabsContent>
                    </div>
                    <FlowMenuEditor
                        data-issue-field="menu"
                        v-else-if="selected.data.nodeType === 'menu'"
                        :data="selected.data"
                        :node-id="selected.id"
                        :errors="selectedMenuErrors"
                        @change="changeMenu"
                        @remove="removeMenuOption"
                    />
                    <section
                        v-else-if="selected.data.nodeType === 'wait_reply'"
                        class="block-config"
                    >
                        <h2>Resposta do contato</h2>
                        <label class="field-label"
                            >Salvar resposta no campo
                            <AppSelect
                                data-issue-field="field_id"
                                v-model.number="selected.data.field_id"
                                @change="markDirty"
                            >
                                <option :value="null">
                                    Selecione um campo
                                </option>
                                <option
                                    v-for="field in reference.fields"
                                    :key="field.id"
                                    :value="field.id"
                                >
                                    {{ field.name }}
                                </option>
                            </AppSelect> </label
                        ><AppButton
                            class="add-text"
                            @click="createReference('fields')"
                            variant="secondary"
                        >
                            <Plus :size="16" />Criar campo personalizado
                        </AppButton>
                        <label class="field-label"
                            >Prazo (segundos, de 60 a 604800)<Input
                                data-issue-field="timeout_seconds"
                                v-model.number="selected.data.timeout_seconds"
                                type="number"
                                min="60"
                                max="604800"
                                @input="markDirty" /></label
                        ><small
                            >O padrão é 86400 segundos (24 horas). Somente
                            respostas de texto preenchem o campo.</small
                        >
                        <label class="field-label">
                            Intervalo para agrupar mensagens (segundos)
                            <Input
                                :model-value="selected.data.reply_grouping_seconds ?? 5"
                                type="number"
                                min="1"
                                max="90"
                                step="1"
                                @update:model-value="(value) => { selected!.data.reply_grouping_seconds = Number(value); markDirty(); }"
                            />
                            <FlowFieldHelp label="Como funciona o agrupamento?">O lote fecha após esse tempo sem novos textos ou ao atingir três vezes o intervalo desde o primeiro texto.</FlowFieldHelp>
                        </label>
                    </section>
                    <section
                        v-else-if="selected.data.nodeType === 'condition'"
                        class="block-config"
                    >
                        <h2>Verificações do contato</h2>
                        <AppFieldHelp label="Ajuda">
                            Escolha quais dados definem o caminho do contato.
                        </AppFieldHelp>
                        <label class="field-label" for="condition-combination"
                            >Combinar verificações
                            <AppSelect
                                id="condition-combination"
                                data-issue-field="condition-operator"
                                v-model="selected.data.condition_operator"
                                @change="changeCondition"
                            >
                                <option value="all">Todas (E)</option>
                                <option value="any">Pelo menos uma (OU)</option>
                            </AppSelect>
                        </label>
                        <AppFieldHelp label="Ajuda">
                            {{
                                selected.data.condition_operator === 'any'
                                    ? 'Se pelo menos uma verificação for atendida, o contato segue por Sim.'
                                    : 'Se todas as verificações forem atendidas, o contato segue por Sim.'
                            }}
                            Caso contrário, segue por Não.
                        </AppFieldHelp>
                        <p
                            v-if="!selected.data.checks?.length"
                            class="condition-empty"
                        >
                            Adicione uma verificação para configurar a condição.
                            Você pode manter o fluxo inativo enquanto ela
                            estiver incompleta.
                        </p>
                        <p
                            v-if="selectedConditionErrors.checks"
                            class="condition-error"
                            role="alert"
                        >
                            {{ selectedConditionErrors.checks }}
                        </p>
                        <article
                            v-for="(check, index) in selected.data.checks"
                            :key="index"
                            class="condition-check"
                            :data-issue-field="`condition-check-${index}`"
                        >
                            <div class="condition-check-heading">
                                <h3>Verificação {{ index + 1 }}</h3>
                                <AppIconButton
                                    :label="`Remover verificação ${index + 1}`"
                                    @click="
                                        selected.data.checks?.splice(index, 1);
                                        changeCondition();
                                    "
                                    ><X :size="16"
                                /></AppIconButton>
                            </div>
                            <label
                                class="field-label"
                                :for="`condition-kind-${index}`"
                                >Tipo de verificação
                                <AppSelect
                                    :id="`condition-kind-${index}`"
                                    v-model="check.kind"
                                    @change="changeConditionKind(check)"
                                >
                                    <option value="tag">Etiqueta</option>
                                    <option value="field">
                                        Campo do contato
                                    </option>
                                </AppSelect>
                            </label>
                            <template v-if="check.kind === 'tag'">
                                <label
                                    class="field-label"
                                    :for="`condition-tag-${index}`"
                                    >Etiqueta
                                    <AppSelect
                                        :id="`condition-tag-${index}`"
                                        :model-value="check.tag_id ?? ''"
                                        @update:model-value="
                                            check.tag_id =
                                                $event === ''
                                                    ? undefined
                                                    : Number($event);
                                            changeCondition();
                                        "
                                        :aria-invalid="
                                            !!conditionError(index, 'tag_id')
                                        "
                                        :aria-describedby="
                                            conditionError(index, 'tag_id')
                                                ? `condition-tag-error-${index}`
                                                : undefined
                                        "
                                    >
                                        <option value="">
                                            Selecione uma etiqueta
                                        </option>
                                        <option
                                            v-for="tag in reference.tags"
                                            :key="tag.id"
                                            :value="tag.id"
                                        >
                                            {{ tag.name }}
                                        </option>
                                    </AppSelect>
                                    <small
                                        v-if="conditionError(index, 'tag_id')"
                                        :id="`condition-tag-error-${index}`"
                                        class="condition-error"
                                        role="alert"
                                        >{{
                                            conditionError(index, 'tag_id')
                                        }}</small
                                    >
                                </label>
                            </template>
                            <template v-else>
                                <label
                                    class="field-label"
                                    :for="`condition-field-${index}`"
                                    >Campo do contato
                                    <AppSelect
                                        :id="`condition-field-${index}`"
                                        :model-value="
                                            conditionFieldValue(check)
                                        "
                                        :aria-invalid="
                                            !!conditionError(index, 'field')
                                        "
                                        :aria-describedby="
                                            conditionError(index, 'field')
                                                ? `condition-field-error-${index}`
                                                : undefined
                                        "
                                        @update:model-value="
                                            selectConditionField(check, $event)
                                        "
                                    >
                                        <option value="">
                                            Selecione um campo
                                        </option>
                                        <optgroup label="Campos padrão">
                                            <option
                                                v-for="field in standardConditionFields"
                                                :key="field.key"
                                                :value="`native:${field.key}`"
                                            >
                                                {{ field.label }}
                                            </option>
                                        </optgroup>
                                        <optgroup label="Campos personalizados">
                                            <option
                                                v-for="field in reference.fields.filter(
                                                    (item) =>
                                                        item.type === 'text',
                                                )"
                                                :key="field.id"
                                                :value="`custom:${field.id}`"
                                            >
                                                {{ field.name }}
                                            </option>
                                        </optgroup>
                                    </AppSelect>
                                    <small
                                        v-if="conditionError(index, 'field')"
                                        :id="`condition-field-error-${index}`"
                                        class="condition-error"
                                        role="alert"
                                        >{{
                                            conditionError(index, 'field')
                                        }}</small
                                    >
                                </label>
                            </template>
                            <label
                                class="field-label"
                                :for="`condition-operator-${index}`"
                                >Verificação
                                <AppSelect
                                    :id="`condition-operator-${index}`"
                                    v-model="check.operator"
                                    :aria-invalid="
                                        !!conditionError(index, 'operator')
                                    "
                                    :aria-describedby="
                                        conditionError(index, 'operator')
                                            ? `condition-operator-error-${index}`
                                            : undefined
                                    "
                                    @change="changeCondition"
                                >
                                    <template v-if="check.kind === 'tag'"
                                        ><option value="has">Possui</option>
                                        <option value="not_has">
                                            Não possui
                                        </option></template
                                    >
                                    <template v-else
                                        ><option value="equals">
                                            É igual a
                                        </option>
                                        <option value="contains">
                                            Contém
                                        </option></template
                                    >
                                </AppSelect>
                                <small
                                    v-if="conditionError(index, 'operator')"
                                    :id="`condition-operator-error-${index}`"
                                    class="condition-error"
                                    role="alert"
                                    >{{
                                        conditionError(index, 'operator')
                                    }}</small
                                >
                            </label>
                            <label
                                v-if="check.kind === 'field'"
                                class="field-label"
                                :for="`condition-value-${index}`"
                                >Valor procurado
                                <Input
                                    :id="`condition-value-${index}`"
                                    v-model="check.value"
                                    maxlength="4000"
                                    placeholder="Ex.: inglês"
                                    :aria-invalid="
                                        !!conditionError(index, 'value')
                                    "
                                    :aria-describedby="
                                        conditionError(index, 'value')
                                            ? `condition-value-error-${index}`
                                            : undefined
                                    "
                                    @input="changeCondition"
                                />
                                <small
                                    v-if="conditionError(index, 'value')"
                                    :id="`condition-value-error-${index}`"
                                    class="condition-error"
                                    role="alert"
                                    >{{ conditionError(index, 'value') }}</small
                                >
                            </label>
                            <p
                                v-if="
                                    selectedConditionErrors[`checks.${index}`]
                                "
                                class="condition-error"
                                role="alert"
                            >
                                {{ selectedConditionErrors[`checks.${index}`] }}
                            </p>
                        </article>
                        <AppButton
                            data-issue-field="condition-add"
                            class="add-text"
                            variant="secondary"
                            :disabled="
                                (selected.data.checks?.length || 0) >= 50
                            "
                            @click="addConditionCheck"
                            ><Plus :size="16" />Adicionar verificação</AppButton
                        >
                        <AppFieldHelp label="Ajuda">
                            Até 50 verificações. A ordem não altera o resultado.
                        </AppFieldHelp>
                        <AppFieldHelp label="Ajuda">
                            Textos são comparados sem diferença de maiúsculas ou
                            acentos e sem espaços nas extremidades. Contém
                            também encontra trechos de palavras: “ana”
                            corresponde a “Mariana”. Campos vazios não atendem à
                            verificação.
                        </AppFieldHelp>
                        <div class="condition-resource-actions">
                            <AppButton
                                variant="ghost"
                                @click="createReference('tags')"
                                >Criar etiqueta</AppButton
                            >
                            <AppButton
                                variant="ghost"
                                @click="createReference('fields')"
                                >Criar campo</AppButton
                            >
                        </div>
                    </section>
                    <section
                        v-else-if="selected.data.nodeType === 'jump_flow'"
                        class="block-config"
                    >
                        <h2>Fluxo de destino</h2>
                        <label class="field-label"
                            >Abrir fluxo<AppSelect
                                data-issue-field="target_flow_id"
                                v-model.number="selected.data.target_flow_id"
                                @change="markDirty"
                            >
                                <option :value="null">
                                    Selecione um fluxo ativo
                                </option>
                                <option
                                    v-for="flow in reference.inactive_flows.filter(
                                        (f) =>
                                            f.id ===
                                            selected?.data.target_flow_id,
                                    )"
                                    :key="flow.id"
                                    :value="flow.id"
                                    disabled
                                >
                                    {{ flow.name }} (inativo)
                                </option>
                                <option
                                    v-for="flow in reference.flows"
                                    :key="flow.id"
                                    :value="flow.id"
                                >
                                    {{ flow.name }}
                                </option>
                            </AppSelect></label
                        >
                    </section>
                    <section
                        v-else-if="selected.data.nodeType === 'operations'"
                        class="block-config"
                    >
                        <h2>Operações em sequência</h2>
                        <article
                            v-for="(action, index) in selected.data.actions"
                            :key="action.id"
                            class="message-item"
                            :data-issue-field="`action-${index}`"
                        >
                            <div class="item-heading">
                                <strong>Operação {{ index + 1 }}</strong>
                                <div>
                                    <AppIconButton
                                        :disabled="index === 0"
                                        @click="moveAction(index, -1)"
                                        label="Mover para cima"
                                        ><ChevronUp :size="16" /></AppIconButton
                                    ><AppIconButton
                                        :disabled="
                                            index ===
                                            (selected.data.actions?.length ||
                                                1) -
                                                1
                                        "
                                        @click="moveAction(index, 1)"
                                        label="Mover para baixo"
                                        ><ChevronDown
                                            :size="16" /></AppIconButton
                                    ><AppIconButton
                                        @click="removeAction(index)"
                                        label="Remover operação"
                                        ><X :size="16"
                                    /></AppIconButton>
                                </div>
                            </div>
                            <label class="field-label"
                                >Ação<AppSelect
                                    v-model="action.kind"
                                    @change="markDirty"
                                >
                                    <option value="add_to_sequence">
                                        Adicionar à sequência
                                    </option>
                                    <option value="add_tag">
                                        Adicionar etiqueta
                                    </option>
                                    <option value="remove_tag">
                                        Remover etiqueta
                                    </option>
                                    <option value="set_field">
                                        Atualizar campo
                                    </option>
                                    <option value="notify_member">
                                        Notificar membro por WhatsApp
                                    </option>
                                    <option value="open_human">
                                        Abrir atendimento humano
                                    </option>
                                    <option value="pause_automation">
                                        Pausar automações
                                    </option>
                                </AppSelect></label
                            >
                            <label
                                v-if="
                                    ['add_tag', 'remove_tag'].includes(
                                        action.kind,
                                    )
                                "
                                class="field-label"
                                >Etiqueta<AppSelect
                                    v-model.number="action.tag_id"
                                    @change="markDirty"
                                >
                                    <option
                                        v-for="tag in reference.tags"
                                        :key="tag.id"
                                        :value="tag.id"
                                    >
                                        {{ tag.name }}
                                    </option>
                                </AppSelect></label
                            >
                            <AppButton
                                v-if="
                                    ['add_tag', 'remove_tag'].includes(
                                        action.kind,
                                    )
                                "
                                class="add-text"
                                @click="createReference('tags')"
                                variant="secondary"
                            >
                                <Plus :size="16" />Criar etiqueta
                            </AppButton>
                            <label
                                v-if="action.kind === 'add_to_sequence'"
                                class="field-label"
                                >Sequência publicada<AppSelect
                                    v-model="action.sequence_id"
                                    @change="markDirty"
                                    ><option :value="null">
                                        Selecionar sequência
                                    </option>
                                    <option
                                        v-for="sequence in reference.sequences ||
                                        []"
                                        :key="sequence.id"
                                        :value="sequence.id"
                                    >
                                        {{ sequence.name }}
                                    </option></AppSelect
                                ><small
                                    >O fluxo atual continua. A primeira etapa
                                    aguarda seu término.</small
                                ></label
                            >
                            <template v-if="action.kind === 'set_field'"
                                ><label class="field-label"
                                    >Campo<AppSelect
                                        v-model.number="action.field_id"
                                        @change="markDirty"
                                    >
                                        <option
                                            v-for="field in reference.fields"
                                            :key="field.id"
                                            :value="field.id"
                                        >
                                            {{ field.name }}
                                        </option>
                                    </AppSelect></label
                                ><label class="field-label"
                                    >Valor<Input
                                        v-model="action.value"
                                        @input="markDirty" /></label
                                ><AppButton
                                    class="add-text"
                                    @click="createReference('fields')"
                                    variant="secondary"
                                >
                                    + Criar campo
                                </AppButton></template
                            >
                            <template v-if="action.kind === 'notify_member'"
                                ><label class="field-label"
                                    >Membro<AppSelect
                                        v-model.number="action.user_id"
                                        @change="markDirty"
                                    >
                                        <option
                                            v-for="member in reference.members"
                                            :key="member.id"
                                            :value="member.id"
                                        >
                                            {{ member.name
                                            }}{{
                                                member.phone
                                                    ? ''
                                                    : ' (sem telefone)'
                                            }}
                                        </option>
                                    </AppSelect></label
                                ><label class="field-label"
                                    >Mensagem<Textarea
                                        v-model="action.text"
                                        rows="3"
                                        @input="markDirty" /></label
                            ></template>
                            <label
                                v-if="action.kind === 'open_human'"
                                class="field-label"
                                >Atendente (opcional; vazio deixa na
                                fila)<AppSelect
                                    v-model="action.user_id"
                                    @change="markDirty"
                                >
                                    <option :value="undefined">
                                        Fila não atribuída
                                    </option>
                                    <option
                                        v-for="member in reference.members"
                                        :key="member.id"
                                        :value="member.id"
                                    >
                                        {{ member.name }}
                                    </option>
                                </AppSelect></label
                            >
                            <p
                                v-if="
                                    ['open_human', 'pause_automation'].includes(
                                        action.kind,
                                    )
                                "
                                class="coming-soon"
                            >
                                Esta operação encerra a execução. Ela deve ser a
                                última desta lista.
                            </p>
                            <template v-if="action.kind === 'pause_automation'"
                                ><label class="field-label"
                                    >Duração<AppSelect
                                        v-model="action.pause_mode"
                                        @change="markDirty"
                                    >
                                        <option value="timed">Por tempo</option>
                                        <option value="indefinite">
                                            Indefinidamente
                                        </option>
                                    </AppSelect></label
                                ><label
                                    v-if="action.pause_mode === 'timed'"
                                    class="field-label"
                                    >Segundos (60 a 604800)<Input
                                        v-model.number="action.pause_seconds"
                                        type="number"
                                        min="60"
                                        max="604800"
                                        @input="markDirty" /></label
                            ></template>
                        </article>
                        <label class="field-label"
                            >Adicionar operação<AppSelect data-issue-field="operations-add"
                                value=""
                                @change="addActionFromEvent"
                            >
                                <option value="" disabled>
                                    Escolha uma operação
                                </option>
                                <option value="add_to_sequence">
                                    Adicionar à sequência
                                </option>
                                <option value="add_tag">
                                    Adicionar etiqueta
                                </option>
                                <option value="remove_tag">
                                    Remover etiqueta
                                </option>
                                <option value="set_field">
                                    Atualizar campo
                                </option>
                                <option value="notify_member">
                                    Notificar membro por WhatsApp
                                </option>
                                <option value="open_human">
                                    Abrir atendimento humano
                                </option>
                                <option value="pause_automation">
                                    Pausar automações
                                </option>
                            </AppSelect></label
                        >
                    </section>
                    <details
                        :key="`organization-${selected.id}`"
                        class="block-organization"
                        :inert="aiEditorOpen && editorControlsDisabled"
                    >
                        <summary>
                            Organização interna<ChevronDown
                                :size="16"
                                aria-hidden="true"
                            />
                        </summary>
                        <div>
                            <label
                                class="field-label"
                                :for="`description-${selected.id}`"
                                >Descrição interna — opcional<Textarea
                                    :id="`description-${selected.id}`"
                                    v-model="selected.data.description"
                                    rows="3"
                                    maxlength="500"
                                    :aria-describedby="`description-help-${selected.id}`"
                                    @input="markDirty" /></label
                            ><AppFieldHelp
                                :id="`description-help-${selected.id}`"

                                 label="Ajuda">Visível apenas para sua equipe</AppFieldHelp>
                        </div>
                    </details>
                    <AppButton
                        v-if="selected.data.nodeType !== 'start'"
                        class="delete-node"
                        :disabled="aiEditorOpen && editorControlsDisabled"
                        @click="removeNode"
                        variant="danger"
                    >
                        Excluir bloco
                    </AppButton>
                    </div>
                </template>
            </aside>
            </Tabs>
            <section
                ref="canvas"
                class="flow-canvas"
                tabindex="-1"
                :inert="aiEditorOpen || (compactEditor && blockPanelOpen)"
                :aria-hidden="aiEditorOpen || (compactEditor && blockPanelOpen) ? true : undefined"
                aria-label="Canvas do fluxo"
                @keydown="onCanvasKeydown"
            >
                <VueFlow
                    :nodes-draggable="canEdit && !aiRunning && !assistantBusy"
                    :nodes-connectable="canEdit && !aiRunning && !assistantBusy"
                    :elements-selectable="true"
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    class="canvas-flow"
                    :is-valid-connection="connectionAllowed"
                    :default-viewport="viewport"
                    :delete-key-code="null"
                    @connect="connect"
                    @pane-click="onPaneClick"
                    @node-click="onNodeClick"
                    @edge-click="onEdgeClick"
                    @node-drag-stop="markDirty"
                    @move-end="onMoveEnd"
                    ><template #node-flowNode="{ id, data }"
                        ><FlowNode
                            :catalog="catalog"
                            :read-only="editorControlsDisabled"
                            :selected="selectedId === id"
                            :id="id"
                            :data="data"
                            :reference="reference"
                            @add="startAdd" /></template
                    ><Background
                        pattern-color="var(--border)"
                        :gap="22" />
                    <Controls position="bottom-left" :show-interactive="!editorControlsDisabled">
                        <template #control-zoom-in>
                            <button type="button" class="vue-flow__controls-button" aria-label="Aproximar"
                                title="Aproximar" @click="zoomIn()"><Plus :size="16" aria-hidden="true" /></button>
                        </template>
                        <template #control-zoom-out>
                            <button type="button" class="vue-flow__controls-button" aria-label="Afastar"
                                title="Afastar" @click="zoomOut()"><Minus :size="16" aria-hidden="true" /></button>
                        </template>
                        <template #control-fit-view>
                            <button type="button" class="vue-flow__controls-button" aria-label="Ajustar visualização"
                                title="Ajustar visualização" @click="fitView({ padding: 0.2, duration: 250 })">
                                <Maximize :size="16" aria-hidden="true" />
                            </button>
                        </template>
                    </Controls>
                </VueFlow>
                <div class="canvas-top">
                    <AppButton
                        :disabled="!canEdit || aiRunning || assistantBusy"
                        v-if="selectedEdgeId"
                        variant="secondary"
                        @click="removeSelectedEdge"
                    >
                        Remover conexão
                    </AppButton>
                    <AppButton
                        :disabled="!canEdit || aiRunning || assistantBusy"
                        @click="toggleGlobalMenu"
                        variant="primary"
                    >
                        <Plus :size="16" />Adicionar bloco
                    </AppButton>
                    <div v-if="menu.open" class="block-menu">
                        <button
                            v-for="definition in definitions"
                            :key="definition.type"
                            :data-flow-type="definition.type"
                            @click="addNode(definition.type)"
                        >
                            <span
                                class="block-type-marker"
                                aria-hidden="true"
                            />
                            {{ definition.label }}
                        </button>
                    </div>
                </div>
                <AppNotice v-if="canvasNotice" :key="canvasNotice" variant="error">
                    {{ canvasNotice }}
                </AppNotice>
                <details class="flow-activity" v-show="!aiEditorOpen">
                    <summary>Ocorrências e execuções</summary>
                    <FlowExecutionEvents
                        :workspace-id="workspace.id"
                        :flow-id="flow.id"
                    />
                    <section
                        v-if="recent_executions.length && !aiEditorOpen"
                        class="run-activity"
                    >
                        <h2>Execuções recentes</h2>
                        <article
                            v-for="execution in recent_executions"
                            :key="execution.id"
                        >
                            <b>{{
                                execution.status === 'waiting_menu'
                                    ? 'Aguardando escolha no menu'
                                    : execution.status
                            }}</b
                            ><small
                                >{{ execution.trigger_source }} ·
                                {{
                                    new Date(execution.started_at).toLocaleString(
                                        'pt-BR',
                                    )
                                }}</small
                            >
                            <p v-if="execution.error_message">
                                {{ execution.error_message }}
                            </p>
                        </article>
                    </section>
                </details>
            </section>
        </section>
    </main>
    <AppNotice v-if="assistantConflict || assistantError || aiRunning" :variant="assistantConflict || assistantError ? 'error' : 'info'" :duration="0">
        <span>{{
            assistantConflict
                ? 'A fluxo mudou. Suas alterações locais estão preservadas.'
                : assistantError ||
                  'A IA está trabalhando. A edição manual está pausada.'
        }}</span>
        <AppButton
            v-if="assistantConflict"
            variant="secondary"
            @click="reloadLatest"
            >Carregar fluxo atual</AppButton
        >
        <AppButton
            v-if="assistantConflict"
            variant="secondary"
            :disabled="aiRunning"
            @click="reapplyChanges"
            >Reaplicar minhas alterações</AppButton
        >
        <AppButton
            v-if="aiRunning"
            variant="secondary"
            :disabled="assistantBusy"
            @click="assistant.stop"
            >Parar IA</AppButton
        >
    </AppNotice>
    <FlowAssistantWindow
        ref="assistantWindow"
        :open="assistantOpen"
        :state="assistantState"
        :running="aiRunning"
        :busy="assistantBusy"
        :error="assistantError"
        :models="assistantModels"
        v-model:model="assistantModel"
        @close="assistantOpen = false"
        @send="sendAssistant"
        @approve="assistant.action(`/assistant/plans/${$event}/approve`)"
        @stop="assistant.stop"
        @resume="
            assistantState?.run &&
            assistant.action(`/assistant/runs/${assistantState.run.id}/resume`)
        "
        @load-models="assistant.loadModels"
        @older="assistant.olderMessages"
    />
    <AppDialog :dialog="dialog" @resolve="finish" />
</template>

<style scoped>
.field-help {
    margin-top: 12px;
    color: var(--muted-foreground);
    font-size: 12px;
    line-height: 1.5;
}
.pending-details {
    padding: 12px;
    margin: 12px 0;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--warning-soft);
    color: var(--warning);
    font-size: 13px;
}
.pending-details li {
    margin-top: 6px;
}
.pending-details button {
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
}

.condition-check {
    margin: 16px 0;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--card);
}
.condition-check-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 12px;
}
.condition-check-heading h3 {
    font-size: 13px;
    margin: 0;
}
.condition-help,
.condition-empty {
    color: var(--muted-foreground);
    font-size: 12px;
    line-height: 1.5;
    margin: 8px 0 16px;
}
.condition-empty {
    padding: 12px;
    background: var(--accent);
    border-radius: 8px;
}
.condition-error {
    color: var(--destructive);
    font-size: 12px;
    line-height: 1.5;
}
.condition-resource-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.condition-check .field-label {
    font-size: 12px;
}

.assistant-editor-notice {
    position: fixed;
    z-index: 40;
    left: 16px;
    bottom: 16px;
    right: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
    color: var(--foreground);
    box-shadow: 0 4px 20px #0002;
    font-size: 13px;
}
.assistant-editor-notice > span {
    flex: 1;
    min-width: 180px;
}

.ai-resource-group,
.ai-resource {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-width: 0;
}
.ai-resource-group {
    border-top: 1px solid var(--border);
    padding-top: 1rem;
}
.ai-resource {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.75rem;
}
.ai-resource strong,
.ai-overwrite {
    overflow-wrap: anywhere;
    font-size: 0.8125rem;
}
.ai-overwrite {
    display: flex;
    align-items: start;
    gap: 0.5rem;
}
.ai-resource input[type='file'] {
    width: 100%;
    min-width: 0;
}

* {
    box-sizing: border-box;
}
.editor-shell {
    height: 100%;
    min-height: 0;
    background: var(--background);
    color: var(--foreground);
    font-family: var(--font-sans), sans-serif;
}
.editor-topbar {
    height: 68px;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 0 var(--page-gutter);
    background: var(--card);
    border-bottom: 1px solid var(--border);
}
.back-link {
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--muted-foreground);
    text-decoration: none;
    font-size: 22px;
    cursor: pointer;
}
.back-link span {
    font-size: 12px;
}
.flow-title {
    display: grid;
    gap: 4px;
    flex: 1;
}
.flow-title small {
    color: var(--muted-foreground);
    font-size: 10px;
}
.top-actions {
    display: flex;
    align-items: center;
    gap: var(--form-button-gap);
    flex-wrap: wrap;
}

.editor-workspace {
    height: calc(100% - 68px);
    display: grid;
    grid-template-columns: minmax(0, 1fr);
}
.settings-panel {
    position: relative;
    z-index: 2;
    overflow: auto;
    padding: 16px;
    background: var(--card);
    border-right: 1px solid var(--border);
}
.settings-panel:not(.settings-panel--ai) > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.settings-panel:not(.settings-panel--ai) > header small {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: var(--brand-strong);
}
.settings-panel:not(.settings-panel--ai) > header button {
    border: 0;
    background: transparent;
    color: var(--muted-foreground);
    font-size: 22px;
    cursor: pointer;
}
.field-label {
    display: grid;
    gap: var(--form-label-gap);
    margin-bottom: var(--form-field-gap);
    color: var(--muted-foreground);
    font-size: 10px;
    font-weight: 650;
}
.field-label input,
.field-label textarea,
.message-item textarea {
    width: 100%;
    padding: 9px;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline-color: var(--ring);
    color: var(--foreground);
    font: inherit;
    font-size: 11px;
    resize: vertical;
}
.content-items {
    margin-top: 22px;
    border-top: 1px solid var(--border);
    padding-top: 17px;
}
.content-items h2 {
    margin: 0 0 5px;
    font-size: 12px;
}
.content-items > p,
.coming-soon {
    color: var(--muted-foreground);
    font-size: 10px;
    line-height: 1.6;
}
.message-item {
    margin-top: 13px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 9px;
}
.item-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.item-heading strong {
    font-size: 10px;
}
.item-heading div,
.format-tools {
    display: flex;
    gap: var(--form-button-gap);
}
.item-heading button,
.format-tools button {
    width: 24px;
    height: 23px;
    border: 1px solid var(--border);
    border-radius: 5px;
    background: var(--card);
    color: var(--muted-foreground);
    cursor: pointer;
}
.item-heading button:disabled {
    opacity: 0.35;
}
.format-tools {
    margin-bottom: 6px;
}
.message-item textarea {
    display: block;
}
.add-text {
    width: 100%;
    margin-top: 10px;
    padding: 9px;
    border: 1px dashed var(--border);
    border-radius: 8px;
    background: var(--background);
    color: var(--success);
    font-size: 10px;
    font-weight: 650;
    cursor: pointer;
}
.item-add-buttons {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--form-button-gap);
}
.block-config {
    margin-top: 22px;
    border-top: 1px solid var(--border);
    padding-top: 17px;
}
.block-config h2,
.run-activity h2 {
    margin: 0 0 12px;
    font-size: 12px;
}
.block-config select,
.block-config small,
.block-config p {
    color: var(--muted-foreground);
    font-size: 10px;
    line-height: 1.5;
}
.check-option {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 8px 0;
    color: var(--muted-foreground);
    font-size: 11px;
}
.run-activity {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
}
.run-activity article {
    padding: 8px 0;
    border-top: 1px solid var(--border);
    font-size: 10px;
}
.run-activity small {
    display: block;
    margin-top: 4px;
    color: var(--muted-foreground);
}
.run-activity p {
    color: var(--danger);
}
.published-pill {
    align-self: center;
    color: var(--muted-foreground);
    font-size: 10px;
    white-space: nowrap;
}
.publish-button {
    white-space: nowrap;
}
.delete-node {
    width: 100%;
    margin-top: 16px;
}
.flow-canvas {
    position: relative;
    min-width: 0;
    background: var(--background);
}
.canvas-flow {
    width: 100%;
    height: 100%;
}
.canvas-top {
    position: absolute;
    z-index: 5;
    top: 16px;
    right: 16px;
    display: flex;
    gap: 8px;
}
.block-menu {
    position: absolute;
    top: 44px;
    right: 0;
    width: 190px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
    background: var(--card);
    box-shadow: var(--shadow-popover);
}
.block-menu button {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 9px;
    border: 0;
    border-radius: 6px;
    background: var(--card);
    text-align: left;
    color: var(--foreground);
    font-size: 11px;
    cursor: pointer;
}
.block-menu button:hover {
    background: var(--flow-type-soft);
}
.block-type-marker {
    width: 10px;
    height: 10px;
    flex: none;
    border-radius: 50%;
    background: var(--flow-type-color);
}
.canvas-notice {
    position: absolute;
    z-index: 5;
    top: 66px;
    right: 18px;
    max-width: 290px;
    margin: 0;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--warning-soft);
    color: var(--warning);
    font-size: 11px;
    line-height: 1.4;
}
:deep(.vue-flow__edge-path) {
    stroke: var(--primary);
    stroke-width: 2.5;
}
:deep(.vue-flow__edge-text) {
    fill: var(--muted-foreground);
    font-size: 10px;
}
@media (max-width: 760px) {
    .editor-shell {
        min-height: 100%;
        height: 100%;
    }
    .editor-topbar {
        height: 60px;
        padding: 0 10px;
        gap: 10px;
    }
    .editor-workspace {
        height: calc(100% - 60px);
        grid-template-columns: minmax(190px, 38%) 1fr;
    }
    .settings-panel {
        padding: 14px 10px;
    }
    .flow-title small {
        font-size: 9px;
    }
    .top-actions .primary,
    .top-actions .secondary,
    .canvas-top button {
        padding: 7px;
        font-size: 9px;
    }
    .canvas-top {
        top: 9px;
        right: 8px;
        gap: 4px;
    }
    .flow-node {
        width: 190px;
    }
}
</style>

<style scoped>
.editor-shell {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}
.editor-topbar {
    height: auto;
    min-height: 68px;
    flex: none;
    padding: 12px 20px;
    gap: 16px;
    flex-wrap: wrap;
}
.editor-workspace {
    height: auto;
    min-height: 0;
    flex: 1;
}
.flow-title {
    min-width: 120px;
}
.back-link {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: var(--control-height);
}
.back-link span {
    font-size: 13px;
}
.flow-canvas {
    min-width: 0;
    min-height: 0;
}
.canvas-top {
    flex-wrap: wrap;
    max-width: calc(100% - 32px);
}
.canvas-top button {
    font-size: 12px;
}
@media (max-width: 640px) {
    .editor-topbar {
        padding: 12px;
        gap: 10px;
    }
    .top-actions {
        width: 100%;
    }
    .editor-workspace {
        position: relative;
        grid-template-columns: minmax(0, 1fr);
    }
    .settings-panel {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: min(300px, 85%);
        z-index: 12;
        box-shadow: 10px 0 25px #0002;
    }
    .canvas-top {
        top: 12px;
        right: 12px;
    }
    .flow-title {
        min-width: 0;
    }
    .canvas-top :deep(button) {
        font-size: 12px;
    }
    .top-actions :deep(button) {
        font-size: 12px;
    }
}
</style>

<style scoped>
.settings-panel {
    font-size: 14px;
}
.settings-panel :deep(input:not([type='checkbox'])) {
    min-height: var(--control-height);
    font-size: 14px;
    border-radius: var(--radius);
}
.settings-panel :deep(textarea) {
    font-size: 14px;
    border-radius: var(--radius);
}
.settings-panel :deep(select) {
    font-size: 14px;
    min-height: var(--control-height);
}
.field-label {
    font-size: 13px;
}
.block-config p,
.block-config small,
.run-activity small {
    font-size: 12px;
}
.settings-panel :deep(button[data-slot='button']) {
    font-size: 13px;
}
.item-add-buttons {
    grid-template-columns: minmax(0, 1fr);
}
.flow-title input {
    border: 0;
    box-shadow: none;
    background: transparent;
    padding: 0;
    font-weight: 600;
}
.flow-title small {
    font-size: 12px;
}
</style>

<style scoped>
.content-items {
    margin-top: 0;
    border-top: 0;
    padding-top: 0;
}
.content-items > h2 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
}
.sequence-heading {
    font-size: 12px;
    font-weight: 500;
    margin: 0 0 5px;
    color: var(--muted-foreground);
}
.content-items > p {
    font-size: 12px;
}
.item-heading strong {
    font-size: 12px;
}
.block-config {
    margin-top: 0;
    border-top: 0;
    padding-top: 0;
}
.block-config > h2 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
}
.block-organization {
    margin-top: 24px;
    border-top: 1px solid var(--border);
    padding-top: 12px;
}
.block-organization summary {
    min-height: var(--control-height);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 12px;
    color: var(--muted-foreground);
    list-style: none;
}
.block-organization summary::-webkit-details-marker {
    display: none;
}
.block-organization[open] summary svg {
    transform: rotate(180deg);
}
.block-organization > div {
    padding-top: 10px;
}
.block-organization .field-label {
    font-size: 12px;
    margin-bottom: 6px;
}
.block-organization summary:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
    border-radius: var(--radius);
}
</style>

<style scoped>
.editor-workspace--ai {
    position: relative;
}
.settings-panel--ai {
    position: absolute;
    inset: 0;
    z-index: 13;
    width: auto;
    padding: var(--page-block-padding) var(--page-gutter) 32px;
    border-right: 0;
    box-shadow: none;
}
.settings-panel--ai > * {
    max-width: 1100px;
    margin-inline: auto;
}
.ai-editor-tabs {
    min-width: 0;
    gap: 0;
    margin-bottom: 24px;
}
.ai-editor-tab-list {
    display: flex;
    width: 100%;
    min-height: var(--control-height);
    justify-content: flex-start;
    gap: 4px;
    overflow-x: auto;
    padding: 0 3px 5px;
    border-bottom: 1px solid var(--border);
    border-radius: 0;
    background: var(--card);
}
.ai-editor-tab {
    flex: none;
    min-height: var(--control-height);
    padding-inline: clamp(12px, 2vw, 22px);
}
.ai-editor-tab-panel {
    min-width: 0;
    padding-top: 22px;
    font-size: 14px;
}
.ai-editor-tab-panel small {
    color: var(--muted-foreground);
    font-size: 13px;
    font-weight: 400;
}
.ai-editor-tab-panel :deep(.contact-template-input) {
    font-size: 15px;
    font-weight: 400;
    line-height: 1.6;
}
.ai-tab-error-indicator {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--destructive);
}
.ai-tab-errors {
    margin-bottom: 16px;
    padding: 10px 14px;
    border: 1px solid var(--destructive);
    border-radius: var(--radius);
    color: var(--destructive);
}
.ai-history-option {
    display: grid;
    gap: 6px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
.ai-history-label {
    display: flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
    min-height: var(--control-height);
    font-weight: 600;
    cursor: pointer;
}
.ai-history-label input {
    width: 16px;
    height: 16px;
    accent-color: var(--brand-strong);
}
@media (max-width: 640px) {
    .ai-editor-tab-list {
        gap: 1px;
    }
    .ai-editor-tab {
        padding-inline: 5px;
        font-size: 13px;
    }
}
.ai-path {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    margin: 10px 0;
}
.ai-path > summary {
    cursor: pointer;
    font-size: 13px;
}
.ai-path > summary small {
    display: block;
    margin: 5px 0 0 16px;
    color: var(--muted-foreground);
}
.ai-path[open] > summary {
    margin-bottom: 14px;
}
.ai-automatic {
    border-top: 1px dashed var(--border);
    margin-top: 18px;
    padding-top: 14px;
    font-size: 13px;
}
</style>

<style scoped>
.settings-panel--ai {
    background: var(--background);
}
.settings-panel--ai > .block-header {
    border-radius: var(--radius-card);
}
.ai-editor-tab-list {
    background: var(--background);
}
.ai-editor-tab-panel {
    padding: var(--card-padding);
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
}
.ai-summary {
    padding: var(--card-padding);
    border-radius: var(--radius-card);
    background: var(--card);
}
.ai-summary-ready {
    color: var(--foreground);
}
.ai-summary-ready p {
    color: var(--muted-foreground);
}
.prompt-actions {
    display: flex;
    justify-content: flex-end;
}
.prompt-expand {
    min-height: var(--control-height);
    color: var(--link);
    font-size: 13px;
    font-weight: 600;
}
.prompt-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(880px, calc(100vw - 32px));
    max-height: var(--visible-viewport-height, 100dvh);
    padding: 24px;
    overflow: auto;
    color: var(--foreground);
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-dialog);
}
.prompt-dialog::backdrop {
    background: var(--overlay);
}
.prompt-dialog header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.prompt-dialog h2 {
    font-size: 20px;
    font-weight: 600;
}
.prompt-dialog :deep(.contact-template-input) {
    min-height: min(320px, 45dvh);
}
@media (max-width: 767px) {
    .editor-workspace {
        position: relative;
        grid-template-columns: minmax(0, 1fr);
    }
    .settings-panel:not(.settings-panel--ai) {
        position: absolute;
        inset: 0;
        z-index: 12;
        width: 100%;
    }
    .prompt-dialog {
        inset: var(--visible-viewport-top, 0px) 0 auto;
        margin: 0;
        width: 100%;
        max-width: none;
        height: var(--visible-viewport-height, 100dvh);
        max-height: none;
        border: 0;
        border-radius: 0;
        padding: max(16px, env(safe-area-inset-top)) 16px max(16px, env(safe-area-inset-bottom));
    }
}
</style>

<style scoped>
.editor-shell--ai {
    --ai-page: color-mix(in srgb, var(--accent) 32%, var(--background));
    --ai-card: color-mix(in srgb, var(--accent) 10%, var(--card));
    background: var(--ai-page);
}
.settings-panel--ai {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px 24px;
    align-content: start;
    background: var(--ai-page);
}
.settings-panel--ai > * {
    width: 100%;
    min-width: 0;
    max-width: none;
    margin: 0;
}
.settings-panel--ai > .block-header {
    padding: 12px 16px;
    border: 1px solid var(--border);
    background: var(--ai-card);
}
.ai-screen-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 8px;
    min-width: 0;
}
.ai-heading-icon,
.ai-card-title span,
.ai-summary-icon {
    display: inline-grid;
    place-items: center;
    width: 36px;
    height: 36px;
    flex: none;
    border-radius: var(--radius);
    background: var(--accent);
    color: var(--ai);
}
.ai-summary-icon {
    padding: 8px;
    margin-inline-end: 8px;
    vertical-align: middle;
}
.ai-heading-copy {
    flex: 1;
    min-width: 0;
}
.ai-heading-line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px 8px;
}
.ai-heading-line h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 650;
}
.ai-heading-line span {
    border-radius: 999px;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 12px;
    padding: 2px 8px;
}
.ai-heading-copy p,
.ai-save-bar small {
    color: var(--text-secondary);
    font-size: 12px;
}
.ai-return {
    display: grid;
    place-items: center;
    width: var(--control-height);
    height: var(--control-height);
    flex: none;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
}
.ai-save-bar {
    display: grid;
    gap: 4px;
    text-align: center;
}
.ai-save-bar small {
    line-height: 1.5;
}
.ai-editor-tabs {
    display: grid;
    gap: 12px;
}
.ai-editor-tab-list {
    gap: 4px;
    padding: 0;
    background: var(--ai-page);
}
.ai-editor-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-bottom: 2px solid transparent;
    color: var(--text-secondary);
    font-weight: 550;
}
.ai-editor-tab[data-state='active'] {
    border-color: var(--ring);
    color: var(--link);
}
.ai-editor-tab-panel {
    background: var(--ai-card);
    scroll-margin-top: 12px;
}
.ai-card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 16px;
    font-weight: 650;
}
.ai-summary {
    order: 1;
    background: var(--ai-card);
}
.ai-summary-details {
    display: grid;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    color: var(--foreground);
    font-size: 13px;
}
.ai-summary-details div {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 4px 12px;
}
.ai-summary-details dd {
    color: var(--text-secondary);
}
@media (max-width: 1023px) {
    .settings-panel--ai {
        padding-bottom: 96px;
    }
    .ai-save-bar {
        position: fixed;
        z-index: 20;
        inset-inline: 0;
        bottom: calc(
        env(safe-area-inset-bottom) +
            max(0px, 100dvh - var(--visible-viewport-height, 100dvh) - var(--visible-viewport-top, 0px))
    );
        padding: 8px var(--page-gutter);
        border-top: 1px solid var(--border);
        background: var(--ai-card);
    }
    .ai-editor-tab {
        flex: 1 0 auto;
        justify-content: center;
        padding-inline: 6px;
        font-size: 12px;
    }
}
@media (max-width: 440px) {
    .ai-editor-tab > svg {
        display: none;
    }
    .ai-heading-icon {
        width: 32px;
        height: 32px;
    }
}
</style>

<style scoped>
.settings-panel--ai {
    grid-template-columns: minmax(0, 1fr);
}
.settings-panel--ai > * {
    grid-column: 1;
}
.settings-panel--ai > .delete-node {
    width: fit-content;
    margin-inline: 0;
}
.settings-panel--ai > .ai-screen-header,
.settings-panel--ai > .ai-editor-tab-list {
    grid-column: 1 / -1;
}
.settings-panel--ai > .ai-editor-tabs {
    grid-column: 1;
    grid-row: 3;
    width: 100%;
    max-width: 1100px;
    margin-inline: auto;
}
.ai-editor-tab-list {
    flex: none;
    justify-content: flex-start;
    border-radius: 0;
    height: auto;
    padding: 0;
    overflow-x: auto;
}
.ai-editor-tab {
    flex: none;
    min-height: 44px;
    padding: 8px 16px;
    border-radius: 0;
    box-shadow: none;
    background: transparent;
    color: var(--text-secondary);
    border: 0;
    border-bottom: 2px solid transparent;
}
.ai-editor-tab[data-state='active'] {
    background: transparent;
    border-bottom-color: var(--ring);
    color: var(--link);
}
.ai-editor-tab-panel {
    margin: 0;
    min-width: 0;
}
.ai-editor-tab-panel[data-state='inactive'] {
    display: none;
}
.ai-name-card {
    margin-bottom: 16px;
}
.ai-name-card :deep(.block-header) {
    margin-bottom: 0;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--ai-card);
}
.ai-name-card :deep(.block-title) {
    min-height: 36px;
}
.ai-name-card :deep(.block-heading > small) {
    display: none;
}
.ai-screen-header {
    flex-wrap: wrap;
}
.ai-heading-copy {
    flex-basis: 120px;
}
.ai-summary-row {
    display: flex;
    justify-content: flex-end;
}
@media (min-width: 1200px) {
    .settings-panel--ai.ai-summary-expanded {
        grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
    }
    .settings-panel--ai > .ai-summary {
        grid-column: 2;
        grid-row: 3;
        align-self: start;
    }
    .settings-panel--ai > .ai-summary-row {
        grid-row: 3;
    }
    .settings-panel--ai > .ai-summary-row ~ .ai-editor-tabs {
        grid-row: 4;
    }
}
@media (max-width: 1199px) {
    .ai-summary-row > button {
        width: 100%;
        justify-content: flex-start;
    }
    .ai-summary-row > button :deep(svg:last-child) {
        margin-inline-start: auto;
    }
    .settings-panel--ai > .ai-editor-tabs {
        grid-row: auto;
    }
}
</style>
<style>
.ai-summary-sheet {
    max-height: min(85dvh, var(--visible-viewport-height, 100dvh));
    overflow-y: auto;
    padding: 24px 16px max(24px, env(safe-area-inset-bottom));
    border-radius: var(--radius-dialog) var(--radius-dialog) 0 0;
}
</style>

<style scoped>
.editor-workspace {
    position: relative;
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr);
}
.editor-workspace:not(.editor-workspace--selected):not(.editor-workspace--ai) > .settings-panel {
    display: none;
}
.editor-workspace--selected > .flow-canvas {
    grid-area: 1 / 1;
}
.block-fields {
    display: contents;
}
.flow-canvas {
    display: grid;
    grid-template-rows: minmax(0, 1fr) auto;
}
.flow-canvas :deep(.vue-flow__controls) {
    display: flex;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
    box-shadow: var(--shadow-popover);
}
.flow-canvas :deep(.vue-flow__controls-button) {
    display: grid;
    width: 36px;
    height: 36px;
    place-items: center;
    border-radius: var(--radius);
    color: var(--foreground);
    cursor: pointer;
}
.flow-canvas :deep(.vue-flow__controls-button:hover) {
    background: var(--accent);
}
.flow-canvas :deep(.vue-flow__controls-button svg) {
    width: 16px;
    height: 16px;
}
@media (max-width: 767px) {
    .flow-canvas :deep(.vue-flow__controls-button) {
        width: 44px;
        height: 44px;
    }
}
.flow-activity {
    max-height: min(34dvh, 320px);
    overflow: auto;
    border-top: 1px solid var(--border);
    background: var(--card);
    padding: 0 16px;
}
.flow-activity > summary {
    min-height: 44px;
    align-content: center;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}
.flow-activity[open] {
    padding-bottom: 12px;
}
.flow-state {
    border-radius: var(--radius);
    padding: 4px 8px;
    background: var(--muted);
    font-size: 12px;
    font-weight: 600;
}
.published-pill--active {
    color: var(--success);
    background: var(--success-soft);
}
@media (min-width: 1200px) {
    .editor-workspace--selected {
        grid-template-columns: minmax(0, 1fr) 320px;
    }
    .editor-workspace--selected > .settings-panel {
        grid-area: 1 / 2;
        border-right: 0;
        border-left: 1px solid var(--border);
    }
}
@media (max-width: 1199px) {
    .editor-workspace--selected > .settings-panel {
        position: absolute;
        inset: 0 0 0 auto;
        width: min(320px, 85%);
        z-index: 12;
        box-shadow: var(--shadow-popover);
        border-left: 1px solid var(--border);
        border-right: 0;
    }
}
@media (max-width: 767px) {
    .editor-workspace--selected > .settings-panel {
        inset: 0;
        width: 100%;
        border-left: 0;
    }
}
</style>

<style scoped>
.settings-panel:not(.settings-panel--ai) .ai-editor-tab-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    height: auto;
    align-items: stretch;
    overflow: visible;
}
.settings-panel:not(.settings-panel--ai) .ai-editor-tab {
    height: auto;
    min-height: 44px;
    padding: 6px 2px;
    white-space: normal;
    font-size: 12px;
    line-height: 1.25;
    overflow-wrap: anywhere;
}
.settings-panel:not(.settings-panel--ai) .ai-editor-tab > svg {
    display: none;
}
.settings-panel:not(.settings-panel--ai) .ai-editor-tab-panel {
    padding: 12px 0;
    border: 0;
}
.ai-add-path {
    width: 100%;
    margin-block: 12px;
}
.ai-path summary, .ai-resource summary {
    cursor: pointer;
    overflow-wrap: anywhere;
    color: var(--foreground);
}
.ai-resource[open] summary {
    margin-bottom: 12px;
}
</style>

<style scoped>
.flow-title input,
.settings-panel :deep(.block-header button),
.editor-topbar :deep(button[data-variant='secondary']),
.editor-topbar :deep(button[data-variant='ghost']) {
    color: var(--foreground);
}
.editor-topbar :deep(button[data-variant='secondary']) {
    background: var(--card);
    border-color: var(--border);
}
.settings-panel .ai-editor-tab {
    color: var(--text-secondary);
}
.settings-panel .ai-editor-tab[data-state='active'] {
    color: var(--link);
}
</style>

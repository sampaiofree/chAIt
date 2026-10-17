<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ArrowUp, ArrowDown, Plus, Trash2 } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppIconButton from '@/components/AppIconButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppDialog from '@/components/AppDialog.vue';
import AutomationNavigation from '@/components/AutomationNavigation.vue';
import SequenceStatus from '@/components/SequenceStatus.vue';
import { Input } from '@/components/ui/input';
import { useAppDialog } from '@/composables/useAppDialog';
import {
    sequenceRequest,
    SequenceRequestError,
    type SequenceSnapshot,
} from '@/lib/sequences';
type SavedStep = {
    schedule_type: 'interval' | 'specific_datetime';
    flow_id: number | null;
    delay_seconds: number | null;
    scheduled_at: string | null;
};
type EditorStep = SavedStep & {
    key: string;
    amount: number | null;
    unit: number;
};
type Draft = { name: string; timezone: string; steps: SavedStep[] };
const props = defineProps<{
    workspace: { id: number; name: string };
    sequence: {
        id: number;
        name: string;
        draft: Draft;
        revision: number;
        is_active: boolean;
    };
    flows: { id: number; name: string }[];
    timezones: string[];
    published_version: number | null;
    enrollments: (SequenceSnapshot & { contact_name?: string })[];
}>();
const draft = reactive({
    name: props.sequence.draft.name,
    timezone: props.sequence.draft.timezone,
    steps: props.sequence.draft.steps.map((step): EditorStep => {
        const seconds = step.delay_seconds;
        const unit =
            seconds && seconds % 86400 === 0
                ? 86400
                : seconds && seconds % 3600 === 0
                  ? 3600
                  : 60;
        return {
            ...step,
            key: crypto.randomUUID(),
            unit,
            amount: seconds ? seconds / unit : null,
        };
    }),
});
const revision = ref(props.sequence.revision);
const active = ref(props.sequence.is_active);
const published = ref(props.published_version);
const busy = ref(false);
const notice = ref('');
const errors = ref<Record<string, string>>({});
const flowSearch = ref('');
const { dialog, confirm, finish } = useAppDialog();
const base = `/workspaces/${props.workspace.id}/sequences/${props.sequence.id}`;
function payload(): Draft {
    return {
        name: draft.name,
        timezone: draft.timezone,
        steps: draft.steps.map((step) => ({
            schedule_type: step.schedule_type,
            flow_id: step.flow_id,
            delay_seconds:
                step.schedule_type === 'interval' && step.amount !== null
                    ? Number(step.amount) * step.unit
                    : null,
            scheduled_at:
                step.schedule_type === 'specific_datetime'
                    ? step.scheduled_at || null
                    : null,
        })),
    };
}
const saved = ref(JSON.stringify(payload()));
const dirty = computed(() => JSON.stringify(payload()) !== saved.value);
function add() {
    draft.steps.push({
        key: crypto.randomUUID(),
        schedule_type: 'interval',
        flow_id: null,
        delay_seconds: 60,
        scheduled_at: null,
        amount: 1,
        unit: 60,
    });
}
function move(i: number, direction: number) {
    const next = i + direction;
    if (next >= 0 && next < draft.steps.length)
        [draft.steps[i], draft.steps[next]] = [
            draft.steps[next],
            draft.steps[i],
        ];
    errors.value = {};
}
async function remove(i: number) {
    if (
        await confirm(
            'Excluir esta etapa do rascunho? Participações já inscritas mantêm sua programação.',
            'Excluir etapa',
        )
    ) {
        draft.steps.splice(i, 1);
        errors.value = {};
    }
}
function field(i: number, name: string) {
    return errors.value[`steps.${i}.${name}`] || '';
}
function flowsFor(step: EditorStep) {
    const term = flowSearch.value.toLocaleLowerCase('pt-BR');
    return props.flows.filter(
        (flow) =>
            flow.id === step.flow_id ||
            flow.name.toLocaleLowerCase('pt-BR').includes(term),
    );
}
function handleError(e: unknown) {
    notice.value =
        e instanceof Error ? e.message : 'Falha na conexão. Tente novamente.';
    errors.value = e instanceof SequenceRequestError ? e.fields : {};
}
async function saveDraft(): Promise<boolean> {
    const result = await sequenceRequest<{ data: { revision: number } }>(
        base,
        'PUT',
        { ...payload(), revision: revision.value },
    );
    revision.value = result.data.revision;
    saved.value = JSON.stringify(payload());
    errors.value = {};
    return true;
}
async function save() {
    if (busy.value) return;
    busy.value = true;
    notice.value = '';
    try {
        await saveDraft();
        notice.value = 'Rascunho salvo.';
    } catch (e) {
        handleError(e);
    } finally {
        busy.value = false;
    }
}
async function publish() {
    if (busy.value) return;
    busy.value = true;
    notice.value = '';
    try {
        if (dirty.value) await saveDraft();
        const result = await sequenceRequest<{ data: { version: number } }>(
            `${base}/publish`,
            'POST',
            { revision: revision.value },
        );
        published.value = result.data.version;
        active.value = true;
        notice.value =
            'Sequência publicada. Novas inscrições usarão esta versão.';
        errors.value = {};
    } catch (e) {
        handleError(e);
    } finally {
        busy.value = false;
    }
}
async function toggle() {
    if (busy.value) return;
    if (
        active.value &&
        !(await confirm(
            'Novas entradas serão bloqueadas. As participações existentes continuarão normalmente.',
            'Desativar sequência',
        ))
    )
        return;
    busy.value = true;
    try {
        await sequenceRequest(
            `${base}/${active.value ? 'deactivate' : 'activate'}`,
            'POST',
        );
        active.value = !active.value;
        notice.value = active.value
            ? 'Novas entradas liberadas.'
            : 'Novas entradas bloqueadas.';
    } catch (e) {
        handleError(e);
    } finally {
        busy.value = false;
    }
}
let allowNavigation = false;
let confirmingNavigation = false;
const stopGuard = router.on('before', (event) => {
    if (allowNavigation || (!dirty.value && !busy.value)) return;
    event.preventDefault();
    if (busy.value || confirmingNavigation) return;
    confirmingNavigation = true;
    const visit = event.detail.visit;
    void confirm(
        'As alterações do rascunho ainda não foram salvas. Deseja descartá-las?',
        'Descartar alterações',
    ).then((accepted) => {
        confirmingNavigation = false;
        if (accepted) {
            allowNavigation = true;
            router.visit(visit.url, visit);
        }
    });
});
function beforeUnload(event: BeforeUnloadEvent) {
    if (dirty.value || busy.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => {
    stopGuard();
    window.removeEventListener('beforeunload', beforeUnload);
});
</script>
<template>
    <Head :title="draft.name || 'Editar sequência'" />
    <main class="sequence-editor">
        <AutomationNavigation :workspace-id="workspace.id" active="sequences" />
        <Link :href="`/workspaces/${workspace.id}/sequences`" class="back"
            >Voltar às sequências</Link
        >
        <header>
            <div>
                <h1>Programação da sequência</h1>
                <p>
                    {{ active ? 'Ativa' : published ? 'Inativa' : 'Rascunho'
                    }}<span v-if="published"> · Versão {{ published }}</span> ·
                    {{ dirty ? 'Alterações não salvas' : 'Rascunho salvo' }}
                </p>
            </div>
            <div class="header-actions">
                <AppButton variant="secondary" :disabled="busy" @click="save"
                    >Salvar rascunho</AppButton
                ><AppButton :loading="busy" @click="publish"
                    >Publicar sequência</AppButton
                ><AppButton
                    v-if="published"
                    variant="ghost"
                    :disabled="busy"
                    @click="toggle"
                    >{{ active ? 'Desativar' : 'Reativar' }}</AppButton
                >
            </div>
        </header>
        <p v-if="notice" class="notice" role="status">{{ notice }}</p>
        <fieldset :disabled="busy" class="definition-fields">
            <label for="sequence-title"
                >Nome da sequência<Input
                    id="sequence-title"
                    v-model="draft.name"
                    maxlength="120"
                    :aria-invalid="Boolean(errors.name)"
                    aria-describedby="sequence-title-error"
                /><small
                    v-if="errors.name"
                    id="sequence-title-error"
                    class="error"
                    >{{ errors.name }}</small
                ></label
            ><label for="sequence-timezone"
                >Fuso horário<AppSelect
                    id="sequence-timezone"
                    v-model="draft.timezone"
                    :aria-invalid="Boolean(errors.timezone)"
                    aria-describedby="timezone-help"
                    ><option
                        v-for="zone in timezones"
                        :key="zone"
                        :value="zone"
                    >
                        {{ zone }}
                    </option></AppSelect
                ><small id="timezone-help"
                    >Datas específicas usam este fuso.
                    {{ errors.timezone }}</small
                ></label
            >
        </fieldset>
        <div class="flow-search">
            <label for="sequence-flow-search"
                >Buscar fluxos publicados<Input
                    id="sequence-flow-search"
                    v-model="flowSearch"
                    type="search"
                    placeholder="Nome do fluxo"
            /></label>
        </div>
        <p v-if="!flows.length" class="notice">
            Publique um fluxo de conversa antes de configurar as etapas.
        </p>
        <p v-if="errors.steps" class="error" role="alert">{{ errors.steps }}</p>
        <ol class="timeline">
            <li
                v-for="(step, index) in draft.steps"
                :key="step.key"
                class="stage"
            >
                <div class="stage-header">
                    <h2>Etapa {{ index + 1 }}</h2>
                    <div class="stage-actions">
                        <AppIconButton
                            :disabled="busy || index === 0"
                            label="Mover etapa para cima"
                            @click="move(index, -1)"
                            ><ArrowUp :size="16" /></AppIconButton
                        ><AppIconButton
                            :disabled="busy || index === draft.steps.length - 1"
                            label="Mover etapa para baixo"
                            @click="move(index, 1)"
                            ><ArrowDown :size="16" /></AppIconButton
                        ><AppIconButton
                            :disabled="busy"
                            label="Excluir etapa"
                            @click="remove(index)"
                            ><Trash2 :size="16"
                        /></AppIconButton>
                    </div>
                </div>
                <fieldset :disabled="busy" class="stage-fields">
                    <label :for="`schedule-${step.key}`"
                        >Programação<AppSelect
                            :id="`schedule-${step.key}`"
                            v-model="step.schedule_type"
                            ><option value="interval">Após um intervalo</option>
                            <option value="specific_datetime">
                                Data específica
                            </option></AppSelect
                        ></label
                    >
                    <div
                        v-if="step.schedule_type === 'interval'"
                        class="interval"
                    >
                        <label :for="`amount-${step.key}`"
                            >Quantidade<Input
                                :id="`amount-${step.key}`"
                                v-model.number="step.amount"
                                type="number"
                                min="1"
                                :max="31536000 / step.unit"
                                :aria-invalid="
                                    Boolean(field(index, 'delay_seconds'))
                                "
                                :aria-describedby="`timing-${step.key}`" /></label
                        ><label :for="`unit-${step.key}`"
                            >Unidade<AppSelect
                                :id="`unit-${step.key}`"
                                v-model="step.unit"
                                ><option :value="60">Minutos</option>
                                <option :value="3600">Horas</option>
                                <option :value="86400">Dias</option></AppSelect
                            ></label
                        >
                    </div>
                    <label v-else :for="`date-${step.key}`"
                        >Data e hora<Input
                            :id="`date-${step.key}`"
                            v-model="step.scheduled_at"
                            type="datetime-local"
                            :aria-invalid="
                                Boolean(field(index, 'scheduled_at'))
                            "
                            :aria-describedby="`timing-${step.key}`"
                    /></label>
                    <label :for="`flow-${step.key}`"
                        >Fluxo publicado<AppSelect
                            :id="`flow-${step.key}`"
                            v-model="step.flow_id"
                            :aria-invalid="Boolean(field(index, 'flow_id'))"
                            :aria-describedby="`flow-error-${step.key}`"
                            ><option :value="null">Selecionar fluxo</option>
                            <option
                                v-if="
                                    step.flow_id &&
                                    !flows.some(
                                        (flow) => flow.id === step.flow_id,
                                    )
                                "
                                :value="step.flow_id"
                            >
                                Fluxo indisponível — selecione outro
                            </option>
                            <option
                                v-for="flow in flowsFor(step)"
                                :key="flow.id"
                                :value="flow.id"
                            >
                                {{ flow.name }}
                            </option></AppSelect
                        ><small
                            v-if="field(index, 'flow_id')"
                            :id="`flow-error-${step.key}`"
                            class="error"
                            >{{ field(index, 'flow_id') }}</small
                        ></label
                    >
                </fieldset>
                <p :id="`timing-${step.key}`" class="help">
                    {{
                        step.schedule_type === 'interval'
                            ? 'A contagem começa após a conclusão do fluxo anterior e pausa enquanto houver atendimento/fluxo em andamento. Intervalos de 1 minuto a 365 dias.'
                            : 'Se o contato estiver ocupado nesse horário, a etapa aguardará até estar disponível. Datas vencidas aguardam sua vez na sequência.'
                    }}<span
                        v-if="
                            field(index, 'delay_seconds') ||
                            field(index, 'scheduled_at')
                        "
                        class="error"
                    >
                        {{
                            field(index, 'delay_seconds') ||
                            field(index, 'scheduled_at')
                        }}</span
                    >
                </p>
            </li>
        </ol>
        <section v-if="!draft.steps.length" class="empty">
            <h2>Adicione a primeira etapa</h2>
            <p>Escolha quando o fluxo deve começar.</p>
        </section>
        <AppButton
            variant="secondary"
            :disabled="busy || draft.steps.length >= 100"
            @click="add"
            ><Plus :size="18" />Adicionar etapa</AppButton
        >
        <section v-if="enrollments.length" class="participations">
            <h2>Participações recentes</h2>
            <p>Inscrições existentes preservam a versão em que entraram.</p>
            <article v-for="enrollment in enrollments" :key="enrollment.id">
                <h3 v-if="enrollment.contact_name">
                    {{ enrollment.contact_name }}
                </h3>
                <SequenceStatus :enrollment="enrollment" />
            </article>
        </section>
        <AppDialog :dialog="dialog" @resolve="finish" />
    </main>
</template>
<style scoped>
.sequence-editor {
    width: 100%;
    max-width: 1200px;
    min-width: 0;
    margin: auto;
    padding: 24px;
}
.back {
    display: inline-block;
    color: var(--muted-foreground);
    font-size: 13px;
    margin-bottom: 16px;
}
header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
h1 {
    font-size: 24px;
    font-weight: 600;
}
h2 {
    font-weight: 600;
    font-size: 16px;
}
p,
small {
    color: var(--muted-foreground);
    font-size: 13px;
}
.header-actions,
.stage-actions {
    display: flex;
    gap: var(--form-button-gap);
    flex-wrap: wrap;
}
label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 13px;
    min-width: 0;
}
.definition-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: start;
    gap: var(--form-field-gap);
    margin-bottom: 24px;
}
.definition-fields > label {
    align-content: start;
}
.flow-search {
    max-width: 400px;
    margin-bottom: 24px;
}
.timeline {
    padding-left: 24px;
    border-left: 2px solid var(--border);
    margin-bottom: 24px;
    display: grid;
    gap: 20px;
}
.stage {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    position: relative;
    min-width: 0;
}
.stage::before {
    content: '';
    position: absolute;
    left: -32px;
    top: 24px;
    border: 3px solid var(--background);
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--primary);
}
.stage-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    gap: 12px;
}
.stage-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--form-field-gap);
}
.stage-fields > label:last-child {
    grid-column: 1 / -1;
}
.interval {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--form-field-gap);
}
.help {
    margin-top: 16px;
    line-height: 1.6;
}
.error {
    color: var(--danger);
}
.notice {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 16px;
    overflow-wrap: anywhere;
}
.empty {
    padding: 24px 0;
}
.participations {
    margin-top: 40px;
    display: grid;
    gap: 12px;
}
.participations article {
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
@media (max-width: 640px) {
    .sequence-editor {
        padding: 16px;
    }
    .definition-fields,
    .stage-fields {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .timeline {
        padding-left: 16px;
    }
    .stage::before {
        left: -24px;
    }
    .stage {
        padding: 16px;
    }
    .stage-header {
        flex-wrap: wrap;
    }
}
</style>

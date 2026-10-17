<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { Copy, Plus, Trash2, ArrowUp, ArrowDown } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useAppDialog } from '@/composables/useAppDialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    webhookRequest,
    WebhookRequestError,
    receiptStates,
    errorCodes,
} from '@/lib/webhooks';
import type { WebhookSnapshot, WebhookRule } from '@/lib/webhooks';
type Mapping = { source: string; target: string };
type Rule = {
    always: boolean;
    source: string;
    valueType: string;
    expected: string;
    add: number[];
    remove: number[];
};
const props = defineProps<{
    workspace: { id: number; name: string };
    webhook: WebhookSnapshot;
    fields: { id: number; name: string; type: string }[];
    tags: { id: number; name: string; color: string }[];
}>();
const endpoint = `/workspaces/${props.workspace.id}/webhooks/${props.webhook.id}`;
const { dialog, confirm, finish } = useAppDialog();
const snapshot = ref(props.webhook);
const form = reactive({
    name: props.webhook.name,
    status: props.webhook.status,
    mappings: props.webhook.mappings.map((m) => ({
        source: JSON.stringify(m.source_path),
        target: `${m.target_kind}:${m.target}`,
    })) as Mapping[],
    rules: props.webhook.tag_rules.map((r) => ({
        always: r.condition === null,
        source: r.condition ? JSON.stringify(r.condition.source_path) : '',
        valueType: r.condition
            ? typeof r.condition.value === 'string'
                ? 'string'
                : typeof r.condition.value === 'boolean'
                  ? 'boolean'
                  : 'number'
            : 'string',
        expected: r.condition ? String(r.condition.value) : '',
        add: [...r.add],
        remove: [...r.remove],
    })) as Rule[],
});
const saved = ref(JSON.stringify(form));
const payload = ref(props.webhook.sample_payload || '');
const payloadSaved = ref(payload.value);
const dirty = computed(
    () =>
        JSON.stringify(form) !== saved.value ||
        payload.value !== payloadSaved.value,
);
const busy = ref(false);
const notice = ref('');
const error = ref('');
const pollError = ref('');
const errors = ref<Record<string, string>>({});
const simulation = ref<{
    status: string;
    warnings: { mapping: number; code: string }[];
    native_fields: string[];
    custom_fields: number[];
    tag_actions: Record<string, string>;
    matched_rules: number[];
} | null>(null);
const fieldOpen = ref(false);
const fieldForm = useForm({ name: '' });
const working = computed(() => busy.value || fieldForm.processing);
const sources = computed(() =>
    snapshot.value.payload_fields.map((f) => ({
        ...f,
        key: JSON.stringify(f.path),
    })),
);
const conflicts = computed(() => {
    const add = new Set(form.rules.flatMap((r) => r.add));
    return props.tags
        .filter(
            (t) =>
                add.has(t.id) &&
                form.rules.some((r) => r.remove.includes(t.id)),
        )
        .map((t) => t.name);
});
function configuration() {
    return {
        name: form.name,
        status: form.status,
        mappings: form.mappings.map((m) => {
            const [kind, target] = m.target.split(':');
            return {
                source_path: m.source ? JSON.parse(m.source) : [],
                target_kind: kind,
                target: kind === 'custom' ? Number(target) : target,
            };
        }),
        tag_rules: form.rules.map((r, i): WebhookRule => {
            if (
                !r.always &&
                r.valueType === 'number' &&
                (!String(r.expected).trim() ||
                    !Number.isFinite(Number(r.expected)))
            )
                throw new WebhookRequestError('Informe um número válido.', {
                    [`tag_rules.${i}.condition.value`]:
                        'Informe um número válido.',
                });
            return {
                condition: r.always
                    ? null
                    : {
                          source_path: r.source ? JSON.parse(r.source) : [],
                          value:
                              r.valueType === 'number'
                                  ? Number(r.expected)
                                  : r.valueType === 'boolean'
                                    ? r.expected === 'true'
                                    : r.expected,
                      },
                add: r.add,
                remove: r.remove,
            };
        }),
    };
}
function acceptSnapshot(next: WebhookSnapshot) {
    const untouched = payload.value === payloadSaved.value;
    snapshot.value = next;
    if (untouched) {
        payload.value = next.sample_payload || '';
        payloadSaved.value = payload.value;
    }
}
async function action(callback: () => Promise<void>) {
    if (working.value) return;
    busy.value = true;
    error.value = '';
    errors.value = {};
    notice.value = '';
    try {
        await callback();
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'Não foi possível concluir a ação.';
        if (e instanceof WebhookRequestError) errors.value = e.fields;
    } finally {
        busy.value = false;
    }
}
function save() {
    return action(async () => {
        const result = await webhookRequest<{ data: WebhookSnapshot }>(
            endpoint,
            'PUT',
            configuration(),
        );
        acceptSnapshot(result.data);
        saved.value = JSON.stringify(form);
        notice.value = 'Configuração salva.';
    });
}
function importPayload() {
    return action(async () => {
        const result = await webhookRequest<{ data: WebhookSnapshot }>(
            `${endpoint}/sample`,
            'POST',
            { payload: payload.value },
        );
        payloadSaved.value = payload.value;
        acceptSnapshot(result.data);
        notice.value = 'Estrutura identificada.';
        simulation.value = null;
    });
}
function capture() {
    return action(async () => {
        const result = await webhookRequest<{ data: WebhookSnapshot }>(
            `${endpoint}/capture`,
            'POST',
        );
        acceptSnapshot(result.data);
        notice.value =
            'Envie uma requisição à URL nos próximos dez minutos. Em Ativo, ela também será processada.';
    });
}
async function clearSample() {
    if (
        working.value ||
        !(await confirm(
            'O exemplo será apagado. Os campos detectados e a configuração permanecem.',
            'Apagar exemplo?',
        ))
    )
        return;
    return action(async () => {
        const result = await webhookRequest<{ data: WebhookSnapshot }>(
            `${endpoint}/sample`,
            'DELETE',
        );
        payload.value = '';
        payloadSaved.value = '';
        acceptSnapshot(result.data);
        simulation.value = null;
        notice.value = 'Exemplo apagado.';
    });
}
function simulate() {
    return action(async () => {
        simulation.value = null;
        const result = await webhookRequest<{
            data: NonNullable<typeof simulation.value>;
        }>(`${endpoint}/simulate`, 'POST', {
            ...configuration(),
            payload: payload.value,
        });
        simulation.value = result.data;
        notice.value =
            'Simulação concluída. Nenhum contato ou tag foi alterado.';
    });
}
async function copy() {
    try {
        await navigator.clipboard.writeText(snapshot.value.url);
        notice.value = 'URL copiada.';
    } catch {
        error.value = 'Selecione a URL e copie manualmente.';
    }
}
function addMapping() {
    form.mappings.push({
        source: '',
        target: form.mappings.some((m) => m.target === 'native:phone')
            ? 'native:display_name'
            : 'native:phone',
    });
}
function addRule() {
    form.rules.push({
        always: true,
        source: '',
        valueType: 'string',
        expected: '',
        add: [],
        remove: [],
    });
}
function moveRule(index: number, offset: number) {
    const [rule] = form.rules.splice(index, 1);
    form.rules.splice(index + offset, 0, rule);
}
function sourceLabel(key: string) {
    return (
        sources.value.find((f) => f.key === key)?.label ||
        'Origem ausente no exemplo'
    );
}
function saveField() {
    if (working.value) return;
    fieldForm.post(`/workspaces/${props.workspace.id}/contact-fields`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            fieldOpen.value = false;
            fieldForm.reset();
            fieldForm.clearErrors();
            notice.value = 'Campo personalizado criado.';
        },
    });
}
watch(fieldOpen, (value) => {
    if (!value) {
        fieldForm.reset();
        fieldForm.clearErrors();
    }
});
let polling: ReturnType<typeof setInterval> | undefined;
let pollingNow = false;
const pollAbort = new AbortController();
async function poll() {
    if (pollingNow || working.value || document.hidden) return;
    pollingNow = true;
    try {
        const result = await webhookRequest<{ data: WebhookSnapshot }>(
            `${endpoint}/snapshot`,
            'GET',
            undefined,
            pollAbort.signal,
        );
        acceptSnapshot(result.data);
        pollError.value = '';
    } catch {
        if (!pollAbort.signal.aborted)
            pollError.value =
                'Não foi possível atualizar os recebimentos. Tentaremos novamente.';
    } finally {
        pollingNow = false;
    }
}
let allowNavigation = false;
const stopGuard = router.on('before', (event) => {
    if (
        allowNavigation ||
        (!dirty.value && !working.value) ||
        event.detail.visit.method !== 'get'
    )
        return;
    event.preventDefault();
    if (working.value) return;
    void confirm(
        'Os dados preenchidos ainda não foram salvos.',
        'Descartar alterações?',
    ).then((ok) => {
        if (ok) {
            allowNavigation = true;
            router.visit(event.detail.visit.url.href);
        }
    });
});
function beforeUnload(event: BeforeUnloadEvent) {
    if (dirty.value || working.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => {
    polling = setInterval(() => void poll(), 3000);
    window.addEventListener('beforeunload', beforeUnload);
});
onBeforeUnmount(() => {
    if (polling) clearInterval(polling);
    pollAbort.abort();
    stopGuard();
    window.removeEventListener('beforeunload', beforeUnload);
});
</script>
<template>
    <Head :title="`Webhook — ${snapshot.name}`" />
    <SettingsLayout active="webhooks"
        ><section class="webhook-editor">
            <Link
                :href="`/workspaces/${workspace.id}/webhooks`"
                class="back-link"
                >Voltar aos Webhooks</Link
            >
            <form @submit.prevent="save">
                <header>
                    <div>
                        <h1>Configurar Webhook</h1>
                        <p>
                            {{ workspace.name }} · Estado atual:
                            <strong>{{
                                snapshot.status === 'active' ? 'Ativo' : 'Teste'
                            }}</strong>
                        </p>
                    </div>
                    <AppButton type="submit" :disabled="working">{{
                        busy ? 'Aguarde…' : 'Salvar alterações'
                    }}</AppButton>
                </header>
                <div class="header-fields">
                    <label
                        >Nome do Webhook<Input
                            v-model="form.name"
                            required
                            maxlength="120"
                            :disabled="working"
                            :aria-invalid="Boolean(errors.name)"
                            aria-describedby="webhook-name-error"
                        /><span
                            v-if="errors.name"
                            id="webhook-name-error"
                            class="form-error"
                            >{{ errors.name }}</span
                        ></label
                    ><label
                        >Estado ao salvar<AppSelect
                            v-model="form.status"
                            aria-label="Estado ao salvar"
                            :disabled="working"
                            ><option value="test">Teste</option>
                            <option value="active">Ativo</option></AppSelect
                        ><small
                            >Em Teste, requisições não alteram contatos nem
                            tags.</small
                        ></label
                    >
                </div>
                <p v-if="notice" role="status" class="notice">{{ notice }}</p>
                <div v-if="error" role="alert" class="error-summary">
                    <p>{{ error }}</p>
                    <p v-for="(message, key) in errors" :key="key">
                        {{ message }}
                    </p>
                </div>
                <fieldset :disabled="working">
                    <section class="section">
                        <h2>1. URL do Webhook</h2>
                        <p>
                            Cole esta URL na plataforma externa. Qualquer pessoa
                            com a URL pode enviar dados; compartilhe-a apenas
                            com a integração.
                        </p>
                        <div class="url-row">
                            <Input
                                :model-value="snapshot.url"
                                readonly
                                aria-label="URL do Webhook"
                            /><AppButton variant="secondary" @click="copy"
                                ><Copy :size="16" />Copiar URL</AppButton
                            >
                        </div>
                    </section>
                    <section class="section">
                        <h2>2. Dados recebidos (Payload)</h2>
                        <p>
                            Capture uma requisição real ou cole um objeto JSON
                            com um contato. Arrays internos permitem selecionar
                            índices fixos.
                        </p>
                        <div class="actions">
                            <AppButton variant="secondary" @click="capture"
                                >Capturar automaticamente</AppButton
                            ><AppButton
                                variant="ghost"
                                :disabled="!snapshot.sample_payload"
                                @click="clearSample"
                                >Apagar exemplo</AppButton
                            >
                        </div>
                        <p v-if="snapshot.capture_until" role="status">
                            Aguardando requisição até
                            {{
                                new Date(
                                    snapshot.capture_until,
                                ).toLocaleTimeString('pt-BR')
                            }}.
                        </p>
                        <p v-if="!snapshot.sample_received_at">
                            Nenhuma requisição recebida ou exemplo salvo.
                        </p>
                        <label
                            >JSON de exemplo<Textarea
                                v-model="payload"
                                rows="8"
                                placeholder='{"cliente":{"telefone":"5527999999999"}}'
                                spellcheck="false"
                        /></label>
                        <p class="help">
                            O último exemplo fica armazenado até ser substituído
                            ou apagado.
                        </p>
                        <div class="actions">
                            <AppButton
                                variant="secondary"
                                :disabled="!payload.trim()"
                                @click="importPayload"
                                >Identificar campos</AppButton
                            ><AppButton
                                variant="secondary"
                                :disabled="!payload.trim()"
                                @click="simulate"
                                >Simular sem alterar dados</AppButton
                            >
                        </div>
                        <details v-if="sources.length">
                            <summary>
                                {{ sources.length }} campos identificados
                            </summary>
                            <ul>
                                <li v-for="source in sources" :key="source.key">
                                    <code>{{ source.label }}</code>
                                    <small>({{ source.type }})</small>
                                </li>
                            </ul>
                        </details>
                        <div v-if="simulation" class="preview" role="status">
                            <strong>Simulado — sem alterações no CRM</strong>
                            <p>
                                {{
                                    simulation.native_fields.length +
                                    simulation.custom_fields.length
                                }}
                                campos opcionais válidos;
                                {{ simulation.matched_rules.length }} regras
                                atendidas;
                                {{ Object.keys(simulation.tag_actions).length }}
                                operações de tag.
                            </p>
                            <p
                                v-for="warning in simulation.warnings"
                                :key="warning.mapping"
                            >
                                Mapeamento {{ warning.mapping + 1 }}: valor
                                inválido ou incompatível; será preservado.
                            </p>
                        </div>
                    </section>
                    <section class="section">
                        <h2>3. Campos do contato</h2>
                        <p>
                            Mapeie o Telefone WhatsApp para ativar. Envie DDI +
                            número completo; não acrescentamos um país
                            automaticamente. Valores opcionais ausentes ou
                            vazios preservam o cadastro.
                        </p>
                        <p v-if="errors.mappings" class="form-error">
                            {{ errors.mappings }}
                        </p>
                        <p v-if="!form.mappings.length">
                            Nenhum campo mapeado.
                        </p>
                        <div
                            v-for="(mapping, index) in form.mappings"
                            :key="index"
                            class="mapping"
                        >
                            <label :for="`source-${index}`"
                                >Origem {{ index + 1
                                }}<AppSelect
                                    :id="`source-${index}`"
                                    v-model="mapping.source"
                                    :aria-label="`Origem ${index + 1}`"
                                    :aria-invalid="
                                        Boolean(
                                            errors[
                                                `mappings.${index}.source_path`
                                            ],
                                        )
                                    "
                                    :aria-describedby="`source-error-${index}`"
                                    ><option value="">
                                        Selecione um campo recebido
                                    </option>
                                    <option
                                        v-if="
                                            mapping.source &&
                                            !sources.some(
                                                (s) => s.key === mapping.source,
                                            )
                                        "
                                        :value="mapping.source"
                                    >
                                        {{ sourceLabel(mapping.source) }}
                                    </option>
                                    <option
                                        v-for="source in sources"
                                        :key="source.key"
                                        :value="source.key"
                                    >
                                        {{ source.label }}
                                    </option></AppSelect
                                ><span
                                    v-if="
                                        errors[`mappings.${index}.source_path`]
                                    "
                                    :id="`source-error-${index}`"
                                    class="form-error"
                                    >{{
                                        errors[`mappings.${index}.source_path`]
                                    }}</span
                                ><small
                                    v-else-if="
                                        mapping.source &&
                                        !sources.some(
                                            (s) => s.key === mapping.source,
                                        )
                                    "
                                    >Origem ausente no exemplo atual.</small
                                ></label
                            ><label :for="`target-${index}`"
                                >Destino {{ index + 1
                                }}<AppSelect
                                    :id="`target-${index}`"
                                    v-model="mapping.target"
                                    :aria-label="`Destino ${index + 1}`"
                                    :aria-invalid="
                                        Boolean(
                                            errors[`mappings.${index}.target`],
                                        )
                                    "
                                    :aria-describedby="`target-error-${index}`"
                                    ><option value="native:phone">
                                        Telefone WhatsApp (obrigatório)
                                    </option>
                                    <option value="native:display_name">
                                        Nome
                                    </option>
                                    <option value="native:email">E-mail</option>
                                    <option value="native:cpf">CPF</option>
                                    <option
                                        v-for="field in fields"
                                        :key="field.id"
                                        :value="`custom:${field.id}`"
                                    >
                                        {{ field.name }} (personalizado)
                                    </option></AppSelect
                                ><span
                                    v-if="errors[`mappings.${index}.target`]"
                                    :id="`target-error-${index}`"
                                    class="form-error"
                                    >{{
                                        errors[`mappings.${index}.target`]
                                    }}</span
                                ></label
                            ><AppButton
                                variant="ghost"
                                :aria-label="`Remover mapeamento ${index + 1}`"
                                :title="`Remover mapeamento ${index + 1}`"
                                @click="form.mappings.splice(index, 1)"
                                ><Trash2 :size="18"
                            /></AppButton>
                        </div>
                        <div class="actions">
                            <AppButton variant="secondary" @click="addMapping"
                                ><Plus :size="16" />Adicionar
                                mapeamento</AppButton
                            ><AppButton
                                variant="ghost"
                                @click="fieldOpen = true"
                                >Criar campo personalizado</AppButton
                            >
                        </div>
                    </section>
                    <section class="section">
                        <h2>4. Tags e condições</h2>
                        <p>
                            As regras são executadas de cima para baixo. Uma
                            condição lê o evento atual; igualdade diferencia
                            maiúsculas e tipos.
                        </p>
                        <p
                            v-if="conflicts.length"
                            class="warning"
                            role="status"
                        >
                            Ações opostas para {{ conflicts.join(', ') }}.
                            Quando ambas ocorrerem, a última regra prevalece.
                        </p>
                        <p v-if="!tags.length">
                            Cadastre tags em Configurações → Campos e tags para
                            selecioná-las aqui.
                        </p>
                        <p v-if="!form.rules.length">
                            Nenhuma regra de tag configurada.
                        </p>
                        <article
                            v-for="(rule, index) in form.rules"
                            :key="index"
                            class="rule"
                        >
                            <div class="rule-header">
                                <h3>Regra {{ index + 1 }}</h3>
                                <div class="actions">
                                    <AppButton
                                        variant="ghost"
                                        :disabled="index === 0"
                                        :aria-label="`Mover regra ${index + 1} para cima`"
                                        title="Mover para cima"
                                        @click="moveRule(index, -1)"
                                        ><ArrowUp :size="16" /></AppButton
                                    ><AppButton
                                        variant="ghost"
                                        :disabled="
                                            index === form.rules.length - 1
                                        "
                                        :aria-label="`Mover regra ${index + 1} para baixo`"
                                        title="Mover para baixo"
                                        @click="moveRule(index, 1)"
                                        ><ArrowDown :size="16" /></AppButton
                                    ><AppButton
                                        variant="ghost"
                                        :aria-label="`Remover regra ${index + 1}`"
                                        title="Remover regra"
                                        @click="form.rules.splice(index, 1)"
                                        ><Trash2 :size="16"
                                    /></AppButton>
                                </div>
                            </div>
                            <label class="checkbox"
                                ><input
                                    v-model="rule.always"
                                    type="checkbox"
                                />Executar sempre</label
                            >
                            <div v-if="!rule.always" class="condition">
                                <label
                                    >SE — Campo do payload<AppSelect
                                        v-model="rule.source"
                                        aria-label="SE — Campo do payload"
                                        ><option value="">
                                            Selecione um campo
                                        </option>
                                        <option
                                            v-if="
                                                rule.source &&
                                                !sources.some(
                                                    (s) =>
                                                        s.key === rule.source,
                                                )
                                            "
                                            :value="rule.source"
                                        >
                                            Origem ausente no exemplo
                                        </option>
                                        <option
                                            v-for="source in sources"
                                            :key="source.key"
                                            :value="source.key"
                                        >
                                            {{ source.label }}
                                        </option></AppSelect
                                    ><span
                                        v-if="
                                            errors[
                                                `tag_rules.${index}.condition.source_path`
                                            ]
                                        "
                                        class="form-error"
                                        >{{
                                            errors[
                                                `tag_rules.${index}.condition.source_path`
                                            ]
                                        }}</span
                                    ></label
                                ><span class="operator">é igual a</span
                                ><label
                                    >Tipo do valor<AppSelect
                                        v-model="rule.valueType"
                                        @change="
                                            rule.expected =
                                                rule.valueType === 'boolean'
                                                    ? 'true'
                                                    : ''
                                        "
                                        ><option value="string">Texto</option>
                                        <option value="number">Número</option>
                                        <option value="boolean">
                                            Booleano
                                        </option></AppSelect
                                    ></label
                                ><label
                                    >Valor esperado<AppSelect
                                        v-if="rule.valueType === 'boolean'"
                                        v-model="rule.expected"
                                        ><option value="true">true</option>
                                        <option value="false">
                                            false
                                        </option></AppSelect
                                    ><Input
                                        v-else
                                        v-model="rule.expected"
                                        :type="
                                            rule.valueType === 'number'
                                                ? 'number'
                                                : 'text'
                                        "
                                        step="any"
                                    /><span
                                        v-if="
                                            errors[
                                                `tag_rules.${index}.condition.value`
                                            ]
                                        "
                                        class="form-error"
                                        >{{
                                            errors[
                                                `tag_rules.${index}.condition.value`
                                            ]
                                        }}</span
                                    ></label
                                >
                            </div>
                            <h4>ENTÃO</h4>
                            <div class="tag-actions">
                                <label
                                    >Adicionar tags<AppSelect
                                        v-model="rule.add"
                                        aria-label="Adicionar tags"
                                        multiple
                                        size="3"
                                        ><option
                                            v-for="tag in tags"
                                            :key="tag.id"
                                            :value="tag.id"
                                        >
                                            {{ tag.name }}
                                        </option></AppSelect
                                    ><span
                                        v-if="errors[`tag_rules.${index}.add`]"
                                        class="form-error"
                                        >{{
                                            errors[`tag_rules.${index}.add`]
                                        }}</span
                                    ></label
                                ><label
                                    >Remover tags<AppSelect
                                        v-model="rule.remove"
                                        aria-label="Remover tags"
                                        multiple
                                        size="3"
                                        ><option
                                            v-for="tag in tags"
                                            :key="tag.id"
                                            :value="tag.id"
                                        >
                                            {{ tag.name }}
                                        </option></AppSelect
                                    ><span
                                        v-if="
                                            errors[`tag_rules.${index}.remove`]
                                        "
                                        class="form-error"
                                        >{{
                                            errors[`tag_rules.${index}.remove`]
                                        }}</span
                                    ></label
                                >
                            </div>
                            <small
                                >Selecione várias tags usando Ctrl ou Command;
                                no celular, use o seletor do sistema.</small
                            >
                        </article>
                        <AppButton
                            variant="secondary"
                            :disabled="!tags.length"
                            @click="addRule"
                            ><Plus :size="16" />Adicionar regra</AppButton
                        >
                    </section>
                </fieldset>
            </form>
            <details class="receipts">
                <summary>Últimos recebimentos e simulações</summary>
                <p v-if="pollError" role="status">{{ pollError }}</p>
                <p v-if="!snapshot.receipts.length">
                    Nenhum recebimento registrado.
                </p>
                <article v-for="receipt in snapshot.receipts" :key="receipt.id">
                    <strong>{{
                        receiptStates[receipt.status] || receipt.status
                    }}</strong
                    ><time>{{
                        new Date(receipt.created_at).toLocaleString('pt-BR')
                    }}</time
                    ><span>{{ receipt.duration_ms }} ms</span>
                    <p v-if="receipt.error_code">
                        {{
                            errorCodes[receipt.error_code] ||
                            'Falha de processamento'
                        }}
                    </p>
                    <p v-if="receipt.warnings?.length">
                        {{ receipt.warnings.length }} campo(s) opcional(is)
                        ignorado(s) por valor inválido.
                    </p>
                </article>
            </details>
            <AppFormDialog
                v-model:open="fieldOpen"
                title="Criar campo personalizado"
                :busy="fieldForm.processing"
                :dirty="fieldForm.isDirty"
                v-slot="{ close }"
                ><form @submit.prevent="saveField">
                    <div class="form-field">
                        <label for="new-field-name">Nome do campo</label
                        ><Input
                            id="new-field-name"
                            v-model="fieldForm.name"
                            required
                            maxlength="100"
                            :aria-invalid="Boolean(fieldForm.errors.name)"
                            aria-describedby="field-name-error"
                        />
                        <p
                            v-if="fieldForm.errors.name"
                            id="field-name-error"
                            class="form-error"
                        >
                            {{ fieldForm.errors.name }}
                        </p>
                        <small>Tipo: texto.</small>
                    </div>
                    <div class="form-actions">
                        <AppButton
                            variant="secondary"
                            :disabled="fieldForm.processing"
                            @click="close()"
                            >Cancelar</AppButton
                        ><AppButton
                            type="submit"
                            :disabled="fieldForm.processing"
                            >Criar campo</AppButton
                        >
                    </div>
                </form></AppFormDialog
            >
            <AppDialog :dialog="dialog" @resolve="finish" /></section
    ></SettingsLayout>
</template>
<style scoped>
.webhook-editor {
    width: 100%;
    max-width: 1120px;
    margin: 0 auto;
    padding: 0;
    min-width: 0;
}
.back-link {
    display: inline-block;
    font-size: 13px;
    color: var(--muted-foreground);
    margin-bottom: 16px;
}
.back-link:hover {
    text-decoration: underline;
}
header,
.rule-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
h1 {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 8px;
}
h2 {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
}
h3,
h4 {
    font-weight: 600;
    font-size: 14px;
}
h4 {
    margin: 20px 0 12px;
}
p,
small {
    color: var(--muted-foreground);
    font-size: 14px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
small,
.help {
    font-size: 12px;
}
.header-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: var(--form-field-gap);
    margin-bottom: 24px;
}
label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 13px;
    min-width: 0;
    align-content: start;
}
fieldset {
    min-width: 0;
}
.section {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-top: 20px;
    min-width: 0;
}
.section > p {
    margin-bottom: 16px;
}
.url-row,
.actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--form-button-gap);
}
.url-row > input {
    flex: 1;
    min-width: 200px;
}
.actions {
    margin: 12px 0;
}
.mapping {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    align-items: start;
    gap: 12px;
    margin: 16px 0;
}
.mapping > button {
    margin-top: 26px;
}
.rule {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin: 16px 0;
}
.rule-header {
    margin-bottom: 12px;
}
.rule-header .actions {
    margin: 0;
}
.checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
}
.checkbox input {
    width: 18px;
    height: 18px;
}
.condition {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 0.7fr) minmax(0, 1fr);
    gap: 12px;
    margin-top: 16px;
}
.operator {
    align-self: center;
    font-size: 13px;
}
.tag-actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
    margin-bottom: 12px;
}
.tag-actions :deep(select) {
    background-image: none;
}
.tag-actions :deep(svg) {
    display: none;
}
details {
    margin-top: 16px;
    min-width: 0;
}
summary {
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
}
ul {
    padding-left: 20px;
    margin-top: 12px;
}
li {
    font-size: 12px;
    overflow-wrap: anywhere;
}
code {
    white-space: pre-wrap;
}
.preview,
.notice {
    padding: 16px;
    border-radius: var(--radius);
    background: var(--secondary);
    margin-top: 16px;
    font-size: 14px;
}
.error-summary,
.warning {
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin: 16px 0;
}
.error-summary p,
.form-error {
    color: var(--danger);
}
.receipts {
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-top: 24px;
}
.receipts article {
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.receipts article p {
    width: 100%;
    font-size: 12px;
}
@media (max-width: 768px) {
    .webhook-editor {
        padding: 0;
    }
    .header-fields,
    .condition {
        grid-template-columns: minmax(0, 1fr);
    }
    .operator {
        margin: 0;
    }
}
@media (max-width: 640px) {
    .section {
        padding: 20px;
    }
    .mapping {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .mapping label {
        grid-column: 1;
    }
    .mapping > button {
        grid-column: 2;
        grid-row: 1;
    }
    .tag-actions {
        grid-template-columns: minmax(0, 1fr);
    }
    .url-row > input {
        min-width: 0;
        flex-basis: 100%;
    }
    .rule {
        padding: 16px;
    }
}
</style>

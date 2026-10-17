<script setup lang="ts">
import AutomationNavigation from '@/components/AutomationNavigation.vue';
import AppIconButton from '@/components/AppIconButton.vue';
import { ChevronUp, ChevronDown, Plus } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import AppSelect from '@/components/AppSelect.vue';
import AppButton from '@/components/AppButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
type FlowOption = { id: number; name: string; is_active: boolean };
type Keyword = {
    term: string;
    operator: 'exact' | 'prefix' | 'contains';
    flow_id: number;
    interrupt_active: boolean;
};
const props = defineProps<{
    workspace: { id: number; name: string };
    connection: { id: number; phone: string | null; status: string };
    flows: FlowOption[];
    settings: {
        welcome_flow_id: number | null;
        default_flow_id: number | null;
    } | null;
    keywords: Keyword[];
}>();
const welcome = ref<number | null>(props.settings?.welcome_flow_id || null);
const fallback = ref<number | null>(props.settings?.default_flow_id || null);
const rules = ref<Keyword[]>(
    props.keywords.map((rule) => ({
        term: rule.term,
        operator: rule.operator,
        flow_id: Number(rule.flow_id),
        interrupt_active: Boolean(rule.interrupt_active),
    })),
);
const busy = ref(false);
const notice = ref('');
function addRule() {
    rules.value.push({
        term: '',
        operator: 'exact',
        flow_id: props.flows[0]?.id || 0,
        interrupt_active: false,
    });
}
function move(index: number, direction: number) {
    const next = index + direction;
    if (next < 0 || next >= rules.value.length) return;
    [rules.value[index], rules.value[next]] = [
        rules.value[next],
        rules.value[index],
    ];
}
async function save() {
    busy.value = true;
    notice.value = '';
    try {
        const response = await fetch(
            `/workspaces/${props.workspace.id}/flow-triggers`,
            {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                body: JSON.stringify({
                    welcome_flow_id: welcome.value,
                    default_flow_id: fallback.value,
                    keywords: rules.value,
                }),
            },
        );
        const result = await response.json();
        if (!response.ok)
            throw new Error(
                result.message ||
                    Object.values(result.errors || {})
                        .flat()
                        .join(' ') ||
                    'Não foi possível salvar os gatilhos.',
            );
        notice.value = 'Configurações salvas.';
    } catch (error) {
        notice.value =
            error instanceof Error ? error.message : 'Falha ao salvar.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Gatilhos de fluxos" />
    <main class="triggers-page">
        <AutomationNavigation :workspace-id="workspace.id" active="keywords" />
        <header>
            <Link :href="`/workspaces/${workspace.id}/flows`">‹ Fluxos</Link
            ><small
                >{{ workspace.name }} · {{ connection.phone || 'WhatsApp' }} ·
                {{ connection.status }}</small
            >
            <h1>Gatilhos de entrada</h1>
            <p>Escolha quais fluxos ativos podem iniciar por esta conexão.</p>
        </header>
        <section>
            <h2>Primeiro contato</h2>
            <p>
                Uma palavra-chave aplicável tem prioridade. Sem correspondência,
                inicia o fluxo de boas-vindas; se não houver boas-vindas, usa a
                resposta padrão.
            </p>
            <label
                >Boas-vindas<AppSelect v-model="welcome">
                    <option :value="null">Desativado</option>
                    <option
                        v-for="flow in flows"
                        :key="flow.id"
                        :value="flow.id"
                    >
                        {{ flow.name }}{{ flow.is_active ? '' : ' (inativo)' }}
                    </option>
                </AppSelect></label
            ><label
                >Resposta padrão<AppSelect v-model="fallback">
                    <option :value="null">Desativado</option>
                    <option
                        v-for="flow in flows"
                        :key="flow.id"
                        :value="flow.id"
                    >
                        {{ flow.name }}{{ flow.is_active ? '' : ' (inativo)' }}
                    </option>
                </AppSelect></label
            >
        </section>
        <section>
            <h2>Palavras-chave</h2>
            <p>
                As regras são verificadas de cima para baixo; a primeira
                aplicável inicia o fluxo.
            </p>
            <article v-for="(rule, index) in rules" :key="index" class="rule">
                <div class="order">
                    <AppIconButton
                        :disabled="index === 0"
                        @click="move(index, -1)"
                        label="Mover para cima"
                        ><ChevronUp :size="16" /></AppIconButton
                    ><AppIconButton
                        :disabled="index === rules.length - 1"
                        @click="move(index, 1)"
                        label="Mover para baixo"
                        ><ChevronDown :size="16"
                    /></AppIconButton>
                </div>
                <label
                    >Expressão<Input
                        v-model="rule.term"
                        placeholder="Ex.: preço" /></label
                ><label
                    >Correspondência<AppSelect v-model="rule.operator">
                        <option value="exact">É igual a</option>
                        <option value="prefix">Começa com</option>
                        <option value="contains">Contém</option>
                    </AppSelect></label
                ><label
                    >Fluxo<AppSelect v-model.number="rule.flow_id">
                        <option
                            v-for="flow in flows"
                            :key="flow.id"
                            :value="flow.id"
                        >
                            {{ flow.name
                            }}{{ flow.is_active ? '' : ' (inativo)' }}
                        </option>
                    </AppSelect></label
                ><label class="interrupt"
                    ><input v-model="rule.interrupt_active" type="checkbox" />
                    Interromper fluxo ativo</label
                ><AppButton
                    class="remove"
                    variant="danger"
                    @click="rules.splice(index, 1)"
                >
                    Remover
                </AppButton>
            </article>
            <AppButton @click="addRule" variant="secondary">
                <Plus :size="16" />Adicionar palavra-chave
            </AppButton>
        </section>
        <footer>
            <p role="status">{{ notice }}</p>
            <AppButton :disabled="busy" @click="save" variant="primary">
                {{ busy ? 'Salvando…' : 'Salvar gatilhos' }}
            </AppButton>
        </footer>
    </main>
</template>

<style scoped>
* {
    box-sizing: border-box;
}
.triggers-page {
    max-width: 1200px;
    width: 100%;
    min-width: 0;
    margin: 0 auto;
    padding: 24px;
    color: var(--foreground);
    font-family: var(--font-sans), sans-serif;
}
.triggers-page header {
    margin-bottom: 26px;
}
.triggers-page header a {
    color: var(--primary);
    text-decoration: none;
}
.triggers-page header small {
    display: block;
    margin-top: 20px;
    color: var(--muted-foreground);
}
.triggers-page h1 {
    margin: 8px 0;
    font-size: 28px;
}
.triggers-page h2 {
    margin: 0 0 8px;
    font-size: 16px;
}
.triggers-page p {
    color: var(--muted-foreground);
    font-size: 13px;
    line-height: 1.6;
}
.triggers-page section {
    margin: 18px 0;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card);
}
.triggers-page label {
    display: grid;
    gap: var(--form-label-gap);
    margin: var(--form-field-gap) 0;
    color: var(--muted-foreground);
    font-size: 12px;
    font-weight: 650;
}
.triggers-page input:not([type='checkbox']),
.triggers-page select {
    min-width: 0;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--card);
    color: var(--foreground);
    font: inherit;
}
.rule {
    display: grid;
    grid-template-columns:
        48px minmax(130px, 1.2fr) minmax(130px, 1fr) minmax(150px, 1fr)
        minmax(150px, 1fr) auto;
    align-items: end;
    gap: 10px;
    padding: 10px 0;
    border-top: 1px solid var(--border);
}
.order {
    display: grid;
    gap: var(--form-button-gap);
}
.rule label {
    min-width: 0;
    margin: 0;
}
.rule .interrupt {
    min-height: var(--control-height);
}
.order button,
.remove,
.interrupt {
    display: flex !important;
    align-items: center;
    gap: 8px;
}

footer {
    display: flex;
    flex-wrap: wrap;
    margin-top: var(--form-actions-space);
    justify-content: flex-end;
    align-items: center;
    gap: var(--form-button-gap);
}
footer p {
    margin-right: auto;
}
@container (max-width: 860px) {
    .rule {
        grid-template-columns: 35px minmax(0, 1fr) minmax(0, 1fr);
    }
    .rule .interrupt,
    .rule .remove {
        grid-column: 2/4;
    }
    .rule .remove {
        justify-self: end;
    }
}
@container (max-width: 640px) {
    .triggers-page {
        padding: 16px;
    }
}
</style>

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import AutomationNavigation from '@/components/AutomationNavigation.vue';
import { Input } from '@/components/ui/input';
import { sequenceRequest, SequenceRequestError } from '@/lib/sequences';
const props = defineProps<{
    workspace: { id: number; name: string };
    sequences: {
        id: number;
        name: string;
        is_active: boolean;
        published_version_id: number | null;
        steps_count: number;
        active_enrollments_count: number;
    }[];
    filters: { search: string; status: string };
}>();
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const open = ref(false);
const name = ref('');
const busy = ref(false);
const error = ref('');
watch(open, (value) => {
    if (!value) {
        name.value = '';
        error.value = '';
    }
});
function filter() {
    router.get(
        `/workspaces/${props.workspace.id}/sequences`,
        { search: search.value, status: status.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
async function create() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await sequenceRequest<{ data: { id: number } }>(
            `/workspaces/${props.workspace.id}/sequences`,
            'POST',
            { name: name.value },
        );
        open.value = false;
        router.visit(
            `/workspaces/${props.workspace.id}/sequences/${result.data.id}`,
        );
    } catch (e) {
        error.value =
            e instanceof SequenceRequestError
                ? e.fields.name || e.message
                : 'Falha ao criar sequência. Tente novamente.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <Head title="Sequências" />
    <main class="sequences-page">
        <AutomationNavigation :workspace-id="workspace.id" active="sequences" />
        <header>
            <div>
                <h1>Sequências</h1>
                <p>Programe fluxos em etapas para acompanhar seus contatos.</p>
            </div>
            <AppButton @click="open = true"
                ><Plus :size="18" />Nova sequência</AppButton
            >
        </header>
        <form class="filters" @submit.prevent="filter">
            <label
                >Buscar sequência<Input
                    v-model="search"
                    type="search"
                    placeholder="Nome da sequência" /></label
            ><label
                >Situação<AppSelect v-model="status" @change="filter"
                    ><option value="all">Todas</option>
                    <option value="active">Ativas</option>
                    <option value="draft">Rascunhos</option>
                    <option value="inactive">Inativas</option></AppSelect
                ></label
            ><AppButton variant="secondary" type="submit">Buscar</AppButton>
        </form>
        <div v-if="sequences.length" class="sequence-list">
            <div class="list-head" aria-hidden="true">
                <span>Nome</span><span>Etapas</span><span>Contatos ativos</span
                ><span>Situação</span>
            </div>
            <Link
                v-for="sequence in sequences"
                :key="sequence.id"
                :href="`/workspaces/${workspace.id}/sequences/${sequence.id}`"
                class="sequence-row"
                ><strong>{{ sequence.name }}</strong
                ><span
                    ><span class="mobile-label">Etapas: </span
                    >{{ sequence.steps_count }}</span
                ><span
                    ><span class="mobile-label">Contatos ativos: </span
                    >{{ sequence.active_enrollments_count }}</span
                ><span class="status">{{
                    sequence.is_active
                        ? 'Ativa'
                        : sequence.published_version_id
                          ? 'Inativa'
                          : 'Rascunho'
                }}</span></Link
            >
        </div>
        <section v-else class="empty">
            <h2>Nenhuma sequência encontrada</h2>
            <p>
                {{
                    filters.search || filters.status !== 'all'
                        ? 'Altere a busca ou os filtros.'
                        : 'Crie uma sequência e adicione o primeiro fluxo.'
                }}
            </p>
            <AppButton variant="secondary" @click="open = true"
                >Nova sequência</AppButton
            >
        </section>
        <AppFormDialog
            v-model:open="open"
            title="Nova sequência"
            description="Dê um nome à programação de fluxos."
            :busy="busy"
            :dirty="Boolean(name)"
            v-slot="{ close }"
            ><form @submit.prevent="create">
                <div class="form-field">
                    <label for="sequence-name">Nome da sequência</label
                    ><Input
                        id="sequence-name"
                        v-model="name"
                        maxlength="120"
                        required
                        :aria-invalid="Boolean(error)"
                        aria-describedby="sequence-name-error"
                    />
                    <p
                        v-if="error"
                        id="sequence-name-error"
                        class="form-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </div>
                <div class="form-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="busy"
                        @click="close()"
                        >Cancelar</AppButton
                    ><AppButton type="submit" :disabled="busy">{{
                        busy ? 'Criando…' : 'Criar sequência'
                    }}</AppButton>
                </div>
            </form></AppFormDialog
        >
    </main>
</template>
<style scoped>
.sequences-page {
    padding: 24px;
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    min-width: 0;
}
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
h1 {
    font-size: 24px;
    font-weight: 600;
}
p {
    color: var(--muted-foreground);
    font-size: 14px;
}
.filters {
    display: flex;
    align-items: end;
    gap: 16px;
    margin-bottom: 24px;
}
label {
    display: grid;
    gap: var(--form-label-gap);
    font-size: 13px;
    min-width: 0;
}
.filters label:first-child {
    flex: 1;
}
.filters label:nth-child(2) {
    width: 180px;
}
.sequence-list {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--card);
}
.list-head,
.sequence-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 80px 130px 90px;
    gap: 16px;
    padding: 16px;
    align-items: center;
    font-size: 14px;
}
.list-head {
    background: var(--muted);
    color: var(--muted-foreground);
    font-size: 12px;
}
.sequence-row + .sequence-row {
    border-top: 1px solid var(--border);
}
.sequence-row:hover {
    background: var(--muted);
}
strong {
    overflow-wrap: anywhere;
}
.mobile-label {
    display: none;
}
.empty {
    padding: 40px 20px;
    text-align: center;
    display: grid;
    justify-items: center;
    gap: 12px;
}
@media (max-width: 640px) {
    .sequences-page {
        padding: 16px;
    }
    .filters {
        flex-wrap: wrap;
    }
    .filters label:first-child {
        flex-basis: 100%;
    }
    .list-head {
        display: none;
    }
    .sequence-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .mobile-label {
        display: inline;
    }
}
</style>

<script setup lang="ts">
import { router, Link, useForm, Head } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import LeadTagPicker from '@/components/LeadTagPicker.vue';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Plus,
    Search,
    ListFilter,
    ArrowUpRight,
    UsersRound,
    ChevronLeft,
    ChevronRight,
} from '@lucide/vue';
import '@/../css/leads.css';
const filterOpen = ref(false);
const props = defineProps<{
    workspace: any;
    leads: any;
    filters: any;
    tags: any[];
    canEdit: boolean;
}>();
const filters = reactive({
    search: '',
    tag_mode: 'or',
    from: '',
    to: '',
    order: 'desc',
    ...props.filters,
    tag_ids: (props.filters.tag_ids || []).map(Number),
    untagged: [true, 1, '1'].includes(props.filters.untagged),
});
const form = useForm({
    phone: '',
    display_name: '',
    email: '',
    cpf: '',
    tag_ids: [] as number[],
});
const creating = ref(false);
const activeFilterCount = computed(
    () =>
        Number(Boolean(filters.from || filters.to)) +
        Number(Boolean(filters.tag_ids.length || filters.untagged)),
);
watch(creating, (open) => {
    if (!open) {
        form.reset();
        form.clearErrors();
    }
});
function create() {
    if (form.processing) return;
    form.post(root, { onSuccess: () => (creating.value = false) });
}
function clearFilters() {
    Object.assign(filters, {
        search: '',
        tag_ids: [],
        tag_mode: 'or',
        untagged: false,
        from: '',
        to: '',
        order: 'desc',
    });
    search();
}
const root = `/workspaces/${props.workspace.id}/leads`;
function search() {
    router.get(
        root,
        { ...filters, untagged: filters.untagged ? 1 : 0 },
        { preserveState: true },
    );
}
</script>
<template>
    <Head title="Leads" />
    <main class="leads-page">
        <header class="leads-header">
            <div>
                <h1>Leads</h1>
                <p>Gerencie os contatos do seu workspace.</p>
            </div>
            <AppButton v-if="canEdit" @click="creating = true"
                ><Plus :size="18" />Novo lead</AppButton
            >
        </header>
        <form class="leads-filter-form" @submit.prevent="search">
            <div class="leads-toolbar">
                <div class="lead-search">
                    <Search :size="16" aria-hidden="true" /><Input
                        v-model="filters.search"
                        type="search"
                        placeholder="Buscar por nome ou WhatsApp"
                        aria-label="Buscar leads"
                    />
                </div>
                <div class="leads-order">
                    <AppSelect
                        v-model="filters.order"
                        aria-label="Ordenação dos leads"
                        ><option value="desc">Mais recentes</option>
                        <option value="asc">Mais antigos</option></AppSelect
                    >
                </div>
                <AppButton
                    variant="secondary"
                    :aria-expanded="filterOpen"
                    aria-controls="lead-filters"
                    @click="filterOpen = !filterOpen"
                    ><ListFilter :size="16" />Filtros
                    <span v-if="activeFilterCount" class="leads-filter-count">{{
                        activeFilterCount
                    }}</span></AppButton
                >
                <AppButton type="submit" variant="secondary">Buscar</AppButton>
            </div>
            <section
                v-if="filterOpen"
                id="lead-filters"
                class="leads-card leads-filter-panel"
                aria-label="Filtros de leads"
            >
                <div class="form-field">
                    <label>Etiquetas</label
                    ><LeadTagPicker v-model="filters.tag_ids" :tags="tags" />
                </div>
                <div class="form-fields">
                    <div class="form-field">
                        <label for="tag-mode">Combinar etiquetas</label
                        ><AppSelect id="tag-mode" v-model="filters.tag_mode"
                            ><option value="or">Qualquer etiqueta (OU)</option>
                            <option value="and">
                                Todas as etiquetas (E)
                            </option></AppSelect
                        >
                    </div>
                    <label class="form-checkbox"
                        ><Checkbox
                            :model-value="Boolean(filters.untagged)"
                            @update:model-value="
                                filters.untagged = $event === true
                            "
                        />Incluir leads sem etiqueta</label
                    >
                    <div class="leads-fields-grid">
                        <div class="form-field">
                            <label for="lead-from"
                                >Cadastrados a partir de</label
                            ><Input
                                id="lead-from"
                                v-model="filters.from"
                                type="date"
                            />
                        </div>
                        <div class="form-field">
                            <label for="lead-to">Cadastrados até</label
                            ><Input
                                id="lead-to"
                                v-model="filters.to"
                                type="date"
                                :min="filters.from || undefined"
                            />
                        </div>
                    </div>
                </div>
                <div class="leads-filter-actions">
                    <AppButton variant="ghost" @click="clearFilters"
                        >Limpar filtros</AppButton
                    ><AppButton type="submit">Aplicar filtros</AppButton>
                </div>
            </section>
        </form>
        <div class="leads-results">
            <p>
                {{ leads.total }}
                {{
                    leads.total === 1 ? 'lead encontrado' : 'leads encontrados'
                }}
            </p>
        </div>
        <section class="leads-list" aria-label="Lista de leads">
            <div class="leads-list-head" aria-hidden="true">
                <span>Lead</span><span>Etiquetas</span><span>Cadastrado em</span
                ><span>Ação</span>
            </div>
            <Link
                v-for="lead in leads.data"
                :key="lead.id"
                :href="`${root}/${lead.id}`"
                class="leads-list-row"
            >
                <div class="leads-identity">
                    <span class="lead-avatar" aria-hidden="true">{{
                        (lead.display_name || '?').charAt(0).toUpperCase()
                    }}</span>
                    <div>
                        <strong>{{ lead.display_name || 'Sem nome' }}</strong
                        ><span class="lead-phone">{{
                            lead.phone || 'WhatsApp não informado'
                        }}</span>
                    </div>
                </div>
                <div class="lead-tags">
                    <span
                        v-for="tag in lead.tags"
                        :key="tag.id"
                        class="lead-tag"
                        ><span
                            class="lead-tag-dot"
                            :style="{
                                backgroundColor:
                                    tag.color || 'var(--muted-foreground)',
                            }"
                        />{{ tag.name }}</span
                    ><span v-if="!lead.tags.length" class="leads-muted"
                        >Sem etiqueta</span
                    >
                </div>
                <span class="leads-date"
                    ><span class="leads-mobile-label">Cadastrado em </span
                    >{{
                        new Date(lead.created_at).toLocaleDateString('pt-BR')
                    }}</span
                >
                <span class="leads-row-action"
                    >Abrir ficha<ArrowUpRight :size="16" aria-hidden="true"
                /></span>
            </Link>
            <div v-if="!leads.data.length" class="leads-empty">
                <UsersRound :size="32" :stroke-width="1.5" />
                <h2>Nenhum lead encontrado</h2>
                <p>
                    Altere a busca ou os filtros, ou cadastre um novo contato.
                </p>
                <AppButton
                    v-if="canEdit"
                    variant="secondary"
                    @click="creating = true"
                    ><Plus :size="16" />Novo lead</AppButton
                >
            </div>
        </section>
        <nav
            v-if="leads.last_page > 1"
            class="leads-pagination"
            aria-label="Paginação"
        >
            <span
                >Página {{ leads.current_page }} de {{ leads.last_page }}</span
            >
            <div>
                <template v-for="(link, i) in leads.links" :key="i"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        class="leads-page-link"
                        :class="{ active: link.active }"
                        :aria-label="
                            i === 0
                                ? 'Página anterior'
                                : i === leads.links.length - 1
                                  ? 'Próxima página'
                                  : `Página ${link.label}`
                        "
                        :aria-current="link.active ? 'page' : undefined"
                        ><ChevronLeft v-if="i === 0" :size="16" /><ChevronRight
                            v-else-if="i === leads.links.length - 1"
                            :size="16"
                        /><span v-else>{{ link.label }}</span></Link
                    ><span
                        v-else-if="link.label === '...'"
                        class="leads-page-link"
                        >…</span
                    ></template
                >
            </div>
        </nav>
        <AppFormDialog
            v-model:open="creating"
            title="Novo lead"
            description="O cadastro exige confirmar que o número possui WhatsApp. Mantenha o WhatsApp deste workspace conectado."
            :busy="form.processing"
            :dirty="form.isDirty"
            v-slot="{ close }"
            ><form @submit.prevent="create">
                <div class="form-fields">
                    <div
                        v-for="key in [
                            'phone',
                            'display_name',
                            'email',
                            'cpf',
                        ] as const"
                        :key="key"
                        class="form-field"
                    >
                        <label :for="`new-lead-${key}`"
                            >{{
                                {
                                    phone: 'WhatsApp com DDI',
                                    display_name: 'Nome',
                                    email: 'E-mail',
                                    cpf: 'CPF',
                                }[key]
                            }}<span
                                v-if="key !== 'phone'"
                                class="leads-optional"
                            >
                                (opcional)</span
                            ></label
                        ><Input
                            :id="`new-lead-${key}`"
                            v-model="form[key]"
                            :disabled="form.processing"
                            :type="
                                key === 'email'
                                    ? 'email'
                                    : key === 'phone'
                                      ? 'tel'
                                      : 'text'
                            "
                            :required="key === 'phone'"
                            :placeholder="
                                key === 'phone'
                                    ? '+55 11 99999-9999'
                                    : undefined
                            "
                            :aria-invalid="Boolean(form.errors[key])"
                            :aria-describedby="
                                form.errors[key]
                                    ? `new-${key}-error`
                                    : undefined
                            "
                        />
                        <p
                            v-if="form.errors[key]"
                            :id="`new-${key}-error`"
                            class="form-error"
                            role="alert"
                        >
                            {{ form.errors[key] }}
                        </p>
                    </div>
                    <div class="form-field">
                        <label
                            >Etiquetas
                            <span class="leads-optional"
                                >(opcional)</span
                            ></label
                        ><LeadTagPicker
                            v-model="form.tag_ids"
                            :tags="tags"
                            :disabled="form.processing"
                        />
                    </div>
                </div>
                <div class="form-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="form.processing"
                        @click="close()"
                        >Cancelar</AppButton
                    ><AppButton type="submit" :loading="form.processing"
                        >Cadastrar lead</AppButton
                    >
                </div>
            </form></AppFormDialog
        >
    </main>
</template>

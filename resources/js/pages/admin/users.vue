<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import UserForm from '@/components/admin/UserForm.vue';
import { Input } from '@/components/ui/input';

type User = {
    id: number;
    name: string;
    email: string;
    workspaces: { id: number; name: string; role: string }[];
};
const props = defineProps<{
    users: {
        data: User[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { filter: string; search: string; page?: string };
    editingUser?: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        is_system_admin: boolean;
    } | null;
    success?: string;
}>();
const search = ref(props.filters.search);
const filter = ref(props.filters.filter);
const creating = ref(false);
const workspaceUser = ref<User | null>(null);
const workspaceForm = useForm({ name: '' });
watch(
    () => props.editingUser,
    () => {
        creating.value = false;
    },
);
function closeUser() {
    creating.value = false;
    if (props.editingUser)
        router.get(
            `/admin/usuarios?${new URLSearchParams(props.filters as Record<string, string>)}`,
            {},
            { preserveScroll: true, preserveState: true },
        );
}
function closeWorkspace() {
    workspaceUser.value = null;
    workspaceForm.reset();
    workspaceForm.clearErrors();
}
function createWorkspace() {
    if (!workspaceUser.value || workspaceForm.processing) return;
    workspaceForm.post(
        `/admin/usuarios/${workspaceUser.value.id}/workspaces?${new URLSearchParams(props.filters as Record<string, string>)}`,
        {
            preserveScroll: true,
            onSuccess: closeWorkspace,
        },
    );
}
function applyFilters() {
    router.get(
        '/admin/usuarios',
        { filter: filter.value, search: search.value },
        { preserveState: true, replace: true },
    );
}
const roleName = (role: string) =>
    ({
        owner: 'Proprietário',
        admin: 'Administrador',
        manager: 'Gestor',
        agent: 'Atendente',
    })[role] ?? role;
const editUrl = (id: number) =>
    `/admin/usuarios/${id}/editar?${new URLSearchParams(props.filters as Record<string, string>)}`;
</script>
<template>
    <Head title="Administração de usuários" />
    <main class="admin-page">
        <header class="page-header">
            <div>
                <a href="/admin/exclusoes">Solicitações de exclusão</a>
                <h1>Usuários</h1>
                <p>Consulte e gerencie as contas da plataforma.</p>
            </div>
            <AppButton @click="creating = true"
                ><Plus :size="16" />Novo usuário</AppButton
            >
        </header>
        <p v-if="success" class="notice" role="status">{{ success }}</p>
        <UserForm
            v-if="creating || editingUser"
            :key="editingUser?.id ?? 'new'"
            :open="true"
            :user="editingUser ?? undefined"
            :filters="filters"
            @cancel="closeUser"
            @saved="creating = false"
        />
        <AppFormDialog
            :open="!!workspaceUser"
            title="Novo workspace"
            :description="`Crie um workspace independente para ${workspaceUser?.name ?? ''} (${workspaceUser?.email ?? ''}). O usuário será o proprietário.`"
            :busy="workspaceForm.processing"
            :dirty="workspaceForm.isDirty"
            @update:open="closeWorkspace"
            v-slot="{ close }"
        >
            <form class="workspace-form" @submit.prevent="createWorkspace">
                <label for="workspace-name">Nome do workspace</label>
                <Input
                    id="workspace-name"
                    v-model="workspaceForm.name"
                    required
                    maxlength="255"
                    :disabled="workspaceForm.processing"
                    :aria-invalid="!!workspaceForm.errors.name"
                    aria-describedby="workspace-name-error"
                />
                <small
                    v-if="workspaceForm.errors.name"
                    id="workspace-name-error"
                    role="alert"
                    >{{ workspaceForm.errors.name }}</small
                >
                <div class="row-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="workspaceForm.processing"
                        @click="close()"
                        >Cancelar</AppButton
                    >
                    <AppButton type="submit" :loading="workspaceForm.processing"
                        >Criar workspace</AppButton
                    >
                </div>
            </form>
        </AppFormDialog>
        <section class="panel" aria-label="Lista de usuários">
            <form class="filters" @submit.prevent="applyFilters">
                <div class="filter-field">
                    <label for="users-search">Buscar por nome ou e-mail</label
                    ><Input
                        id="users-search"
                        v-model="search"
                        type="search"
                        placeholder="Buscar usuário"
                    />
                </div>
                <div class="filter-field">
                    <label for="users-filter">Exibir</label
                    ><select
                        id="users-filter"
                        v-model="filter"
                        @change="applyFilters"
                    >
                        <option value="owners">Proprietários</option>
                        <option value="all">Todos os usuários</option>
                    </select>
                </div>
                <AppButton type="submit" variant="secondary">Buscar</AppButton>
            </form>
            <div v-if="users.data.length" class="user-list">
                <article
                    v-for="user in users.data"
                    :key="user.id"
                    class="user-row"
                >
                    <div class="user-info">
                        <strong>{{ user.name }}</strong
                        ><span>{{ user.email }}</span>
                    </div>
                    <div class="memberships">
                        <span
                            v-for="workspace in user.workspaces"
                            :key="workspace.id"
                            >{{ workspace.name }} ·
                            {{ roleName(workspace.role) }}</span
                        ><span v-if="!user.workspaces.length"
                            >Sem workspace</span
                        >
                    </div>
                    <div class="row-actions">
                        <Link
                            preserve-scroll
                            preserve-state
                            class="edit-link"
                            :href="editUrl(user.id)"
                            :aria-label="`Editar ${user.name}`"
                            >Editar</Link
                        >
                        <AppButton
                            variant="secondary"
                            :aria-label="`Novo workspace para ${user.name}`"
                            @click="workspaceUser = user"
                            >Novo workspace</AppButton
                        >
                    </div>
                </article>
            </div>
            <p v-else class="empty">Nenhum usuário encontrado.</p>
            <nav
                v-if="users.last_page > 1"
                class="pagination"
                aria-label="Paginação"
            >
                <Link v-if="users.prev_page_url" :href="users.prev_page_url"
                    >Anterior</Link
                ><span v-else>Anterior</span>
                <span
                    >Página {{ users.current_page }} de
                    {{ users.last_page }}</span
                >
                <Link v-if="users.next_page_url" :href="users.next_page_url"
                    >Próxima</Link
                ><span v-else>Próxima</span>
            </nav>
        </section>
    </main>
</template>
<style scoped>
.admin-page {
    width: 100%;
    min-width: 0;
    margin: 0 auto;
    padding: 24px 40px 48px;
    display: grid;
    gap: 20px;
}
.row-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
}
.workspace-form {
    display: grid;
    gap: 12px;
}
.workspace-form small {
    color: var(--destructive);
}
.page-header {
    display: flex;
    gap: 16px;
    justify-content: space-between;
    align-items: center;
}
h1 {
    font-size: 24px;
    font-weight: 700;
}
h2 {
    font-size: 18px;
    font-weight: 600;
}
.page-header p {
    color: var(--muted-foreground);
    font-size: 14px;
}
.panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}
.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
    padding-bottom: 18px;
}
.filter-field {
    display: grid;
    gap: 5px;
    min-width: min(180px, 100%);
    flex: 1;
}
.filter-field label {
    font-size: 13px;
    font-weight: 500;
}
select {
    height: var(--control-height);
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--background);
    color: var(--foreground);
}
.user-row {
    border-top: 1px solid var(--border);
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(180px, 1fr) auto;
    align-items: start;
    gap: 16px;
    padding: 16px 0;
    font-size: 14px;
}
.user-info,
.memberships {
    min-width: 0;
    display: grid;
    gap: 4px;
    overflow-wrap: anywhere;
}
.user-info span,
.memberships {
    color: var(--muted-foreground);
}
.edit-link,
.pagination a {
    color: var(--brand-strong);
    font-weight: 600;
}
.empty {
    color: var(--muted-foreground);
    padding: 20px 0;
}
.pagination {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid var(--border);
    padding-top: 16px;
    font-size: 14px;
}
.notice {
    color: var(--brand-strong);
    font-size: 14px;
}
@media (max-width: 640px) {
    .admin-page {
        padding: 16px 16px 32px;
    }
    .page-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .user-row {
        grid-template-columns: minmax(0, 1fr);
    }
    .memberships {
        grid-column: 1 / -1;
        grid-row: 2;
    }
}
</style>

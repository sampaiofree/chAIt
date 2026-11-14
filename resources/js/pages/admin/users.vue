<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
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
    filters: { filter: string; search: string };
    success?: string;
}>();
const search = ref(props.filters.search);
const filter = ref(props.filters.filter);
const creating = ref(false);
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
    `/admin/usuarios/${id}/editar?${new URLSearchParams(props.filters)}`;
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
            <AppButton v-if="!creating" @click="creating = true"
                ><Plus :size="16" />Novo usuário</AppButton
            >
        </header>
        <p v-if="success" class="notice" role="status">{{ success }}</p>
        <section
            v-if="creating"
            class="panel form-panel"
            aria-labelledby="create-title"
        >
            <h2 id="create-title">Novo usuário</h2>
            <p>Um workspace será criado para o novo usuário.</p>
            <UserForm
                :filters="filters"
                @cancel="creating = false"
                @saved="creating = false"
            />
        </section>
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
                    <Link
                        class="edit-link"
                        :href="editUrl(user.id)"
                        :aria-label="`Editar ${user.name}`"
                        >Editar</Link
                    >
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
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px;
    display: grid;
    gap: 20px;
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
.page-header p,
.form-panel > p {
    color: var(--muted-foreground);
    font-size: 14px;
}
.panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}
.form-panel {
    max-width: 620px;
    display: grid;
    gap: 12px;
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
    min-width: 180px;
    flex: 1;
}
.filter-field label {
    font-size: 13px;
    font-weight: 500;
}
select {
    height: 36px;
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
        padding: 20px 16px;
    }
    .page-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .user-row {
        grid-template-columns: 1fr auto;
    }
    .memberships {
        grid-column: 1 / -1;
        grid-row: 2;
    }
}
</style>

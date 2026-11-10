<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        is_system_admin: boolean;
    };
    filters: { filter: string; search: string };
}>();
function back() {
    router.get(`/admin/usuarios?${new URLSearchParams(props.filters)}`);
}
</script>
<template>
    <Head title="Editar usuário" />
    <main class="edit-page">
        <h1>Editar usuário</h1>
        <section class="edit-card">
            <UserForm :user="user" :filters="filters" @cancel="back" />
        </section>
    </main>
</template>
<style scoped>
.edit-page {
    max-width: 680px;
    margin: 0 auto;
    padding: 32px;
    display: grid;
    gap: 20px;
}
h1 {
    font-size: 24px;
    font-weight: 700;
}
.edit-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}
@media (max-width: 640px) {
    .edit-page {
        padding: 20px 16px;
    }
}
</style>

<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Plus, Users, ShieldCheck } from '@lucide/vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppButton from '@/components/AppButton.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import PhoneInput from '@/components/PhoneInput.vue';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    can_view_unassigned: boolean;
    can_manage_flows: boolean;
    can_use_flow_assistant: boolean;
};
type Permission =
    | 'can_view_unassigned'
    | 'can_manage_flows'
    | 'can_use_flow_assistant';
const props = defineProps<{
    workspace: { id: number; name: string };
    members: Member[];
}>();
const page = usePage<any>();
const isAdmin = computed(() => page.props.isAdmin === true);
const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    can_view_unassigned: false,
    can_manage_flows: false,
    can_use_flow_assistant: false,
});
const open = ref(false);
const success = ref('');
const permissionError = ref('');
const savingMembers = ref(new Set<number>());
function setOpen(value: boolean) {
    open.value = value;
    if (!value) {
        form.reset();
        form.clearErrors();
    }
}
function addMember() {
    if (form.processing) return;
    success.value = '';
    form.post(`/workspaces/${props.workspace.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            setOpen(false);
            success.value = 'Usuário adicionado à equipe.';
        },
    });
}
function update(
    member: Member,
    permission: Permission,
    value: boolean | 'indeterminate',
) {
    if (savingMembers.value.has(member.id)) return;
    permissionError.value = '';
    savingMembers.value.add(member.id);
    router.patch(
        `/workspaces/${props.workspace.id}/members/${member.id}/permissions`,
        { [permission]: value === true },
        {
            preserveScroll: true,
            onError: () => {
                permissionError.value =
                    'Não foi possível atualizar a permissão. Tente novamente.';
            },
            onFinish: () => {
                savingMembers.value.delete(member.id);
            },
        },
    );
}
const roleName = (role: string) =>
    ({
        owner: 'Proprietário',
        admin: 'Administrador',
        manager: 'Gestor',
        agent: 'Atendente',
    })[role] ?? role;
</script>
<template>
    <Head title="Equipe" />
    <SettingsLayout active="team">
        <section>
            <header class="team-header">
                <div>
                    <h1>Equipe</h1>
                    <p>
                        Gerencie os usuários e as permissões de
                        {{ workspace.name }}.
                    </p>
                </div>
                <AppButton v-if="isAdmin" @click="open = true"
                    ><Plus :size="16" />Adicionar usuário</AppButton
                >
            </header>
            <p v-if="success" class="notice success" role="status">
                {{ success }}
            </p>
            <p v-if="permissionError" class="notice error" role="alert">
                {{ permissionError }}
            </p>
            <div class="members-card">
                <div class="members-caption">
                    <Users :size="18" />
                    <h2>Usuários do workspace</h2>
                    <span>{{ members.length }}</span>
                </div>
                <div v-if="!members.length" class="empty">
                    Nenhum usuário cadastrado.
                </div>
                <article
                    v-for="member in members"
                    :key="member.id"
                    class="member-row"
                >
                    <div class="member-identity">
                        <span class="member-avatar">{{
                            member.name
                                .split(/\s+/)
                                .slice(0, 2)
                                .map((part) => part[0])
                                .join('')
                                .toUpperCase()
                        }}</span>
                        <div>
                            <strong>{{ member.name }}</strong
                            ><small>{{ member.email }}</small
                            ><span class="role-badge">{{
                                roleName(member.role)
                            }}</span>
                        </div>
                    </div>
                    <div
                        v-if="
                            member.role === 'agent' || member.role === 'manager'
                        "
                        class="member-permissions"
                        :aria-busy="savingMembers.has(member.id)"
                    >
                        <label
                            class="form-checkbox"
                            :for="`unassigned-${member.id}`"
                            ><Checkbox
                                :id="`unassigned-${member.id}`"
                                :model-value="member.can_view_unassigned"
                                :disabled="
                                    !isAdmin || savingMembers.has(member.id)
                                "
                                @update:model-value="
                                    update(
                                        member,
                                        'can_view_unassigned',
                                        $event,
                                    )
                                "
                            />Ver conversas não atribuídas</label
                        >
                        <label class="form-checkbox" :for="`flows-${member.id}`"
                            ><Checkbox
                                :id="`flows-${member.id}`"
                                :model-value="member.can_manage_flows"
                                :disabled="
                                    !isAdmin || savingMembers.has(member.id)
                                "
                                @update:model-value="
                                    update(member, 'can_manage_flows', $event)
                                "
                            />Criar e editar fluxos</label
                        >
                        <label
                            class="form-checkbox"
                            :for="`assistant-${member.id}`"
                            ><Checkbox
                                :id="`assistant-${member.id}`"
                                :model-value="member.can_use_flow_assistant"
                                :disabled="
                                    !isAdmin || savingMembers.has(member.id)
                                "
                                @update:model-value="
                                    update(
                                        member,
                                        'can_use_flow_assistant',
                                        $event,
                                    )
                                "
                            />Utilizar Assistente IA</label
                        >
                    </div>
                    <span v-else class="admin-access"
                        ><ShieldCheck :size="16" />Acesso administrativo</span
                    >
                </article>
            </div>
        </section>
    </SettingsLayout>
    <AppFormDialog
        :open="open"
        title="Adicionar usuário"
        description="Informe os dados da conta. Um email já cadastrado será vinculado ao workspace sem alterar seus dados."
        :busy="form.processing"
        :dirty="form.isDirty"
        @update:open="setOpen"
        v-slot="{ close }"
    >
        <form @submit.prevent="addMember">
            <fieldset
                class="form-fields team-fields"
                :disabled="form.processing"
            >
                <div class="form-field">
                    <label for="member-name">Nome</label
                    ><Input
                        id="member-name"
                        v-model="form.name"
                        autocomplete="name"
                        :aria-invalid="!!form.errors.name"
                        aria-describedby="member-name-help member-name-error"
                    /><small id="member-name-help" class="form-help"
                        >Obrigatório para uma nova conta.</small
                    ><small
                        id="member-name-error"
                        v-if="form.errors.name"
                        class="form-error"
                        role="alert"
                        >{{ form.errors.name }}</small
                    >
                </div>
                <div class="form-field">
                    <label for="member-email">Email</label
                    ><Input
                        id="member-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        :aria-invalid="!!form.errors.email"
                        :aria-describedby="
                            form.errors.email ? 'member-email-error' : undefined
                        "
                    /><small
                        id="member-email-error"
                        v-if="form.errors.email"
                        class="form-error"
                        role="alert"
                        >{{ form.errors.email }}</small
                    >
                </div>
                <div class="form-field">
                    <label for="member-phone">Número do WhatsApp</label>
                    <PhoneInput
                        id="member-phone"
                        v-model="form.phone"
                        :aria-invalid="!!form.errors.phone"
                        :aria-describedby="
                            form.errors.phone
                                ? 'member-phone-help member-phone-error'
                                : 'member-phone-help'
                        "
                    />
                    <small id="member-phone-help" class="form-help"
                        >Obrigatório para novas contas. Informe DDI + número,
                        somente dígitos. Ex.: 5511999999999.</small
                    >
                    <small
                        v-if="form.errors.phone"
                        id="member-phone-error"
                        class="form-error"
                        role="alert"
                        >{{ form.errors.phone }}</small
                    >
                </div>
                <div class="form-field">
                    <label for="member-password">Senha inicial</label
                    ><Input
                        id="member-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :aria-invalid="!!form.errors.password"
                        aria-describedby="member-password-help member-password-error"
                    /><small id="member-password-help" class="form-help"
                        >Obrigatória para uma nova conta.</small
                    ><small
                        id="member-password-error"
                        v-if="form.errors.password"
                        class="form-error"
                        role="alert"
                        >{{ form.errors.password }}</small
                    >
                </div>
                <div class="form-field">
                    <label for="member-confirm">Confirmar senha</label
                    ><Input
                        id="member-confirm"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        :aria-invalid="!!form.errors.password_confirmation"
                        :aria-describedby="
                            form.errors.password_confirmation
                                ? 'member-confirm-error'
                                : undefined
                        "
                    /><small
                        id="member-confirm-error"
                        v-if="form.errors.password_confirmation"
                        class="form-error"
                        role="alert"
                        >{{ form.errors.password_confirmation }}</small
                    >
                </div>
            </fieldset>
            <fieldset
                class="form-fields permission-fields"
                :disabled="form.processing"
            >
                <legend>Permissões</legend>
                <label class="form-checkbox" for="member-unassigned"
                    ><Checkbox
                        id="member-unassigned"
                        v-model="form.can_view_unassigned"
                        :disabled="form.processing"
                    />Ver conversas não atribuídas</label
                ><label class="form-checkbox" for="member-flows"
                    ><Checkbox
                        id="member-flows"
                        v-model="form.can_manage_flows"
                        :disabled="form.processing"
                    />Criar e editar fluxos</label
                >
                <label class="form-checkbox" for="member-assistant"
                    ><Checkbox
                        id="member-assistant"
                        v-model="form.can_use_flow_assistant"
                        :disabled="form.processing"
                    />Utilizar Assistente IA</label
                >
            </fieldset>
            <div class="form-actions">
                <AppButton
                    variant="secondary"
                    :disabled="form.processing"
                    @click="close()"
                    >Cancelar</AppButton
                ><AppButton type="submit" :loading="form.processing">{{
                    form.processing ? 'Adicionando…' : 'Adicionar à equipe'
                }}</AppButton>
            </div>
        </form>
    </AppFormDialog>
</template>
<style scoped>
.team-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 28px;
}
.team-header p {
    color: var(--muted-foreground);
    line-height: 1.6;
    margin: 0;
}
.team-header > button {
    flex: none;
}
.members-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
    overflow: hidden;
}
.members-caption {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
}
.members-caption svg {
    color: var(--muted-foreground);
}
.members-caption h2 {
    font-size: 14px;
    font-weight: 600;
}
.members-caption > span {
    font-size: 12px;
    background: var(--secondary);
    border-radius: 6px;
    padding: 2px 7px;
    color: var(--muted-foreground);
}
.member-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 24px;
    gap: 24px;
    border-bottom: 1px solid var(--border);
}
.member-row:last-child {
    border-bottom: 0;
}
.member-identity {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
}
.member-identity > div {
    min-width: 0;
    overflow-wrap: anywhere;
}
.member-identity strong {
    display: block;
    font-weight: 600;
    font-size: 14px;
}
.member-identity small {
    display: block;
    font-size: 12px;
    color: var(--muted-foreground);
    margin: 3px 0 8px;
}
.member-avatar {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--secondary);
    color: var(--muted-foreground);
    font-size: 11px;
    font-weight: 600;
    flex: none;
}
.role-badge {
    display: inline-block;
    font-size: 11px;
    padding: 2px 7px;
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--muted-foreground);
}
.member-permissions {
    display: grid;
    gap: 10px;
}
.admin-access {
    display: flex;
    gap: 7px;
    align-items: center;
    font-size: 12px;
    color: var(--muted-foreground);
}
.team-fields {
    grid-template-columns: 1fr 1fr;
}
.permission-fields {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}
.permission-fields legend {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 12px;
}
.notice {
    padding: 12px 16px;
    border-radius: 8px;
    margin: 0 0 20px;
}
.success {
    color: var(--success);
    background: var(--success-soft);
}
.error {
    color: var(--danger);
    background: var(--danger-soft);
}
.empty {
    padding: 32px;
    color: var(--muted-foreground);
    text-align: center;
}
@media (max-width: 640px) {
    .team-header {
        flex-direction: column;
    }
    .member-row {
        align-items: flex-start;
        flex-direction: column;
        padding: 20px;
    }
    .team-fields {
        grid-template-columns: 1fr;
    }
    .members-caption {
        padding: 18px 20px;
    }
    .form-checkbox {
        min-height: 44px;
    }
}
</style>

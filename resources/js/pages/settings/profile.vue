<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import PhoneInput from '@/components/PhoneInput.vue';
const page = usePage<any>();
const form = useForm({
    name: page.props.auth?.user?.name || '',
    email: page.props.auth?.user?.email || '',
    phone: page.props.auth?.user?.phone || '',
});
const csrfToken =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';
function save() {
    if (form.processing) return;
    form.patch('/settings/profile', { preserveScroll: true });
}
</script>
<template>
    <Head title="Meu perfil" /><SettingsLayout active="profile"
        ><section>
            <h1>Meu perfil</h1>
            <p>Atualize os dados da sua conta.</p>
            <div class="settings-card">
                <form @submit.prevent="save">
                    <fieldset :disabled="form.processing" class="form-fields">
                        <div class="form-field">
                            <label for="profile-name">Nome</label
                            ><Input
                                id="profile-name"
                                v-model="form.name"
                                autocomplete="name"
                                required
                                :aria-invalid="!!form.errors.name"
                                :aria-describedby="
                                    form.errors.name
                                        ? 'profile-name-error'
                                        : undefined
                                "
                            /><small
                                id="profile-name-error"
                                v-if="form.errors.name"
                                class="form-error"
                                role="alert"
                                >{{ form.errors.name }}</small
                            >
                        </div>
                        <div class="form-field">
                            <label for="profile-email">Email</label
                            ><Input
                                id="profile-email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                :aria-invalid="!!form.errors.email"
                                :aria-describedby="
                                    form.errors.email
                                        ? 'profile-email-error'
                                        : undefined
                                "
                            /><small
                                id="profile-email-error"
                                v-if="form.errors.email"
                                class="form-error"
                                role="alert"
                                >{{ form.errors.email }}</small
                            >
                        </div>
                        <div class="form-field">
                            <label for="profile-phone"
                                >Telefone / WhatsApp</label
                            >
                            <PhoneInput
                                id="profile-phone"
                                v-model="form.phone"
                                required
                                :aria-invalid="!!form.errors.phone"
                                :aria-describedby="
                                    form.errors.phone
                                        ? 'profile-phone-help profile-phone-error'
                                        : 'profile-phone-help'
                                "
                            />
                            <small id="profile-phone-help" class="form-help"
                                >Informe DDI + número, somente dígitos. Ex.:
                                5511999999999.</small
                            >
                            <small
                                v-if="form.errors.phone"
                                id="profile-phone-error"
                                class="form-error"
                                role="alert"
                                >{{ form.errors.phone }}</small
                            >
                        </div>
                    </fieldset>
                    <p
                        v-if="form.recentlySuccessful"
                        class="saved"
                        role="status"
                    >
                        Perfil atualizado.
                    </p>
                    <div class="form-actions">
                        <AppButton type="submit" :loading="form.processing"
                            >Salvar alterações</AppButton
                        >
                    </div>
                </form>
            </div>
            <form class="logout-form" method="post" action="/logout">
                <input
                    type="hidden"
                    name="_token"
                    :value="csrfToken"
                /><AppButton type="submit" variant="secondary"
                    >Sair da conta</AppButton
                >
            </form>
        </section></SettingsLayout
    >
</template>
<style scoped>
.logout-form {
    margin-top: 24px;
}
.saved {
    color: var(--success);
    margin-top: 16px;
}
</style>

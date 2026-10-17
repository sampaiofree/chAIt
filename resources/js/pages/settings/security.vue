<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const fields = [
    {
        key: 'current_password' as const,
        label: 'Senha atual',
        autocomplete: 'current-password',
    },
    {
        key: 'password' as const,
        label: 'Nova senha',
        autocomplete: 'new-password',
    },
    {
        key: 'password_confirmation' as const,
        label: 'Confirmar nova senha',
        autocomplete: 'new-password',
    },
];
function save() {
    if (form.processing) return;
    form.put('/settings/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <Head title="Segurança" /><SettingsLayout active="security"
        ><section>
            <h1>Segurança</h1>
            <p>Altere a senha da sua conta.</p>
            <div class="settings-card">
                <form @submit.prevent="save">
                    <fieldset :disabled="form.processing" class="form-fields">
                        <div
                            v-for="field in fields"
                            :key="field.key"
                            class="form-field"
                        >
                            <label :for="field.key">{{ field.label }}</label
                            ><Input
                                :id="field.key"
                                v-model="form[field.key]"
                                type="password"
                                :autocomplete="field.autocomplete"
                                required
                                :aria-invalid="!!form.errors[field.key]"
                                :aria-describedby="
                                    form.errors[field.key]
                                        ? `${field.key}-error`
                                        : undefined
                                "
                            /><small
                                v-if="form.errors[field.key]"
                                :id="`${field.key}-error`"
                                class="form-error"
                                role="alert"
                                >{{ form.errors[field.key] }}</small
                            >
                        </div>
                    </fieldset>
                    <p
                        v-if="form.recentlySuccessful"
                        class="saved"
                        role="status"
                    >
                        Senha atualizada.
                    </p>
                    <div class="form-actions">
                        <AppButton type="submit" :loading="form.processing"
                            >Atualizar senha</AppButton
                        >
                    </div>
                </form>
            </div>
        </section></SettingsLayout
    >
</template>
<style scoped>
.saved {
    color: var(--success);
    margin-top: 16px;
}
</style>

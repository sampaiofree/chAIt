<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    workspace: { id: number; name: string; icon_url: string | null };
}>();
const form = useForm<{ name: string; icon: File | null }>({
    name: props.workspace.name,
    icon: null,
});
const removing = ref(false);
const notice = ref('');
const removeError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.workspace.name,
    (name) => {
        form.name = name;
    },
);

function save() {
    if (form.processing || removing.value) return;
    notice.value = '';
    removeError.value = '';
    form.post('/settings/workspace', {
        preserveScroll: true,
        onSuccess: () => {
            form.icon = null;
            if (fileInput.value) fileInput.value.value = '';
            notice.value = 'Workspace atualizado.';
        },
    });
}

function removeIcon() {
    if (form.processing || removing.value) return;
    removing.value = true;
    notice.value = '';
    removeError.value = '';
    router.delete('/settings/workspace/icon', {
        preserveScroll: true,
        onSuccess: () => {
            form.icon = null;
            if (fileInput.value) fileInput.value.value = '';
            notice.value = 'Ícone removido.';
        },
        onError: () => {
            removeError.value = 'Não foi possível remover o ícone.';
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Workspace" />
    <SettingsLayout active="workspace">
        <section>
            <h1>Workspace</h1>
            <p>Atualize o nome e o ícone deste workspace.</p>
            <div class="settings-card">
                <form @submit.prevent="save">
                    <fieldset
                        :disabled="form.processing || removing"
                        class="form-fields"
                    >
                        <div class="form-field">
                            <label for="workspace-name"
                                >Nome do workspace</label
                            >
                            <Input
                                id="workspace-name"
                                v-model="form.name"
                                required
                                maxlength="255"
                                :aria-invalid="!!form.errors.name"
                                :aria-describedby="
                                    form.errors.name
                                        ? 'workspace-name-error'
                                        : undefined
                                "
                            />
                            <small
                                v-if="form.errors.name"
                                id="workspace-name-error"
                                class="form-error"
                                role="alert"
                                >{{ form.errors.name }}</small
                            >
                        </div>
                        <div class="form-field">
                            <label for="workspace-icon"
                                >Ícone do workspace</label
                            >
                            <div class="icon-preview">
                                <img
                                    v-if="workspace.icon_url"
                                    :src="workspace.icon_url"
                                    alt="Ícone atual do workspace"
                                />
                                <span v-else aria-label="Sem ícone">{{
                                    workspace.name
                                        .trim()
                                        .charAt(0)
                                        .toUpperCase() || '?'
                                }}</span>
                            </div>
                            <input
                                id="workspace-icon"
                                ref="fileInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                :aria-invalid="!!form.errors.icon"
                                :aria-describedby="
                                    form.errors.icon
                                        ? 'workspace-icon-error workspace-icon-help'
                                        : 'workspace-icon-help'
                                "
                                @change="
                                    form.icon =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                            <small id="workspace-icon-help"
                                >PNG, JPEG ou WebP, até 2 MB.</small
                            >
                            <small
                                v-if="form.errors.icon"
                                id="workspace-icon-error"
                                class="form-error"
                                role="alert"
                                >{{ form.errors.icon }}</small
                            >
                        </div>
                    </fieldset>
                    <p v-if="notice" role="status" class="notice">
                        {{ notice }}
                    </p>
                    <p v-if="removeError" role="alert" class="form-error">
                        {{ removeError }}
                    </p>
                    <div class="form-actions">
                        <AppButton
                            type="submit"
                            :loading="form.processing"
                            :disabled="removing"
                            >Salvar alterações</AppButton
                        >
                        <AppButton
                            v-if="workspace.icon_url"
                            type="button"
                            variant="secondary"
                            :loading="removing"
                            :disabled="form.processing"
                            @click="removeIcon"
                            >Remover ícone</AppButton
                        >
                    </div>
                </form>
            </div>
        </section>
    </SettingsLayout>
</template>

<style scoped>
.form-fields {
    display: grid;
    gap: 24px;
    border: 0;
    padding: 0;
    margin: 0;
}
.form-field {
    display: grid;
    gap: 8px;
    font-size: 13px;
}
.form-field label {
    font-weight: 500;
}
.form-field input[type='file'] {
    max-width: 100%;
}
.form-field small {
    color: var(--muted-foreground);
}
.form-field .form-error,
.form-error {
    color: var(--destructive);
}
.icon-preview {
    display: grid;
    place-items: center;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 24px;
}
.icon-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.notice {
    color: var(--foreground);
    margin-top: 16px;
}
</style>

<script setup lang="ts">
import { ref, watch, nextTick } from 'vue';
import { X } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import type {
    AppDialogResult,
    AppDialogState,
} from '@/composables/useAppDialog';
const props = defineProps<{ dialog: AppDialogState }>();
const emit = defineEmits<{ resolve: [result: AppDialogResult] }>();
const input = ref<InstanceType<typeof Input> | null>(null);
let previousFocus: HTMLElement | null = null;
watch(
    () => props.dialog.open,
    (open) => {
        if (open) {
            const active = document.activeElement as HTMLElement | null;
            // A queued alert can open while the previous dialog is closing.
            // Keep the original opener instead of a disappearing dialog control.
            if (
                active &&
                active !== document.body &&
                !active.closest('.app-form-dialog')
            ) {
                previousFocus = active;
            }
        }
    },
);
function submit() {
    if (props.dialog.mode === 'prompt') {
        const value = props.dialog.value.trim();
        if (!value) return;
        emit('resolve', value);
        return;
    }
    emit('resolve', true);
}
function cancel() {
    emit('resolve', props.dialog.mode === 'confirm' ? false : null);
}
async function focusInput(event: Event) {
    if (props.dialog.mode === 'prompt') {
        event.preventDefault();
        await nextTick();
        (input.value?.$el as HTMLInputElement)?.focus();
    }
}
function restoreFocus(event: Event) {
    event.preventDefault();
    previousFocus?.focus();
}
</script>
<template>
    <Dialog :open="dialog.open" @update:open="!$event && cancel()">
        <DialogContent
            class="app-form-dialog"
            :show-close-button="false"
            @open-auto-focus="focusInput"
            @close-auto-focus="restoreFocus"
        >
            <DialogHeader class="pr-10 text-left"
                ><DialogTitle>{{ dialog.title }}</DialogTitle
                ><DialogDescription :class="{ 'sr-only': !dialog.message }">{{
                    dialog.message || 'Informe os dados para continuar.'
                }}</DialogDescription></DialogHeader
            >
            <AppButton
                variant="ghost"
                size="icon"
                class="modal-close"
                aria-label="Fechar diálogo"
                title="Fechar diálogo"
                @click="cancel"
                ><X :size="20"
            /></AppButton>
            <form @submit.prevent="submit">
                <Input
                    v-if="dialog.mode === 'prompt'"
                    ref="input"
                    v-model="dialog.value"
                    :placeholder="dialog.placeholder"
                    :aria-label="dialog.title"
                    required
                    maxlength="120"
                />
                <div
                    class="form-actions"
                    :class="{
                        'form-actions--message': dialog.mode !== 'prompt',
                    }"
                >
                    <AppButton
                        v-if="dialog.mode !== 'alert'"
                        variant="secondary"
                        @click="cancel"
                        >{{ dialog.cancelLabel }}</AppButton
                    ><AppButton type="submit">{{
                        dialog.confirmLabel
                    }}</AppButton>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>

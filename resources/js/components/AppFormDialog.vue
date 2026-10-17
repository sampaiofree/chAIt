<script setup lang="ts">
import { ref, watch, nextTick } from 'vue';
import { X } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogCancel,
    AlertDialogAction,
} from '@/components/ui/alert-dialog';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        busy?: boolean;
        dirty?: boolean;
    }>(),
    { busy: false, dirty: false, description: '' },
);
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const discardOpen = ref(false);
const formBody = ref<HTMLElement | null>(null);
async function focusForm(event: Event) {
    event.preventDefault();
    await nextTick();
    formBody.value
        ?.querySelector<HTMLElement>(
            'input:not([disabled]), select:not([disabled]), textarea:not([disabled])',
        )
        ?.focus();
}
let previousFocus: HTMLElement | null = null;
watch(
    () => props.open,
    (open) => {
        if (open) previousFocus = document.activeElement as HTMLElement;
    },
);
function requestClose(open = false) {
    if (open || props.busy) return;
    if (props.dirty) {
        discardOpen.value = true;
        return;
    }
    emit('update:open', false);
}
function discard() {
    discardOpen.value = false;
    emit('update:open', false);
}
function restoreFocus(event: Event) {
    event.preventDefault();
    previousFocus?.focus();
}
</script>
<template>
    <Dialog :open="open" @update:open="requestClose">
        <DialogContent
            :show-close-button="false"
            class="app-form-dialog"
            @open-auto-focus="focusForm"
            @close-auto-focus="restoreFocus"
            @interact-outside="busy && $event.preventDefault()"
            @escape-key-down="busy && $event.preventDefault()"
        >
            <DialogHeader class="pr-10 text-left"
                ><DialogTitle>{{ title }}</DialogTitle
                ><DialogDescription v-if="description">{{
                    description
                }}</DialogDescription
                ><DialogDescription v-else class="sr-only"
                    >Preencha os dados e salve as alterações.</DialogDescription
                ></DialogHeader
            >
            <AppButton
                class="modal-close"
                variant="ghost"
                size="icon"
                aria-label="Fechar formulário"
                title="Fechar formulário"
                :disabled="busy"
                @click="requestClose()"
                ><X :size="20"
            /></AppButton>
            <div ref="formBody"><slot :close="requestClose" /></div>
        </DialogContent>
    </Dialog>
    <AlertDialog v-model:open="discardOpen">
        <AlertDialogContent class="z-[60]"
            ><AlertDialogHeader
                ><AlertDialogTitle>Descartar alterações?</AlertDialogTitle
                ><AlertDialogDescription
                    >Os dados preenchidos ainda não foram
                    salvos.</AlertDialogDescription
                ></AlertDialogHeader
            ><AlertDialogFooter
                ><AlertDialogCancel>Continuar editando</AlertDialogCancel
                ><AlertDialogAction @click="discard"
                    >Descartar</AlertDialogAction
                ></AlertDialogFooter
            ></AlertDialogContent
        >
    </AlertDialog>
</template>

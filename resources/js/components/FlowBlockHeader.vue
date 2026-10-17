<script setup lang="ts">
import {
    computed,
    nextTick,
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import { Pencil } from '@lucide/vue';
import AppIconButton from '@/components/AppIconButton.vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{ blockId: string; kind: string; name: string }>();
const emit = defineEmits<{
    'update:name': [value: string];
    'validity-change': [invalid: boolean];
}>();
const editing = ref(false);
const draft = ref('');
const attempted = ref(false);
// Commit before Save handles the click, but keep the pointer target in place
// until that click finishes before collapsing the inline editor.
let pointerDown = false;
let closeAfterClick = false;
let closeTimer: ReturnType<typeof setTimeout> | undefined;
function finishPointerClick() {
    if (closeAfterClick) {
        editing.value = false;
        closeAfterClick = false;
    }
}
function onPointerDown() {
    pointerDown = true;
}
function onPointerUp() {
    pointerDown = false;
    closeTimer = setTimeout(finishPointerClick, 0);
}
onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('pointerup', onPointerUp, true);
    document.addEventListener('click', finishPointerClick);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown, true);
    document.removeEventListener('pointerup', onPointerUp, true);
    document.removeEventListener('click', finishPointerClick);
    clearTimeout(closeTimer);
});
function commitOnBlur() {
    commit(false, pointerDown);
}

const headingElement = ref<HTMLElement | null>(null);
const input = ref<InstanceType<typeof Input> | null>(null);
const invalid = computed(
    () => !draft.value.trim() || draft.value.trim().length > 120,
);
watch(
    [editing, invalid],
    () => emit('validity-change', editing.value && invalid.value),
    { flush: 'sync' },
);
watch(
    () => props.blockId,
    () => {
        editing.value = false;
        attempted.value = false;
        draft.value = props.name;
    },
);
async function startEditing() {
    draft.value = props.name;
    closeAfterClick = false;
    clearTimeout(closeTimer);
    attempted.value = false;
    editing.value = true;
    await nextTick();
    (input.value?.$el as HTMLInputElement)?.focus();
    (input.value?.$el as HTMLInputElement)?.select();
}
async function focusEditButton() {
    await nextTick();
    headingElement.value
        ?.querySelector<HTMLButtonElement>(
            'button[aria-label="Editar nome interno do bloco"]',
        )
        ?.focus();
}
function commit(returnFocus = false, deferClose = false) {
    if (!editing.value) return;
    if (invalid.value) {
        attempted.value = true;
        return;
    }
    const name = draft.value.trim();
    if (name !== props.name) emit('update:name', name);
    if (deferClose) closeAfterClick = true;
    else editing.value = false;
    attempted.value = false;
    if (returnFocus) void focusEditButton();
}
function cancel() {
    closeAfterClick = false;
    clearTimeout(closeTimer);
    editing.value = false;
    attempted.value = false;
    void focusEditButton();
}
</script>
<template>
    <header ref="headingElement" class="block-header">
        <div class="block-heading">
            <small>{{ kind }}</small>
            <div class="block-title">
                <h2 :title="name">{{ name }}</h2>
                <AppIconButton
                    label="Editar nome interno do bloco"
                    @click="startEditing"
                    ><Pencil :size="16"
                /></AppIconButton>
            </div>
        </div>
        <slot name="close" />
    </header>
    <div v-if="editing" class="block-name-editor nodrag">
        <label :for="`block-name-${blockId}`">Nome interno do bloco</label>
        <Input
            ref="input"
            :id="`block-name-${blockId}`"
            v-model="draft"
            maxlength="120"
            required
            :aria-invalid="attempted && invalid"
            :aria-describedby="`block-name-help-${blockId}${attempted && invalid ? ` block-name-error-${blockId}` : ''}`"
            @blur="commitOnBlur"
            @keydown.enter.prevent="commit(true)"
            @keydown.esc.prevent.stop="cancel"
        />
        <small :id="`block-name-help-${blockId}`" class="form-help"
            >Visível apenas para sua equipe</small
        >
        <small
            v-if="attempted && invalid"
            :id="`block-name-error-${blockId}`"
            class="form-error"
            role="alert"
            >Informe um nome de até 120 caracteres.</small
        >
    </div>
</template>
<style scoped>
.block-header {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 20px;
}
.block-heading {
    flex: 1;
    min-width: 0;
}
.block-heading > small {
    color: var(--muted-foreground);
    font-size: 11px;
    font-weight: 500;
}
.block-title {
    min-height: 44px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.block-title h2 {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.5;
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    margin: 0;
}
.block-name-editor {
    display: grid;
    gap: 6px;
    padding: 12px;
    background: var(--secondary);
    border-radius: var(--radius);
    margin-bottom: 20px;
}
.block-name-editor label {
    font-size: 12px;
    font-weight: 500;
}
</style>

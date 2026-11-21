<script setup lang="ts">
import {
    computed,
    nextTick,
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    blockId: string;
    nodeType: string;
    kind: string;
    name: string;
    readOnly?: boolean;
}>();
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
watch(
    () => props.readOnly,
    (readOnly) => {
        if (readOnly) editing.value = false;
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
    <header
        ref="headingElement"
        class="block-header"
        :data-flow-type="nodeType"
    >
        <div class="block-heading">
            <small>{{ kind }}</small>
            <div class="block-title nodrag">
                <h2 v-if="readOnly">{{ name }}</h2>
                <h2 v-else-if="!editing"><button type="button" class="title-button" aria-label="Editar nome interno do bloco" :title="name" @click="startEditing">{{ name }}</button></h2>
                <div v-else class="inline-name-editor">
                    <Input ref="input" :id="`block-name-${blockId}`" v-model="draft" aria-label="Nome interno do bloco" maxlength="120" required :aria-invalid="attempted && invalid" :aria-describedby="attempted && invalid ? `block-name-error-${blockId}` : undefined" @blur="commitOnBlur" @keydown.enter.prevent="commit(true)" @keydown.esc.prevent.stop="cancel" />
                    <small v-if="attempted && invalid" :id="`block-name-error-${blockId}`" class="form-error" role="alert">Informe um nome de até 120 caracteres.</small>
                </div>
            </div>
        </div>
        <slot name="close" />
    </header>
</template>
<style scoped>
.block-header {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 12px;
    /*border-top: 4px solid var(--flow-type-color);*/
    border-bottom: 1px solid var(--border);
    border-radius: var(--radius) var(--radius) 0 0;
    background: var(--flow-type-soft);
    margin-bottom: 20px;
}
.block-heading {
    flex: 1;
    min-width: 0;
}
.block-heading > small {
    color: var(--flow-type-text);
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
.title-button {
    display: block;
    width: 100%;
    text-align: left;
    cursor: text;
    overflow-wrap: anywhere;
}
.title-button:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
.inline-name-editor { width: 100%; min-width: 0; }
.inline-name-editor input { width: 100%; font-size: 14px; font-weight: 600; }
</style>

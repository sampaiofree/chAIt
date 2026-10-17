<script setup lang="ts">
import {
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
    nextTick,
    useAttrs,
} from 'vue';
import {
    type ContactPart,
    type ContactTemplate,
    type ContactFields,
    standardFields,
    templateParts,
    templateUnits,
    normalizeTemplate,
    partLabel,
    templateDisplay,
    validClipboardParts,
} from '@/lib/contactTemplate';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        modelValue?: ContactTemplate;
        fields?: ContactFields;
        workspaceId: number;
        chips?: boolean;
        rows?: string | number;
        maxlength?: string | number;
        placeholder?: string;
    }>(),
    { chips: true, rows: 2 },
);
const emit = defineEmits<{
    'update:modelValue': [ContactTemplate];
    input: [];
}>();
const attrs = useAttrs();
const root = ref<HTMLDivElement>();
const wrapper = ref<HTMLDivElement>();
const opened = ref(false);
const search = ref('');
const error = ref('');
const mime = 'application/x-chait-contact-template+json';
type Selection = { start: number; end: number };
type State = { value: ContactTemplate; selection: Selection };
let value: ContactTemplate = props.modelValue || '';
let selection: Selection = { start: 0, end: 0 };
const composing = ref<boolean>(false);
let history: State[] = [];
let index = -1;
function catalog(): ContactFields {
    return props.fields || { standard: standardFields, custom: [] };
}
function serialize(node: Node): ContactPart[] {
    if (node.nodeType === Node.TEXT_NODE)
        return [{ type: 'text', text: node.textContent || '' }];
    if (!(node instanceof Element))
        return Array.from(node.childNodes).flatMap(serialize);
    if (node instanceof HTMLElement && node.dataset.contactPart) {
        try {
            return [JSON.parse(node.dataset.contactPart) as ContactPart];
        } catch {
            return [];
        }
    }
    if (node.tagName === 'BR') return [{ type: 'text', text: '\n' }];
    const parts = Array.from(node.childNodes).flatMap(serialize);
    if (
        ['DIV', 'P'].includes(node.tagName) &&
        node !== root.value &&
        node.previousSibling
    )
        parts.unshift({ type: 'text', text: '\n' });
    return parts;
}
function size(node: Node): number {
    return templateUnits(normalizeTemplate(serialize(node))).length;
}
function capture() {
    const s = window.getSelection();
    const el = root.value;
    if (
        !el ||
        !s?.rangeCount ||
        !el.contains(s.anchorNode) ||
        !el.contains(s.focusNode)
    )
        return;
    const r = s.getRangeAt(0);
    const chipAt = (node: Node) =>
        (node instanceof Element ? node : node.parentElement)?.closest(
            '[data-contact-part]',
        );
    const startChip = chipAt(r.startContainer);
    const endChip = chipAt(r.endContainer);
    if (startChip && el.contains(startChip)) r.setStartBefore(startChip);
    if (endChip && el.contains(endChip)) r.setEndAfter(endChip);
    const prefix = r.cloneRange();
    prefix.selectNodeContents(el);
    prefix.setEnd(r.startContainer, r.startOffset);
    const through = r.cloneRange();
    through.selectNodeContents(el);
    through.setEnd(r.endContainer, r.endOffset);
    selection = {
        start: size(prefix.cloneContents()),
        end: size(through.cloneContents()),
    };
}
function point(offset: number): [Node, number] {
    const el = root.value!;
    let remaining = offset;
    for (const node of Array.from(el.childNodes)) {
        const length =
            node.nodeType === Node.TEXT_NODE
                ? (node.textContent || '').length
                : 1;
        if (
            remaining < length ||
            (remaining === length && node.nodeType === Node.TEXT_NODE)
        )
            return [node, remaining];
        if (remaining === 0)
            return [el, Array.from(el.childNodes).indexOf(node as ChildNode)];
        remaining -= length;
    }
    return [el, el.childNodes.length];
}
function restore() {
    const el = root.value;
    if (!el) return;
    el.focus();
    const r = document.createRange();
    const start = point(selection.start);
    const end = point(selection.end);
    r.setStart(...start);
    r.setEnd(...end);
    const s = window.getSelection();
    s?.removeAllRanges();
    s?.addRange(r);
}
function draw() {
    const el = root.value;
    if (!el) return;
    el.replaceChildren();
    for (const p of templateParts(value)) {
        if (p.type === 'text') {
            el.append(document.createTextNode(p.text));
            continue;
        }
        const chip = document.createElement('span');
        chip.contentEditable = 'false';
        chip.className = 'contact-chip';
        chip.dataset.contactPart = JSON.stringify(p);
        chip.textContent = partLabel(p, catalog());
        chip.setAttribute('role', 'img');
        const label = `${partLabel(p, catalog())}${p.type === 'custom' ? ` #${p.field_id}` : ''}`;
        chip.setAttribute('aria-label', `Campo do contato: ${label}`);
        chip.title = label;
        el.append(chip);
    }
}
function state(): State {
    return {
        value: JSON.parse(JSON.stringify(value)),
        selection: { ...selection },
    };
}
function remember() {
    history = history.slice(0, index + 1);
    history.push(state());
    if (history.length > 150) history.shift();
    index = history.length - 1;
}
function publish(redraw = true) {
    if (redraw) {
        draw();
        restore();
    }
    remember();
    emit('update:modelValue', value);
    emit('input');
}
function beforeChange() {
    capture();
    if (history[index]) history[index].selection = { ...selection };
}
function replace(parts: ContactPart[], captureSelection = true) {
    if (captureSelection) beforeChange();
    const units = templateUnits(value);
    const inserted = templateUnits(normalizeTemplate(parts));
    units.splice(selection.start, selection.end - selection.start, ...inserted);
    value = normalizeTemplate(units);
    selection = {
        start: selection.start + inserted.length,
        end: selection.start + inserted.length,
    };
    error.value = '';
    publish();
}
function undo(direction: number) {
    beforeChange();
    const next = index + direction;
    if (!history[next]) return;
    index = next;
    value = JSON.parse(JSON.stringify(history[index].value));
    selection = { ...history[index].selection };
    draw();
    restore();
    emit('update:modelValue', value);
    emit('input');
}
function beforeInput(event: InputEvent) {
    if (composing.value || event.isComposing) return;
    beforeChange();
    if (
        event.inputType === 'historyUndo' ||
        event.inputType === 'historyRedo'
    ) {
        event.preventDefault();
        undo(event.inputType === 'historyUndo' ? -1 : 1);
    } else if (
        ['insertText', 'insertLineBreak', 'insertParagraph'].includes(
            event.inputType,
        )
    ) {
        event.preventDefault();
        replace([
            {
                type: 'text',
                text:
                    event.inputType === 'insertText' ? event.data || '' : '\n',
            },
        ]);
    } else if (
        event.inputType.startsWith('delete') &&
        ['deleteContentBackward', 'deleteContentForward'].includes(
            event.inputType,
        )
    ) {
        event.preventDefault();
        deleteSelection(event.inputType.endsWith('Backward'));
    }
}
function deleteSelection(backward: boolean) {
    beforeChange();
    if (selection.start === selection.end) {
        const units = templateUnits(value);
        const p = units[backward ? selection.start - 1 : selection.end];
        const pair =
            p?.type === 'text' &&
            (backward ? /[\uDC00-\uDFFF]/ : /[\uD800-\uDBFF]/).test(p.text);
        const amount = pair ? 2 : 1;
        if (backward) selection.start = Math.max(0, selection.start - amount);
        else selection.end = Math.min(units.length, selection.end + amount);
    }
    restore();
    replace([], false);
}
function nativeInput() {
    if (composing.value || !root.value) return;
    value = normalizeTemplate(serialize(root.value));
    capture();
    publish(false);
}
function keydown(event: KeyboardEvent) {
    if (composing.value) return;
    if (
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        ['Backspace', 'Delete'].includes(event.key)
    ) {
        event.preventDefault();
        deleteSelection(event.key === 'Backspace');
        return;
    }
    if (
        (event.ctrlKey || event.metaKey) &&
        ['z', 'y'].includes(event.key.toLowerCase())
    ) {
        event.preventDefault();
        undo(event.key.toLowerCase() === 'y' || event.shiftKey ? 1 : -1);
    }
}
function copy(event: ClipboardEvent, cut = false) {
    capture();
    if (selection.start === selection.end) return;
    const parts = templateUnits(value).slice(selection.start, selection.end);
    event.preventDefault();
    event.clipboardData?.setData(
        'text/plain',
        templateDisplay(normalizeTemplate(parts), catalog()),
    );
    event.clipboardData?.setData(
        mime,
        JSON.stringify({ workspace_id: props.workspaceId, parts }),
    );
    if (cut) replace([]);
}
function paste(event: ClipboardEvent) {
    event.preventDefault();
    const raw = event.clipboardData?.getData(mime);
    if (raw) {
        try {
            const data = JSON.parse(raw);
            if (data.workspace_id !== props.workspaceId) {
                error.value = 'Não é possível colar campos de outro workspace.';
                return;
            }
            if (!validClipboardParts(data.parts, catalog())) {
                error.value =
                    'A referência colada não está disponível neste workspace.';
                return;
            }
            if (
                !props.chips &&
                data.parts.some((p: ContactPart) => p.type !== 'text')
            ) {
                error.value =
                    'O elemento Resposta não aceita campos do contato.';
                return;
            }
            replace(data.parts);
        } catch {
            error.value = 'Conteúdo estruturado inválido.';
        }
        return;
    }
    replace([
        {
            type: 'text',
            text: event.clipboardData?.getData('text/plain') || '',
        },
    ]);
}
function insert(part: ContactPart) {
    replace([part]);
    opened.value = false;
    search.value = '';
}
function format(mark: string) {
    beforeChange();
    const units = templateUnits(value);
    const start = selection.start;
    const end = selection.end;
    if (start === end) {
        selection = { start: 0, end: units.length };
        restore();
    }
    const selected = units.slice(selection.start, selection.end);
    replace([
        { type: 'text', text: mark },
        ...selected,
        { type: 'text', text: mark },
    ]);
}
function matches(label: string) {
    return label.toLocaleLowerCase().includes(search.value.toLocaleLowerCase());
}
defineExpose({ format });
watch(
    () => props.modelValue,
    (next) => {
        if (JSON.stringify(next || '') === JSON.stringify(value)) return;
        value = JSON.parse(JSON.stringify(next || ''));
        selection = { start: 0, end: 0 };
        draw();
        history = [];
        remember();
    },
);
watch(
    () => JSON.stringify(props.fields),
    () => {
        if (composing.value) return;
        const focused = root.value === document.activeElement;
        capture();
        draw();
        if (focused) restore();
    },
);
function closeOutside(event: PointerEvent) {
    if (
        opened.value &&
        event.target instanceof Node &&
        !wrapper.value?.contains(event.target)
    )
        opened.value = false;
}
onMounted(() => {
    draw();
    remember();
    document.addEventListener('selectionchange', capture);
    document.addEventListener('pointerdown', closeOutside);
});
onBeforeUnmount(() => {
    document.removeEventListener('selectionchange', capture);
    document.removeEventListener('pointerdown', closeOutside);
});
</script>
<template>
    <div ref="wrapper" class="contact-template-field">
        <div v-if="chips" class="contact-field-tools">
            <button
                type="button"
                :aria-expanded="opened"
                @pointerdown.prevent
                @click="
                    capture();
                    opened = !opened;
                    if (opened)
                        nextTick(() =>
                            ($refs.searchInput as HTMLInputElement)?.focus(),
                        );
                "
            >
                Inserir campo
            </button>
            <div
                v-if="opened"
                class="contact-field-picker"
                @keydown.esc.stop="
                    opened = false;
                    restore();
                "
            >
                <input
                    ref="searchInput"
                    v-model="search"
                    placeholder="Pesquisar campo…"
                    aria-label="Pesquisar campo do contato"
                />
                <small>Dados padrão do contato</small>
                <button
                    v-for="field in catalog().standard.filter((f) =>
                        matches(f.label),
                    )"
                    :key="field.key"
                    type="button"
                    @click="insert({ type: 'standard', key: field.key })"
                >
                    {{ field.label }}
                </button>
                <small>Campos personalizados</small>
                <button
                    v-for="field in catalog().custom.filter((f) =>
                        matches(f.name + ' #' + f.id),
                    )"
                    :key="field.id"
                    type="button"
                    @click="insert({ type: 'custom', field_id: field.id })"
                >
                    {{ field.name }} <span>#{{ field.id }}</span>
                </button>
                <button
                    type="button"
                    @click="
                        opened = false;
                        restore();
                    "
                >
                    Fechar
                </button>
            </div>
        </div>
        <div
            v-bind="attrs"
            ref="root"
            class="contact-template-input"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :data-placeholder="placeholder"
            :style="{ minHeight: `${Number(rows) * 1.5 + 1}rem` }"
            spellcheck="true"
            @beforeinput="beforeInput"
            @input="nativeInput"
            @keydown="keydown"
            @compositionstart="
                composing = true;
                beforeChange();
            "
            @compositionend="
                composing = false;
                nativeInput();
                draw();
                restore();
            "
            @copy="copy($event)"
            @cut="copy($event, true)"
            @paste="paste"
            @drop.prevent="error = 'Cole o texto ou use Inserir campo.'"
        />
        <p v-if="error" role="alert" class="contact-field-error">{{ error }}</p>
    </div>
</template>
<style scoped>
.contact-template-field {
    position: relative;
    min-width: 0;
    width: 100%;
}
.contact-template-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--input);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--foreground);
    font-size: 0.875rem;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    outline: none;
}
.contact-template-input:focus {
    border-color: var(--ring);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring) 30%, transparent);
}
.contact-template-input:empty::before {
    content: attr(data-placeholder);
    color: var(--muted-foreground);
    pointer-events: none;
}
.contact-template-input :deep(.contact-chip) {
    display: inline;
    border-radius: 0.3rem;
    background: var(--accent);
    color: var(--accent-foreground);
    padding: 0.1rem 0.3rem;
    border: 1px solid var(--border);
    box-decoration-break: clone;
}
.contact-field-tools {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 0.25rem;
}
.contact-field-tools > button {
    color: var(--brand-strong);
    font-size: 0.75rem;
    border-radius: 0.25rem;
    padding: 0.25rem;
}
button:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.contact-field-picker {
    position: absolute;
    z-index: 40;
    right: 0;
    top: 1.8rem;
    max-width: 100%;
    width: 300px;
    max-height: 300px;
    overflow-y: auto;
    display: grid;
    gap: 0.25rem;
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    background: var(--popover);
    color: var(--popover-foreground);
    box-shadow: 0 8px 25px #0002;
}
.contact-field-picker input {
    padding: 0.5rem;
    border: 1px solid var(--input);
    border-radius: 0.3rem;
    width: 100%;
}
.contact-field-picker small {
    color: var(--muted-foreground);
    padding-top: 0.5rem;
}
.contact-field-picker button {
    padding: 0.35rem;
    text-align: left;
    border-radius: 0.25rem;
    overflow-wrap: anywhere;
}
.contact-field-picker button:hover {
    background: var(--accent);
}
.contact-field-picker span {
    color: var(--muted-foreground);
    font-size: 0.75rem;
}
.contact-field-error {
    color: var(--destructive);
    font-size: 0.75rem;
}
</style>

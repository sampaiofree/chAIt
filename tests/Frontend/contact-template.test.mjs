import assert from 'node:assert/strict';
import { test } from 'node:test';
import { reapplyFlowChanges } from '../../resources/js/composables/useFlowAssistant.ts';
import {
    normalizeTemplate,
    templateDisplay,
    hasContactFields,
    templateUnits,
    validClipboardParts,
} from '../../resources/js/lib/contactTemplate.ts';

test('literal normalization preserves whitespace zero braces and field identity', () => {
    const doc = normalizeTemplate([
        { type: 'text', text: ' 0 ' },
        { type: 'text', text: '{nome}' },
        { type: 'custom', field_id: 4 },
    ]);
    assert.deepEqual(doc.parts, [
        { type: 'text', text: ' 0 {nome}' },
        { type: 'custom', field_id: 4 },
    ]);
    assert.equal(
        templateDisplay(doc, {
            standard: [],
            custom: [{ id: 4, name: 'Curso novo' }],
        }),
        ' 0 {nome}{Curso novo}',
    );
    assert.equal(hasContactFields('{nome}'), false);
    assert.equal(
        normalizeTemplate([
            { type: 'text', text: ' ' },
            { type: 'text', text: '0' },
        ]),
        ' 0',
    );
    assert.equal(templateUnits(doc).at(-1).field_id, 4);
});
test('clipboard rejects unknown structures and unavailable custom references', () => {
    const fields = { standard: [], custom: [{ id: 2, name: 'Curso' }] };
    assert.equal(
        validClipboardParts([{ type: 'custom', field_id: 2 }], fields),
        true,
    );
    assert.equal(
        validClipboardParts([{ type: 'custom', field_id: 99 }], fields),
        false,
    );
    assert.equal(
        validClipboardParts([{ type: 'standard', key: 'cpf' }], fields),
        false,
    );
    assert.equal(
        validClipboardParts(
            [{ type: 'custom', field_id: 2, text: 'inventado' }],
            fields,
        ),
        false,
    );
});
test('shared conflict reapplication keeps the complete local template and remote layout', () => {
    const before = {
        template_version: 1,
        parts: [{ type: 'standard', key: 'nome' }],
    };
    const local = {
        template_version: 1,
        parts: [
            { type: 'text', text: 'Olá ' },
            { type: 'custom', field_id: 4 },
        ],
    };
    const remote = {
        template_version: 1,
        parts: [{ type: 'standard', key: 'telefone' }],
    };
    const node = (text) => ({
        id: 'a',
        data: { text },
        position: { x: 0, y: 0 },
    });
    const merged = reapplyFlowChanges(
        [node(before)],
        [node(local)],
        [{ ...node(remote), position: { x: 10, y: 30 } }],
    );
    assert.deepEqual(merged, [{ ...node(local), position: { x: 10, y: 30 } }]);
});

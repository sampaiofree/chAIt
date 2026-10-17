import assert from 'node:assert/strict';
import { test } from 'node:test';
import { reapplyFlowChanges } from '../../resources/js/composables/useFlowAssistant.ts';

test('reapplication preserves remote positions and blocks added by the team', () => {
    const base = {
        nodes: [
            {
                id: 'welcome',
                data: { label: 'Antes', text: 'Olá' },
                position: { x: 0, y: 100 },
            },
        ],
        edges: [],
    };
    const local = {
        nodes: [
            {
                id: 'welcome',
                data: { label: 'Boas-vindas', text: 'Olá' },
                position: { x: 0, y: 100 },
            },
        ],
        edges: [],
    };
    const remote = {
        nodes: [
            {
                id: 'welcome',
                data: { label: 'Antes', text: 'Olá' },
                position: { x: 300, y: 100 },
            },
            { id: 'support', data: { label: 'Suporte' } },
        ],
        edges: [],
    };
    assert.deepEqual(reapplyFlowChanges(base, local, remote), {
        nodes: [
            {
                id: 'welcome',
                data: { label: 'Boas-vindas', text: 'Olá' },
                position: { x: 300, y: 100 },
            },
            { id: 'support', data: { label: 'Suporte' } },
        ],
        edges: [],
    });
});

test('explicit reapplication resolves overlapping text edits locally and preserves other remote fields', () => {
    assert.deepEqual(
        reapplyFlowChanges(
            { label: 'Antes', text: 'Olá' },
            { label: 'Local', text: 'Olá' },
            { label: 'Equipe', text: 'Bem-vindo' },
        ),
        { label: 'Local', text: 'Bem-vindo' },
    );
});

test('local node deletion does not delete unrelated team nodes or restore remote deletions', () => {
    const base = [
        { id: 'a', label: 'A' },
        { id: 'b', label: 'B' },
    ];
    const local = [{ id: 'b', label: 'B' }];
    const remote = [
        { id: 'a', label: 'A' },
        { id: 'c', label: 'C' },
    ];
    assert.deepEqual(reapplyFlowChanges(base, local, remote), [
        { id: 'c', label: 'C' },
    ]);
});

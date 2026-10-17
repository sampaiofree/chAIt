import assert from 'node:assert/strict';
import { test } from 'node:test';
import { useAppDialog } from '../../resources/js/composables/useAppDialog.ts';

test('alerts wait for acknowledgment and then close', async () => {
    const appDialog = useAppDialog();
    const result = appDialog.alert('Falha ao salvar', 'Não foi possível');

    assert.equal(appDialog.dialog.open, true);
    assert.equal(appDialog.dialog.mode, 'alert');
    assert.equal(appDialog.dialog.title, 'Não foi possível');
    assert.equal(appDialog.dialog.message, 'Falha ao salvar');

    appDialog.finish(true);
    await result;
    assert.equal(appDialog.dialog.open, false);
});

test('confirmations distinguish acceptance from cancellation', async () => {
    const appDialog = useAppDialog();
    const accepted = appDialog.confirm('Excluir pasta?', 'Excluir');
    appDialog.finish(true);
    assert.equal(await accepted, true);

    const canceled = appDialog.confirm('Excluir pasta?', 'Excluir');
    appDialog.finish(false);
    assert.equal(await canceled, false);
});

test('prompts preserve an initial value and resolve to text or cancellation', async () => {
    const appDialog = useAppDialog();
    const submitted = appDialog.prompt('Renomear fluxo', 'Boas-vindas');
    assert.equal(appDialog.dialog.mode, 'prompt');
    assert.equal(appDialog.dialog.value, 'Boas-vindas');
    appDialog.dialog.value = 'Novo nome';
    appDialog.finish(appDialog.dialog.value);
    assert.equal(await submitted, 'Novo nome');

    const canceled = appDialog.prompt('Novo fluxo');
    appDialog.finish(null);
    assert.equal(await canceled, null);
});

test('a second dialog waits until the first is answered', async () => {
    const appDialog = useAppDialog();
    const first = appDialog.alert('Primeiro aviso');
    const second = appDialog.confirm('Continuar?');

    assert.equal(appDialog.dialog.message, 'Primeiro aviso');
    appDialog.finish(true);
    await first;
    await Promise.resolve();
    assert.equal(appDialog.dialog.message, 'Continuar?');
    assert.equal(appDialog.dialog.open, true);

    appDialog.finish(false);
    assert.equal(await second, false);
    assert.equal(appDialog.dialog.open, false);
});

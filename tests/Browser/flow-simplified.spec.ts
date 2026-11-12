import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function createFlow(page: Page) {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/workspaces/1/flows');
    const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    const response = await page.request.post('/workspaces/1/flows', {
        headers: { 'X-CSRF-TOKEN': csrf || '', Accept: 'application/json' },
        data: { name: 'Novo fluxo' },
    });
    expect(response.ok()).toBeTruthy();
    const flow = (await response.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    await page.goto(endpoint);
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();

    return endpoint;
}

test('autosave persists and only one browser tab can edit', async ({
    page,
    context,
}) => {
    const endpoint = await createFlow(page);
    await page.getByLabel('Nome do fluxo').fill('Fluxo salvo automaticamente');
    await expect
        .poll(
            async () =>
                (await (await page.request.get(`${endpoint}/assistant`)).json())
                    .data.flow.name,
        )
        .toBe('Fluxo salvo automaticamente');
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
    const other = await context.newPage();
    await other.goto(endpoint);
    await expect(other.getByLabel('Nome do fluxo')).toBeDisabled();
    await expect(other.getByText(/está editando/)).toBeVisible();
    await page.close();
    await expect(other.getByLabel('Nome do fluxo')).toBeEnabled({
        timeout: 12000,
    });
    await other.getByLabel('Nome do fluxo').fill('Outra pessoa assumiu');
    await expect
        .poll(
            async () =>
                (
                    await (
                        await other.request.get(`${endpoint}/assistant`)
                    ).json()
                ).data.flow.name,
        )
        .toBe('Outra pessoa assumiu');
});

test('an incomplete flow cannot be activated', async ({ page }) => {
    await createFlow(page);
    await page.getByRole('button', { name: 'Ativar', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('Conecte e configure');
    await expect(page.getByText('Inativo', { exact: true })).toBeVisible();
});

test('an imported flow activates and becomes editable again only after inactivation', async ({
    page,
    context,
}) => {
    const endpoint = await createFlow(page);
    const document = {
        format: 'chait-flow',
        schema_version: '1.0',
        name: 'Importado',
        dependencies: { tags: [], fields: [] },
        nodes: [
            { id: 'start', type: 'start', data: { label: 'Início' } },
            {
                id: 'content',
                type: 'content',
                data: {
                    label: 'Mensagem',
                    items: [{ id: 'item', kind: 'text', text: 'Olá!' }],
                },
            },
        ],
        edges: [{ source: 'start', output: 'next', target: 'content' }],
    };
    await page.getByRole('button', { name: 'Ações do fluxo' }).click();
    await page.getByRole('menuitem', { name: 'Importar JSON' }).click();
    await page.getByLabel('Conteúdo JSON').fill(JSON.stringify(document));
    await page.getByRole('button', { name: 'Validar JSON' }).click();
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect
        .poll(
            async () =>
                (await (await page.request.get(`${endpoint}/assistant`)).json())
                    .data.flow.graph.nodes.length,
        )
        .toBe(2);
    const other = await context.newPage();
    await other.goto(endpoint);
    await expect(other.getByLabel('Nome do fluxo')).toBeDisabled();
    await page.getByRole('button', { name: 'Ativar', exact: true }).click();
    await expect(page.getByText('Ativo', { exact: true })).toBeVisible();
    await expect(other.getByText('Ativo', { exact: true })).toBeVisible({
        timeout: 10000,
    });
    await expect(page.getByLabel('Nome do fluxo')).toBeDisabled();
    await expect(
        page.getByRole('button', { name: 'Assistente IA' }),
    ).toBeDisabled();
    await page.getByRole('button', { name: 'Inativar', exact: true }).click();
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();
    await expect(other.getByText('Inativo', { exact: true })).toBeVisible({
        timeout: 10000,
    });
});

test('changes made while a save is in flight are saved afterwards', async ({
    page,
}) => {
    const endpoint = await createFlow(page);
    let delayed = false;
    await page.route('**/editor-state', async (route) => {
        if (!delayed) {
            delayed = true;
            await new Promise((resolve) => setTimeout(resolve, 1200));
        }

        await route.continue();
    });
    const firstSave = page.waitForRequest(
        (request) =>
            request.url().endsWith('/editor-state') &&
            request.method() === 'PUT',
    );
    await page.getByLabel('Nome do fluxo').fill('Primeira alteração');
    await firstSave;
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();
    await page.getByLabel('Nome do fluxo').fill('Alteração mais recente');
    await expect
        .poll(
            async () =>
                (await (await page.request.get(`${endpoint}/assistant`)).json())
                    .data.flow.name,
            { timeout: 10000 },
        )
        .toBe('Alteração mais recente');
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
});

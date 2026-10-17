import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

function documentFlow() {
    return {
        format: 'chait-flow',
        schema_version: '1.0',
        name: 'Nome do arquivo',
        dependencies: {
            tags: [{ id: 'lead', name: 'Importado via JSON' }],
            fields: [],
        },
        nodes: [
            { id: 'start', type: 'start', data: { label: 'Início' } },
            {
                id: 'greeting',
                type: 'content',
                data: {
                    label: 'Boas-vindas importadas',
                    items: [
                        {
                            id: 'text',
                            kind: 'text',
                            text: 'Olá, fluxo importado!',
                        },
                    ],
                },
            },
            {
                id: 'tag',
                type: 'operations',
                data: {
                    label: 'Marcar contato',
                    actions: [{ id: 'mark', kind: 'add_tag', tag_ref: 'lead' }],
                },
            },
        ],
        edges: [
            { source: 'start', output: 'next', target: 'greeting' },
            { source: 'greeting', output: 'next', target: 'tag' },
        ],
    };
}
async function setup(page: Page) {
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({ json: { data: { status: 'connected' } } }),
    );
    await page.route('**/workspaces/*/conversations**', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/workspaces/1/flows');
    const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    const created = await page.request.post('/workspaces/1/flows', {
        headers: { 'X-CSRF-TOKEN': csrf || '', Accept: 'application/json' },
        data: { name: 'Fluxo JSON ' + Date.now() },
    });
    expect(created.ok()).toBeTruthy();
    const flow = (await created.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    await page.goto(endpoint);
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(flow.name);

    return { endpoint, name: flow.name };
}
async function openImport(page: Page) {
    await page
        .getByRole('button', { name: 'Ações do fluxo', exact: true })
        .click();
    const item = page.getByRole('menuitem', { name: 'Importar JSON' });
    await expect(item).toBeEnabled();
    await item.click();
    await expect(
        page.getByRole('dialog', { name: 'Importar JSON' }),
    ).toBeVisible();
}
async function pasteAndValidate(page: Page, document = documentFlow()) {
    await page
        .getByLabel('Conteúdo JSON', { exact: true })
        .fill(JSON.stringify(document));
    await page
        .getByRole('button', { name: 'Validar JSON', exact: true })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Substituir rascunho?' }),
    ).toBeVisible();
}

test('pasted JSON replaces the shared preview, saves and survives reload without publishing', async ({
    page,
}) => {
    const { endpoint, name } = await setup(page);
    await page.getByLabel('Nome do fluxo').fill('Nome de prévia a descartar');
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.session?.name,
        )
        .toBe('Nome de prévia a descartar');
    await openImport(page);
    await pasteAndValidate(page);
    await expect(page.getByRole('dialog')).toContainText('toda a equipe');
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(name);
    await expect(
        page.getByText('Boas-vindas importadas', { exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
    const saved = (
        await (await page.request.get(endpoint + '/assistant')).json()
    ).data;
    expect(saved.session).toBeNull();
    expect(saved.flow.published_version_id).toBeNull();
    await page.reload();
    await expect(
        page.getByText('Boas-vindas importadas', { exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
});

test('invalid input is copyable, cancellation preserves local data, and a JSON file can be imported', async ({
    page,
}) => {
    const { endpoint } = await setup(page);
    await page.getByLabel('Nome do fluxo').fill('Preservar edição');
    await openImport(page);
    await page.getByLabel('Conteúdo JSON', { exact: true }).fill('{invalid');
    await page
        .getByRole('button', { name: 'Validar JSON', exact: true })
        .click();
    await expect(page.getByRole('alert')).toContainText('JSON malformado');
    await expect(
        page.getByRole('button', { name: 'Copiar erros' }),
    ).toBeVisible();
    await page
        .context()
        .grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.getByRole('button', { name: 'Copiar erros' }).click();
    await expect(
        page.getByRole('button', { name: 'Erros copiados' }),
    ).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toContain(
        'JSON malformado',
    );
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(
        'Preservar edição',
    );
    await openImport(page);
    await page.getByLabel('Arquivo JSON').setInputFiles({
        name: 'fluxo.json',
        mimeType: 'application/json',
        buffer: Buffer.from(JSON.stringify(documentFlow())),
    });
    await page
        .getByRole('button', { name: 'Validar JSON', exact: true })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Substituir rascunho?' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
    expect(
        (await (await page.request.get(endpoint + '/assistant')).json()).data
            .flow.graph.nodes,
    ).toHaveLength(3);
});

test('incomplete imported blocks keep their missing settings visible', async ({
    page,
}) => {
    await setup(page);
    await openImport(page);
    const document = {
        format: 'chait-flow',
        schema_version: '1.0',
        name: 'Pendente',
        dependencies: { tags: [], fields: [] },
        nodes: [
            { id: 'start', type: 'start', data: { label: 'Início' } },
            {
                id: 'wait',
                type: 'wait_reply',
                data: { label: 'Resposta pendente' },
            },
            {
                id: 'menu',
                type: 'menu',
                data: {
                    label: 'Menu pendente',
                    options: [{ id: 'opcao', label: null }],
                },
            },
        ],
        edges: [{ source: 'start', output: 'next', target: 'wait' }],
    };
    await page
        .getByLabel('Conteúdo JSON', { exact: true })
        .fill(JSON.stringify(document));
    await page.getByRole('button', { name: 'Validar JSON' }).click();
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByText('Configuração pendente', { exact: true }).first(),
    ).toBeVisible();
    await page.getByText('Resposta pendente', { exact: true }).click();
    await expect(
        page.getByText('Escolha o campo de destino e o prazo de resposta.'),
    ).toBeVisible();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
});

test('downloads use the saved draft and guide excludes AI construction', async ({
    page,
}) => {
    const { name } = await setup(page);
    await page.getByLabel('Nome do fluxo').fill('Nome ainda não salvo');
    await page.getByRole('button', { name: 'Ações do fluxo' }).click();
    await page.getByRole('menuitem', { name: 'Exportar JSON' }).click();
    await expect(
        page.getByRole('dialog', { name: 'Exportar JSON' }),
    ).toContainText('Revise o conteúdo');
    await expect(page.getByLabel('Versão publicada')).toHaveCount(0);
    const pending = page.waitForEvent('download');
    await page
        .getByRole('button', { name: 'Baixar JSON', exact: true })
        .click();
    const download = await pending;
    expect(download.suggestedFilename()).toMatch(/-draft\.json$/);
    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];

    for await (const chunk of stream!) {
        chunks.push(chunk);
    }

    expect(JSON.parse(Buffer.concat(chunks).toString()).name).toBe(name);
    await page.getByRole('button', { name: 'Ações do fluxo' }).click();
    const guidePending = page.waitForEvent('download');
    await page.getByRole('menuitem', { name: 'Baixar guia para IA' }).click();
    const guide = await guidePending;
    expect(guide.suggestedFilename()).toBe('chait-guia-fluxos.md');
});

test('an import reviewed in another tab blocks stale confirmation without replacing that tab', async ({
    page,
    context,
}) => {
    const { endpoint } = await setup(page);
    const other = await context.newPage();
    await other.goto(endpoint);
    await openImport(page);
    await pasteAndValidate(page);
    await other.getByLabel('Nome do fluxo').fill('Alteração remota');
    await expect
        .poll(
            async () =>
                (
                    await (
                        await other.request.get(endpoint + '/assistant')
                    ).json()
                ).data.session?.name,
        )
        .toBe('Alteração remota');
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(page.getByRole('alert')).toContainText(
        'prévia compartilhada mudou',
    );
    const current = (
        await (await page.request.get(endpoint + '/assistant')).json()
    ).data;
    expect(current.flow.graph.nodes).toHaveLength(1);
    expect(current.session.name).toBe('Alteração remota');
    await other.close();
});

test('late polling responses cannot restore the canvas from before import', async ({
    page,
}) => {
    const { endpoint } = await setup(page);
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
    const old = await (await page.request.get(endpoint + '/assistant')).json();
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    let requested: () => void = () => {};
    const waiting = new Promise<void>((resolve) => {
        requested = resolve;
    });
    await page.route(
        '**' + endpoint + '/assistant',
        async (route) => {
            requested();
            await held;
            await route.fulfill({ json: old });
        },
        { times: 1 },
    );
    await waiting;
    await openImport(page);
    await pasteAndValidate(page);
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByText('Boas-vindas importadas', { exact: true }),
    ).toBeVisible();
    release();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible();
    await expect(
        page.getByText('Boas-vindas importadas', { exact: true }),
    ).toBeVisible();
});

for (const viewport of [
    { width: 390, height: 844 },
    { width: 1440, height: 1000 },
]) {
    test(`import dialog is accessible at ${viewport.width}px`, async ({
        page,
    }) => {
        await page.setViewportSize(viewport);
        await setup(page);
        await openImport(page);
        await expect(
            page.getByLabel('Conteúdo JSON', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Cancelar', exact: true }),
        ).toBeInViewport();
        await page.screenshot({
            path: `artifacts/flow-transport-import-${viewport.width}.png`,
            fullPage: true,
            animations: 'disabled',
        });
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog')).not.toBeVisible();
    });
}

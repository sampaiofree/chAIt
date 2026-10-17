import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function fixture(page: Page) {
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
    const headers = { 'X-CSRF-TOKEN': csrf || '', Accept: 'application/json' };
    const created = await page.request.post('/workspaces/1/flows', {
        headers,
        data: { name: `Menu de teste ${Date.now()}` },
    });
    expect(created.status(), await created.text()).toBe(201);
    const flow = (await created.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    const graph = {
        schema_version: 2,
        nodes: [
            {
                id: 'start',
                type: 'start',
                position: { x: 250, y: 20 },
                data: { label: 'Início' },
            },
            {
                id: 'menu',
                type: 'menu',
                position: { x: 250, y: 180 },
                data: {
                    label: 'Escolher atendimento',
                    menu_format: 'numeric',
                    question: 'Como podemos ajudar?',
                    options: [
                        { id: 'sales', label: 'Vendas' },
                        { id: 'support', label: 'Suporte' },
                    ],
                    invalid_reply_text: 'Escolha uma opção válida.',
                    timeout_seconds: 300,
                    max_invalid_replies: 3,
                    list_button_text: 'Ver opções',
                },
            },
            {
                id: 'target',
                type: 'content',
                position: { x: 550, y: 400 },
                data: {
                    label: 'Equipe de vendas',
                    items: [{ id: 'text', kind: 'text', text: 'Olá!' }],
                },
            },
        ],
        edges: [
            {
                id: 'start-menu',
                source: 'start',
                sourceHandle: 'next',
                target: 'menu',
                targetHandle: 'input',
            },
            {
                id: 'sales-target',
                source: 'menu',
                sourceHandle: 'option:sales',
                target: 'target',
                targetHandle: 'input',
            },
        ],
        viewport: { x: 0, y: 0, zoom: 1 },
    };
    const updated = await page.request.put(endpoint, {
        headers,
        data: { graph, name: flow.name, revision: 1 },
    });
    expect(updated.ok()).toBeTruthy();
    await page.goto(endpoint);
    await page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Escolher atendimento' })
        .click();
    await expect(
        page.getByLabel('Pergunta para o contato', { exact: true }),
    ).toBeVisible();

    return { endpoint, headers };
}

test('menu editing preserves connections through reorder, format changes and reload', async ({
    page,
}) => {
    const { endpoint, headers } = await fixture(page);
    await page.getByLabel('Opção 1', { exact: true }).fill('Comercial');
    await page
        .getByRole('button', { name: 'Mover opção 1 para baixo', exact: true })
        .click();
    await expect(page.getByLabel('Opção 2', { exact: true })).toHaveValue(
        'Comercial',
    );
    await page.getByLabel('Formato', { exact: true }).selectOption('list');
    await expect(
        page.getByLabel('Texto do botão de abertura', { exact: true }),
    ).toHaveValue('Ver opções');
    await page.getByLabel('Formato', { exact: true }).selectOption('numeric');
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect
        .poll(async () => {
            const state = (
                await (
                    await page.request.get(`${endpoint}/assistant`, { headers })
                ).json()
            ).data;

            return state.flow.graph.nodes.find(
                (node: { id: string }) => node.id === 'menu',
            ).data.options[1].label;
        })
        .toBe('Comercial');
    await page.reload();
    await page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Escolher atendimento' })
        .click();
    await expect(page.getByLabel('Opção 2', { exact: true })).toHaveValue(
        'Comercial',
    );
    const state = (
        await (
            await page.request.get(`${endpoint}/assistant`, { headers })
        ).json()
    ).data;
    expect(
        state.flow.graph.edges.find(
            (edge: { id: string }) => edge.id === 'sales-target',
        ).sourceHandle,
    ).toBe('option:sales');
});

test('removing a connected option confirms the affected edge and preserves its target', async ({
    page,
}) => {
    const { endpoint, headers } = await fixture(page);
    await page
        .getByRole('button', { name: 'Remover opção 1', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'A conexão desta opção será removida',
    );
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.getByLabel('Opção 1', { exact: true })).toHaveValue(
        'Vendas',
    );
    await page
        .getByRole('button', { name: 'Remover opção 1', exact: true })
        .click();
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect
        .poll(async () => {
            const state = (
                await (
                    await page.request.get(`${endpoint}/assistant`, { headers })
                ).json()
            ).data;

            return state.flow.graph.edges.length;
        })
        .toBe(1);
    const state = (
        await (
            await page.request.get(`${endpoint}/assistant`, { headers })
        ).json()
    ).data;
    expect(
        state.flow.graph.nodes.some(
            (node: { id: string }) => node.id === 'target',
        ),
    ).toBeTruthy();
});

test('menu inline validation and configured wait remain usable with keyboard and responsive themes', async ({
    page,
}, testInfo) => {
    await fixture(page);
    await page
        .locator('summary')
        .filter({ hasText: 'Espera e respostas inválidas' })
        .click();
    await expect(
        page.getByLabel('Tempo máximo de espera', { exact: true }),
    ).toHaveValue('5');
    await page.getByLabel('Unidade do tempo de espera').selectOption('86400');
    await page.getByLabel('Tempo máximo de espera', { exact: true }).fill('7');
    await page
        .getByLabel('Limite de respostas inválidas', { exact: true })
        .fill('10');
    await page.getByLabel('Pergunta para o contato', { exact: true }).fill('');
    await expect(page.locator('#menu-question-error')).toHaveText(
        'Preencha a pergunta para o contato.',
    );
    await page
        .getByLabel('Pergunta para o contato', { exact: true })
        .fill('Qual atendimento você deseja?');
    await page
        .getByRole('button', { name: 'Adicionar opção', exact: true })
        .focus();
    await page.keyboard.press('Enter');
    await expect(page.getByLabel('Opção 3', { exact: true })).toBeVisible();
    await page.getByLabel('Opção 3', { exact: true }).fill('Financeiro');

    for (const theme of ['light', 'dark']) {
        await page.evaluate(
            (value) =>
                document.documentElement.classList.toggle(
                    'dark',
                    value === 'dark',
                ),
            theme,
        );

        for (const width of [390, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect(
                page.getByLabel('Pergunta para o contato', { exact: true }),
            ).toBeVisible();
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        window.innerWidth,
                ),
            ).toBeTruthy();
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(`menu-${theme}-${width}.png`),
            });
        }
    }
});

for (const format of ['button', 'list']) {
    test(`interactive ${format} publishes without connection homologation`, async ({
        page,
    }) => {
        const { endpoint } = await fixture(page);
        await page.getByLabel('Formato', { exact: true }).selectOption(format);
        await page.getByRole('button', { name: 'Salvar', exact: true }).click();
        await expect
            .poll(async () => {
                const state = (
                    await (
                        await page.request.get(`${endpoint}/assistant`)
                    ).json()
                ).data;
                return state.session === null;
            })
            .toBeTruthy();
        await page
            .getByRole('button', { name: 'Publicar', exact: true })
            .click();
        await expect(page.getByRole('dialog')).toContainText('Fluxo publicado');
        await expect(page.locator('.menu-config [role="alert"]')).toHaveCount(
            0,
        );
        await expect(page.locator('.menu-config')).not.toContainText(
            'homologação',
        );
    });
}

test('menu option add connector creates a visible separate block with its stable handle', async ({
    page,
}) => {
    const { endpoint } = await fixture(page);
    await page
        .getByRole('button', {
            name: 'Adicionar bloco após 2. Suporte',
            exact: true,
        })
        .click();
    await page.getByRole('button', { name: 'Conteúdo', exact: true }).click();
    await expect
        .poll(async () => {
            const state = (
                await (await page.request.get(`${endpoint}/assistant`)).json()
            ).data;

            return state.session?.graph.edges.some(
                (edge: { sourceHandle: string }) =>
                    edge.sourceHandle === 'option:support',
            );
        })
        .toBeTruthy();
    const state = (
        await (await page.request.get(`${endpoint}/assistant`)).json()
    ).data;
    const graph = state.session.graph;
    const targetId = graph.edges.find(
        (edge: { sourceHandle: string }) =>
            edge.sourceHandle === 'option:support',
    ).target;
    const target = graph.nodes.find(
        (node: { id: string }) => node.id === targetId,
    );
    const menu = graph.nodes.find((node: { id: string }) => node.id === 'menu');
    expect(target.position.y).toBeGreaterThan(menu.position.y + 300);
    expect(target.position.x).toBeGreaterThan(menu.position.x + 200);
});

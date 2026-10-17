import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { AssistantState } from '../../resources/js/composables/useFlowAssistant';

async function login(page: Page) {
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
}
async function createFlow(page: Page) {
    await page.goto('/workspaces/1/flows');
    const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    const response = await page.request.post('/workspaces/1/flows', {
        headers: { 'X-CSRF-TOKEN': csrf ?? '', Accept: 'application/json' },
        data: { name: 'Assistente ' + Date.now() },
    });
    expect(response.ok()).toBe(true);
    const flow = (await response.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    await page.goto(endpoint);
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(flow.name);
    await expect
        .poll(async () =>
            (await page.request.get(endpoint + '/assistant')).status(),
        )
        .toBe(200);

    return { endpoint, name: flow.name };
}

test('temporary edits recover after reload and explicit discard returns to the saved draft', async ({
    page,
}) => {
    await login(page);
    const { endpoint, name } = await createFlow(page);
    await page.getByLabel('Nome do fluxo').fill('Prévia recuperável');
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.session?.name,
        )
        .toBe('Prévia recuperável');
    expect(
        (await (await page.request.get(endpoint + '/assistant')).json()).data
            .flow.name,
    ).toBe(name);
    await page.reload();
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(
        'Prévia recuperável',
    );
    await page
        .getByRole('button', { name: 'Descartar alterações', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'Isso afeta toda a equipe',
    );
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(name);
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.session,
        )
        .toBeNull();
});

test('shared preview propagates between tabs and saving closes the temporary generation', async ({
    page,
    context,
}) => {
    await login(page);
    const { endpoint } = await createFlow(page);
    const other = await context.newPage();
    await other.goto(endpoint);
    await page.getByLabel('Nome do fluxo').fill('Prévia da equipe');
    await expect(other.getByLabel('Nome do fluxo')).toHaveValue(
        'Prévia da equipe',
        { timeout: 12000 },
    );
    await other.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.flow.name,
        )
        .toBe('Prévia da equipe');
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.session,
        )
        .toBeNull();
    await expect(page.getByText('Todas as alterações salvas')).toBeVisible({
        timeout: 12000,
    });
    await other.close();
});

test('floating assistant handles approval, progress, interruption and responsive themes', async ({
    page,
}, testInfo) => {
    await login(page);
    const { endpoint } = await createFlow(page);
    const initial: AssistantState = (
        await (await page.request.get(endpoint + '/assistant')).json()
    ).data;
    let current: AssistantState = {
        ...initial,
        configured: true,
        can_use_ai: true,
        conversation: { state: 'awaiting_plan_approval', model: 'auto' },
        messages: [
            {
                id: 1,
                role: 'user',
                content: 'Crie um atendimento para interessados em cursos.',
                kind: 'message',
                status: null,
                approved_at: null,
                user_id: 1,
                created_at: new Date().toISOString(),
            },
            {
                id: 2,
                role: 'assistant',
                content:
                    'Vamos receber o interessado com uma mensagem de boas-vindas e orientar a equipe a continuar o atendimento. Revise o plano e clique Construir.',
                kind: 'plan',
                status: 'pending',
                approved_at: null,
                user_id: null,
                created_at: new Date().toISOString(),
            },
        ],
    };
    await page.route('**' + endpoint + '/assistant**', async (route) => {
        const path = new URL(route.request().url()).pathname;

        if (path.endsWith('/models')) {
            return route.fulfill({
                json: { data: ['gpt-6.1-sol', 'gpt-6-luna'] },
            });
        }

        if (path.endsWith('/approve')) {
            current = {
                ...current,
                conversation: { state: 'building', model: 'auto' },
                session: {
                    generation: 'preview',
                    base_revision: initial.flow.revision,
                    revision: 2,
                    name: initial.flow.name,
                    graph: {
                        ...initial.flow.graph,
                        nodes: [
                            ...initial.flow.graph.nodes,
                            {
                                id: 'welcome',
                                type: 'content',
                                position: { x: 100, y: 280 },
                                data: {
                                    label: 'Boas-vindas',
                                    items: [
                                        {
                                            id: 'hello',
                                            kind: 'text',
                                            text: 'Olá! Que bom receber você. Conheça nossos cursos.',
                                        },
                                    ],
                                },
                            },
                        ],
                        edges: [
                            {
                                id: 'welcome-edge',
                                source: initial.flow.graph.nodes[0].id,
                                sourceHandle: 'next',
                                target: 'welcome',
                                targetHandle: 'input',
                            },
                        ],
                    },
                },
                run: {
                    id: 1,
                    status: 'running',
                    purpose: 'building',
                    error: null,
                },
            };
            current.messages[1] = {
                ...current.messages[1],
                status: 'approved',
                approved_at: new Date().toISOString(),
            };
        }

        if (path.endsWith('/stop')) {
            current = {
                ...current,
                run: {
                    id: 1,
                    status: 'paused',
                    purpose: 'building',
                    error: 'Execução interrompida pela equipe.',
                },
            };
        }

        return route.fulfill({ json: { data: current } });
    });
    await page.reload();
    await page
        .getByRole('button', { name: 'Assistente IA', exact: true })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Assistente IA' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Construir', exact: true }),
    ).toBeVisible();
    await expect(page.locator('.vue-flow__node')).toHaveCount(1);
    await page.getByRole('button', { name: 'Construir', exact: true }).click();
    await expect(page.locator('.vue-flow__node')).toHaveCount(2);
    await expect(page.getByLabel('Nome do fluxo')).toBeDisabled();
    await expect(
        page.getByRole('button', { name: 'Salvar', exact: true }),
    ).toBeDisabled();
    await page.getByRole('button', { name: 'Parar', exact: true }).click();
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();
    await expect(
        page.getByRole('button', { name: 'Retomar', exact: true }),
    ).toBeVisible();

    for (const width of [1440, 1280, 1024, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });

        for (const dark of [false, true]) {
            await page.evaluate(
                (value) =>
                    document.documentElement.classList.toggle('dark', value),
                dark,
            );
            await expect(
                page.getByRole('dialog', { name: 'Assistente IA' }),
            ).toBeVisible();
            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            ).toBe(true);
            await page.screenshot({
                path: testInfo.outputPath(
                    `assistant-${width}-${dark ? 'dark' : 'light'}.png`,
                ),
            });
        }
    }

    await page.keyboard.press('Escape');
    await expect(
        page.getByRole('dialog', { name: 'Assistente IA' }),
    ).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Assistente IA', exact: true }),
    ).toBeFocused();
});

test('reload before debounce recovers the last unsynchronized keystrokes', async ({
    page,
}) => {
    await login(page);
    const { endpoint } = await createFlow(page);
    await page.route('**' + endpoint + '/draft-session', async (route) => {
        if (route.request().method() === 'PUT') {
            await route.abort();
        } else {
            await route.continue();
        }
    });
    await page
        .getByLabel('Nome do fluxo')
        .fill('Últimas digitações recuperadas');
    await expect
        .poll(() =>
            page.evaluate(() =>
                Object.keys(sessionStorage)
                    .filter((key) => key.startsWith('flow-preview:'))
                    .map(
                        (key) =>
                            JSON.parse(sessionStorage.getItem(key) || '{}')
                                .snapshot?.name,
                    )
                    .includes('Últimas digitações recuperadas'),
            ),
        )
        .toBe(true);
    await page.reload();
    await expect(page.getByLabel('Nome do fluxo')).toHaveValue(
        'Últimas digitações recuperadas',
    );
    await page.unroute('**' + endpoint + '/draft-session');
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect
        .poll(
            async () =>
                (await (await page.request.get(endpoint + '/assistant')).json())
                    .data.flow.name,
        )
        .toBe('Últimas digitações recuperadas');
});

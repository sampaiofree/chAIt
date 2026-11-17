import { test, expect } from '@playwright/test';

test('AI outputs can be edited and removed while Continue stays fixed', async ({
    page,
}) => {
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
        data: { name: 'Saídas IA' },
    });
    const flow = (await created.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    const token = crypto.randomUUID();
    await page.request.post(`${endpoint}/edit-lock`, {
        headers,
        data: { token },
    });
    const graph = flow.graph;
    graph.nodes.push({
        id: 'ai',
        type: 'ai',
        position: { x: 250, y: 180 },
        data: {
            label: 'Conversa de teste',
            ai_prompt: 'Atenda o cliente.',
            ai_model: 'gpt-6.1-sol',
            ai_inactivity_seconds: 3600,
            ai_outputs: [
                {
                    id: 'done',
                    label: 'Atendimento finalizado',
                    instruction: 'Quando concluir.',
                },
                {
                    id: 'human',
                    label: 'Atendimento humano',
                    instruction: 'Quando solicitado.',
                },
            ],
        },
    });
    graph.edges.push({
        id: 'enter',
        source: graph.nodes[0].id,
        sourceHandle: 'next',
        target: 'ai',
        targetHandle: 'input',
    });
    const updated = await page.request.put(endpoint, {
        headers: { ...headers, 'X-Flow-Edit-Token': token },
        data: { graph, name: flow.name, revision: 1 },
    });
    expect(updated.ok(), await updated.text()).toBeTruthy();
    await page.request.delete(`${endpoint}/edit-lock`, {
        headers,
        data: { token },
    });
    await page.goto(endpoint);
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();
    await page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Conversa de teste' })
        .click();
    await expect(
        page.getByText('Caminhos da conversa', { exact: true }),
    ).toBeVisible();
    await page.locator('.ai-path').first().locator('summary').click();
    await page
        .getByLabel('Nome do caminho', { exact: true })
        .first()
        .fill('Resolvido');
    await page
        .getByRole('button', { name: 'Adicionar caminho', exact: true })
        .click();
    await expect(
        page.getByLabel('Nome do caminho', { exact: true }),
    ).toHaveCount(3);
    await page
        .getByLabel('Nome do caminho', { exact: true })
        .last()
        .fill('Vendas');
    await page
        .getByLabel('Quando seguir este caminho?', { exact: true })
        .last()
        .fill('Quando houver interesse em comprar.');
    await page.screenshot({
        path: 'artifacts/ai-outputs-desktop.png',
        fullPage: true,
    });

    for (let i = 0; i < 3; i++) {
        await page
            .locator('.ai-path')
            .first()
            .evaluate((el) => {
                (el as HTMLDetailsElement).open = true;
            });
        await page
            .getByRole('button', { name: 'Remover saída', exact: true })
            .first()
            .click();
    }

    await expect(
        page.getByLabel('Nome do caminho', { exact: true }),
    ).toHaveCount(0);
    await expect(
        page
            .locator('.vue-flow__node')
            .filter({ hasText: 'Conversa de teste' })
            .locator('.node-exit')
            .filter({ hasText: 'Continuar' }),
    ).toBeVisible();
    await expect
        .poll(async () => {
            const state = (
                await (await page.request.get(`${endpoint}/assistant`)).json()
            ).data;

            return state.flow.graph.nodes.find(
                (n: { id: string }) => n.id === 'ai',
            )?.data.ai_outputs;
        })
        .toEqual([]);
});

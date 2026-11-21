import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function createAiFlow(page: Page, name: string, includeWait = false) {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    const flowsUrl = await page
        .getByRole('link', { name: 'Fluxos', exact: true })
        .getAttribute('href');
    expect(flowsUrl).toMatch(/\/workspaces\/\d+\/flows$/);
    await page.goto(flowsUrl!);
    const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': csrf || '', Accept: 'application/json' };
    const created = await page.request.post(flowsUrl!, {
        headers,
        data: { name },
    });
    expect(created.ok(), await created.text()).toBeTruthy();
    const flow = (await created.json()).data;
    const endpoint = `${flowsUrl}/${flow.id}`;
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
    if (includeWait) {
        graph.nodes.push({
            id: 'wait',
            type: 'wait_reply',
            position: { x: 250, y: 480 },
            data: { label: 'Aguardar Resposta', timeout_seconds: 86400 },
        });
    }
    graph.edges.push({
        id: 'enter',
        source: graph.nodes[0].id,
        sourceHandle: 'next',
        target: 'ai',
        targetHandle: 'input',
    });
    const updated = await page.request.put(endpoint, {
        headers: { ...headers, 'X-Flow-Edit-Token': token },
        data: { graph, name: flow.name, revision: flow.revision },
    });
    expect(updated.ok(), await updated.text()).toBeTruthy();
    await page.request.delete(`${endpoint}/edit-lock`, {
        headers,
        data: { token },
    });
    await page.goto(endpoint);
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();

    return endpoint;
}

function aiNode(page: Page) {
    return page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Conversa de teste' });
}

test('AI outputs can be edited and removed while Continue stays fixed', async ({
    page,
}, testInfo) => {
    const endpoint = await createAiFlow(page, 'Saídas IA');
    await aiNode(page).click();
    await page.getByRole('tab', { name: 'Saídas e recursos', exact: true }).click();
    await page.locator('.ai-path').first().locator('summary').click();
    await page
        .getByLabel('Nome do caminho', { exact: true })
        .first()
        .fill('Resolvido');
    const addPath = page.getByRole('button', {
        name: 'Adicionar caminho',
        exact: true,
    });
    await expect(addPath).toHaveCSS('border-top-style', 'solid');
    expect(await addPath.evaluate((button) =>
        parseFloat(getComputedStyle(button).borderTopWidth),
    )).toBeGreaterThan(0);
    await addPath.click();
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
        path: testInfo.outputPath('ai-outputs-desktop.png'),
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
    await page.getByRole('button', { name: 'Fechar painel' }).click();
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

for (const width of [1440, 1024, 768, 390, 320]) {
    test(`AI opens in the block panel and expands at ${width}px`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await createAiFlow(page, `Contexto IA ${width}`);
        const viewport = page.locator('.vue-flow__transformationpane');
        const position = await viewport.evaluate((element) => getComputedStyle(element).transform);
        await aiNode(page).click();
        const panel = page.locator('.settings-panel');
        await expect(panel).toBeVisible();
        await expect(panel).not.toHaveClass(/settings-panel--ai/);
        const history = page.getByRole('checkbox', { name: 'Carregar histórico da conversa' });
        await history.check();
        await expect(page.getByRole('textbox', { name: 'O que a IA deve fazer?' })).toBeVisible();
        await page.getByRole('button', { name: 'Expandir configuração' }).click();
        await expect(panel).toHaveClass(/settings-panel--ai/);
        await expect(history).toBeChecked();
        await page.getByRole('button', { name: 'Salvar alterações' }).click();
        await expect(page.getByText('Todas as alterações salvas').filter({ visible: true })).toBeVisible();
        await page.getByRole('button', { name: 'Voltar ao painel' }).click();
        await expect(history).toBeChecked();
        await expect(page.getByRole('button', { name: 'Expandir configuração' })).toBeFocused();
        for (const theme of ['light', 'dark']) {
            await page.emulateMedia({ colorScheme: theme === 'dark' ? 'dark' : 'light' });
            await expect(page.locator('html')).toHaveCSS('color-scheme', theme);
            await page.screenshot({ path: testInfo.outputPath(`ai-panel-${width}-${theme}.png`), animations: 'disabled' });
        }
        await page.getByRole('button', { name: 'Fechar painel' }).click();
        await expect(panel).toBeHidden();
        await expect.poll(() => viewport.evaluate((element) => getComputedStyle(element).transform)).toBe(position);
    });
}

test('message grouping settings persist in the AI and wait reply blocks', async ({ page }) => {
    await createAiFlow(page, 'Intervalo de agrupamento', true);
    const interval = page.getByLabel('Intervalo para agrupar mensagens (segundos)');
    const settings = page.getByRole('tab', { name: 'Configurações', exact: true });

    await aiNode(page).click();
    await settings.click();
    await expect(interval).toHaveValue('5');
    await interval.fill('90');
    await expect(page.getByText('Todas as alterações salvas').filter({ visible: true })).toBeVisible();
    await page.getByRole('button', { name: 'Fechar painel' }).click();

    await page.locator('.flow-node.node-wait_reply').click();
    await expect(interval).toHaveValue('5');
    await interval.fill('1');
    await expect(page.getByText('Todas as alterações salvas').filter({ visible: true })).toBeVisible();

    await page.reload();
    await expect(page.getByLabel('Nome do fluxo')).toBeEnabled();
    await page.locator('.flow-node.node-wait_reply').click();
    await expect(interval).toHaveValue('1');
    await page.getByRole('button', { name: 'Fechar painel' }).click();
    await aiNode(page).click();
    await settings.click();
    await expect(interval).toHaveValue('90');
});

test('expanded AI instructions preserve the draft and restore focus on mobile', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await createAiFlow(page, 'Instruções ampliadas');
    await aiNode(page).click();

    const instructions = page.getByRole('textbox', { name: 'O que a IA deve fazer?' });
    await instructions.click();
    await expect(page.getByRole('textbox', { name: 'Pesquisar campo do contato' })).toHaveCount(0);
    await instructions.fill('');
    await page.getByRole('button', { name: 'Preencha as instruções da IA.' }).click();
    await expect(instructions).toBeFocused();
    await instructions.fill('Atenda o cliente.');

    const expand = page.getByRole('button', { name: 'Expandir instruções' });
    await expand.click();
    const dialog = page.getByRole('dialog', { name: 'Instruções da IA' });
    await expect(dialog).toBeVisible();
    const prompt = dialog.getByRole('textbox', { name: 'O que a IA deve fazer?' });
    await expect(prompt).toHaveText('Atenda o cliente.');
    await prompt.fill('Atenda com clareza.');
    await page.keyboard.press('Escape');

    await expect(dialog).toBeHidden();
    await expect(expand).toBeFocused();
    await expect(page.getByRole('textbox', { name: 'O que a IA deve fazer?' })).toHaveText(
        'Atenda com clareza.',
    );
    await page.getByRole('button', { name: 'Fechar painel' }).click();
    await expect(page.getByText('Todas as alterações salvas').filter({ visible: true })).toBeVisible();
});


test('resource titles and inactivity survive autosave', async ({ page }) => {
    const endpoint = await createAiFlow(page, 'Recursos e prazo');
    await aiNode(page).click();
    await page.getByRole('tab', { name: 'Saídas e recursos', exact: true }).click();
    await page.getByRole('button', { name: 'Autorizar campo' }).click();
    await page.getByRole('combobox', { name: 'Campo autorizado 1' }).selectOption({ label: 'Curso IA' });
    const resource = page.locator('details.ai-resource').filter({ hasText: 'Curso IA' });
    if (!(await resource.evaluate((el) => (el as HTMLDetailsElement).open))) {
        await resource.locator('summary').click();
    }
    await resource.getByLabel('Título interno (opcional)').fill('Interesse no curso');
    await page.getByRole('tab', { name: 'Configurações', exact: true }).click();
    const unit = page.getByLabel('Unidade de inatividade');
    const duration = page.getByRole('textbox', { name: 'Tempo de inatividade', exact: true });
    await unit.selectOption({ label: 'Segundos' });
    await expect(duration).toHaveValue('3600');
    await duration.fill('301');
    await unit.selectOption({ label: 'Minutos' });
    await expect(page.getByText('301 segundos', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Sobre Limite de mensagens do contato' }).click();
    await expect(page.getByText(/mesmo quando chegam agrupadas/)).toBeVisible();

    await expect.poll(async () => {
        const { data } = await (await page.request.get(`${endpoint}/assistant`)).json();
        const ai = data.flow.graph.nodes.find((node: { id: string }) => node.id === 'ai').data;
        return [ai.ai_inactivity_seconds, ai.ai_fields?.[0]?.title];
    }).toEqual([301, 'Interesse no curso']);
});

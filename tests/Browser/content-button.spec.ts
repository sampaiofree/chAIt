import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

type Item = {
    id: string;
    kind: string;
    text?: string;
    seconds?: number;
    button?: { kind: string; label: string; url?: string; copy_text?: string };
};
async function fixture(page: Page, complete = false) {
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
        data: { name: `Conteúdo com botão ${Date.now()}` },
    });
    expect(created.status()).toBe(201);
    const flow = (await created.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    const items: Item[] = [
        { id: 'intro', kind: 'text', text: 'Veja nossos cursos.' },
    ];

    if (complete) {
        items.push(
            { id: 'delay', kind: 'delay', seconds: 3 },
            {
                id: 'button',
                kind: 'button',
                text: 'Quer conhecer os cursos disponíveis?',
                button: { kind: 'reply', label: 'Tenho interesse' },
            },
        );
    }

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
                id: 'content',
                type: 'content',
                position: { x: 250, y: 180 },
                data: { label: 'Apresentar cursos', items },
            },
        ],
        edges: [
            {
                id: 'begin',
                source: 'start',
                sourceHandle: 'next',
                target: 'content',
                targetHandle: 'input',
            },
        ],
        viewport: { x: 0, y: 0, zoom: 1 },
    };
    const saved = await page.request.put(endpoint, {
        headers,
        data: { graph, name: flow.name, revision: 1 },
    });
    expect(saved.ok()).toBeTruthy();
    await page.goto(endpoint);
    await page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Apresentar cursos' })
        .click();
    await expect(
        page.getByRole('button', { name: 'Botão', exact: true }),
    ).toBeVisible();
    const state = async () =>
        (
            await (
                await page.request.get(`${endpoint}/assistant`, { headers })
            ).json()
        ).data;
    const contentItems = async (): Promise<Item[]> =>
        (await state()).flow.graph.nodes.find(
            (node: { id: string }) => node.id === 'content',
        ).data.items;
    const save = async () => {
        await page.getByRole('button', { name: 'Salvar', exact: true }).click();
        await expect
            .poll(async () => (await state()).session === null)
            .toBeTruthy();
    };

    return { endpoint, state, contentItems, save };
}

test('content button supports all kinds reorder save recovery and removal while retaining its identity', async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Botão', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Botão', exact: true }),
    ).toBeDisabled();
    await page
        .getByLabel('Texto da mensagem', { exact: true })
        .fill('Conheça os cursos.');
    await page.getByLabel('Texto do botão', { exact: true }).fill('Ver cursos');
    await page
        .getByLabel('Endereço URL', { exact: true })
        .fill('https://example.com/cursos');
    await expect(page.getByLabel('Prévia do elemento Botão')).toContainText(
        'Ver cursos',
    );
    await f.save();
    const id = (await f.contentItems())[1].id;
    await page
        .getByLabel('Tipo de botão', { exact: true })
        .selectOption('reply');
    await expect(page.getByLabel('Endereço URL', { exact: true })).toHaveCount(
        0,
    );
    await expect(
        page.getByText('O clique envia uma resposta do contato.', {
            exact: false,
        }),
    ).toBeVisible();
    await page
        .getByLabel('Tipo de botão', { exact: true })
        .selectOption('copy');
    await page.getByLabel('Texto para copiar', { exact: true }).fill('CUPOM10');
    await page
        .locator('.message-item')
        .filter({ has: page.locator('.content-button-editor') })
        .getByRole('button', { name: 'Mover para cima', exact: true })
        .click();
    await f.save();
    const items = await f.contentItems();
    expect(items[0]).toMatchObject({
        id,
        kind: 'button',
        button: { kind: 'copy', label: 'Ver cursos', copy_text: 'CUPOM10' },
    });
    expect(items[0].button?.url).toBeUndefined();
    expect(items[1]).toMatchObject({
        id: 'intro',
        text: 'Veja nossos cursos.',
    });
    await page.reload();
    await page
        .locator('.vue-flow__node')
        .filter({ hasText: 'Apresentar cursos' })
        .click();
    await expect(
        page.getByLabel('Texto para copiar', { exact: true }),
    ).toHaveText('CUPOM10');
    await page
        .locator('.message-item')
        .filter({ has: page.locator('.content-button-editor') })
        .getByRole('button', { name: 'Remover item 1', exact: true })
        .click();
    await expect(
        page.getByRole('button', { name: 'Botão', exact: true }),
    ).toBeEnabled();
    await f.save();
    expect(await f.contentItems()).toEqual([
        { id: 'intro', kind: 'text', text: 'Veja nossos cursos.' },
    ]);
});

test('content button validates near fields and can be added by keyboard', async ({
    page,
}) => {
    await fixture(page);
    await page.getByRole('button', { name: 'Botão', exact: true }).focus();
    await page.keyboard.press('Enter');
    await page
        .getByLabel('Texto do botão', { exact: true })
        .fill('Inválido|outro');
    await expect(
        page.locator('.content-button-editor [role="alert"]'),
    ).toContainText([
        'Preencha o texto da mensagem.',
        'Use uma linha, sem o caractere |.',
        'Informe um endereço completo',
    ]);
    await page
        .getByLabel('Texto da mensagem', { exact: true })
        .fill('Conheça os cursos.');
    await page.getByLabel('Texto do botão', { exact: true }).fill('Ver cursos');
    await page
        .getByLabel('Endereço URL', { exact: true })
        .fill('https://example.com/cursos');
    await expect(
        page.locator('.content-button-editor [role="alert"]'),
    ).toHaveCount(0);
    await page
        .getByLabel('Tipo de botão', { exact: true })
        .selectOption('reply');
    await expect(
        page.getByLabel('Texto para copiar', { exact: true }),
    ).toHaveCount(0);
});

test('content button publishes without connection homologation', async ({
    page,
}) => {
    await fixture(page, true);
    await page.getByRole('button', { name: 'Publicar', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('Fluxo publicado');
    await expect(
        page.locator('.content-button-editor [role="alert"]'),
    ).toHaveCount(0);
    await expect(page.locator('.content-button-editor')).not.toContainText(
        'aguarda homologação',
    );
});

test('content button remains readable in responsive light and dark themes with long text', async ({
    page,
}, testInfo) => {
    await fixture(page, true);
    await page
        .getByLabel('Texto da mensagem', { exact: true })
        .fill(
            'Conheça nossos cursos profissionalizantes. Escolha o melhor caminho para o seu futuro.',
        );
    await page
        .getByLabel('Texto do botão', { exact: true })
        .fill('Quero conhecer os cursos');

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
            await page
                .getByLabel('Tipo de botão', { exact: true })
                .scrollIntoViewIfNeeded();
            await expect(
                page.getByLabel('Tipo de botão', { exact: true }),
            ).toBeVisible();
            await expect(
                page.getByLabel('Texto do botão', { exact: true }),
            ).toBeVisible();
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        window.innerWidth,
                ),
            ).toBeTruthy();
            expect(
                await page
                    .locator('.content-button-editor')
                    .evaluate(
                        (element) => element.scrollWidth <= element.clientWidth,
                    ),
            ).toBeTruthy();
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(
                    `content-button-${theme}-${width}.png`,
                ),
            });
            await page
                .getByLabel('Prévia do elemento Botão')
                .scrollIntoViewIfNeeded();
            const contrast = await page
                .locator('.preview-action')
                .evaluate((element) => {
                    const luminance = (color: string) => {
                        const rgb = color
                            .match(/\d+(?:\.\d+)?/g)!
                            .slice(0, 3)
                            .map(Number)
                            .map((n) => n / 255);
                        const linear = rgb.map((n) =>
                            n <= 0.04045
                                ? n / 12.92
                                : ((n + 0.055) / 1.055) ** 2.4,
                        );

                        return (
                            linear[0] * 0.2126 +
                            linear[1] * 0.7152 +
                            linear[2] * 0.0722
                        );
                    };
                    const foreground = luminance(
                        getComputedStyle(element).color,
                    );
                    const background = luminance(
                        getComputedStyle(element.closest('.button-preview')!)
                            .backgroundColor,
                    );

                    return (
                        (Math.max(foreground, background) + 0.05) /
                        (Math.min(foreground, background) + 0.05)
                    );
                });
            expect(contrast).toBeGreaterThanOrEqual(4.5);
            await expect(
                page.getByLabel('Prévia do elemento Botão'),
            ).toBeVisible();
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(
                    `content-button-preview-${theme}-${width}.png`,
                ),
            });
        }
    }
});

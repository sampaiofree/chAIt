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

const browserName = process.env.CHAIT_BROWSER_ENGINE || 'chromium';
{
    test.describe(`contact templates ${browserName}`, () => {
        test('cursor atomic chips undo redo literal paste formatting and saved recovery', async ({
            page,
        }) => {
            const f = await fixture(page);
            const input = page.getByRole('textbox', {
                name: 'Texto da mensagem 1',
                exact: true,
            });
            await input.fill('Olá ');
            await input.press('End');
            const field = input.locator('..');
            await field
                .getByRole('button', { name: 'Inserir campo', exact: true })
                .click();
            await field
                .getByRole('button', { name: 'Nome', exact: true })
                .click();
            await expect(input.locator('.contact-chip')).toHaveText('Nome');
            await input.press('Backspace');
            await expect(input.locator('.contact-chip')).toHaveCount(0);
            await input.press('ControlOrMeta+z');
            await expect(input.locator('.contact-chip')).toHaveCount(1);
            await input.press('ControlOrMeta+Shift+z');
            await expect(input.locator('.contact-chip')).toHaveCount(0);
            await input.press('ControlOrMeta+z');
            await input.press('End');
            await input.pressSequentially(' {nome} ação');
            await expect(input).toContainText('{nome} ação');
            await expect(input.locator('.contact-chip')).toHaveCount(1);
            await input.press('ControlOrMeta+a');
            const clipboard = await input.evaluate((el) => {
                const dt = new DataTransfer();
                el.dispatchEvent(
                    new ClipboardEvent('copy', {
                        clipboardData: dt,
                        bubbles: true,
                        cancelable: true,
                    }),
                );

                return {
                    structured: dt.getData(
                        'application/x-chait-contact-template+json',
                    ),
                    text: dt.getData('text/plain'),
                };
            });
            expect(clipboard.structured).toContain('standard');
            await input.press('ArrowRight');
            await input.evaluate((el, data) => {
                const dt = new DataTransfer();
                dt.setData('text/plain', data.text);
                dt.setData(
                    'application/x-chait-contact-template+json',
                    data.structured,
                );
                el.dispatchEvent(
                    new ClipboardEvent('paste', {
                        clipboardData: dt,
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            }, clipboard);
            await expect(input.locator('.contact-chip')).toHaveCount(2);
            await input.press('ControlOrMeta+z');
            await expect(input.locator('.contact-chip')).toHaveCount(1);
            await input.evaluate((el) => {
                const dt = new DataTransfer();
                dt.setData('text/plain', '{telefone}');
                dt.setData('text/html', '<img src=x onerror=alert(1)>');
                el.dispatchEvent(
                    new ClipboardEvent('paste', {
                        clipboardData: dt,
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            });
            await expect(input).toContainText('{telefone}');
            await expect(input.locator('img')).toHaveCount(0);
            await input.press('ControlOrMeta+a');
            await page
                .getByRole('button', { name: 'Negrito', exact: true })
                .click();
            await expect(input).toContainText('*Olá');
            await expect(input.locator('.contact-chip')).toHaveCount(1);
            await f.save();
            const saved = await f.contentItems();
            expect(typeof saved[0].text).toBe('object');
            await page.reload();
            await page
                .locator('.vue-flow__node')
                .filter({ hasText: 'Apresentar cursos' })
                .click();
            await expect(
                page
                    .getByRole('textbox', {
                        name: 'Texto da mensagem 1',
                        exact: true,
                    })
                    .locator('.contact-chip'),
            ).toHaveText('Nome');
            expect(await f.contentItems()).toEqual(saved);
        });
        test('partial chip selection delete and composition preserve atomic editing', async ({
            page,
        }) => {
            await fixture(page);
            const input = page.getByRole('textbox', {
                name: 'Texto da mensagem 1',
                exact: true,
            });
            await input.fill('A');
            await input.press('End');
            await input.evaluate((el) => {
                el.dispatchEvent(
                    new CompositionEvent('compositionstart', { bubbles: true }),
                );
                el.append(document.createTextNode('ção'));
                el.dispatchEvent(
                    new InputEvent('input', {
                        bubbles: true,
                        inputType: 'insertCompositionText',
                        data: 'ção',
                        isComposing: true,
                    }),
                );
                el.dispatchEvent(
                    new CompositionEvent('compositionend', {
                        bubbles: true,
                        data: 'ção',
                    }),
                );
            });
            await expect(input).toHaveText('Ação');
            await input.press('ControlOrMeta+z');
            await expect(input).toHaveText('A');
            await input.press('End');
            const field = input.locator('..');
            await field
                .getByRole('button', { name: 'Inserir campo', exact: true })
                .click();
            await field
                .getByRole('button', { name: 'Telefone', exact: true })
                .click();
            await input.evaluate((el) => {
                const text = el.querySelector('.contact-chip')!.firstChild!;
                const range = document.createRange();
                range.setStart(text, 2);
                range.setEnd(text, 4);
                const selection = window.getSelection()!;
                selection.removeAllRanges();
                selection.addRange(range);
            });
            await input.press('Delete');
            await expect(input.locator('.contact-chip')).toHaveCount(0);
            await input.press('ControlOrMeta+z');
            await expect(input.locator('.contact-chip')).toHaveCount(1);
        });
        test('cross workspace clipboard and reply conversion give a local explanation', async ({
            page,
        }) => {
            await fixture(page);
            await page
                .getByRole('button', { name: 'Botão', exact: true })
                .click();
            const input = page.getByRole('textbox', {
                name: 'Texto da mensagem',
                exact: true,
            });
            await input.fill('Olá ');
            await input.press('End');
            const field = input.locator('..');
            await field
                .getByRole('button', { name: 'Inserir campo', exact: true })
                .click();
            await field
                .getByRole('button', { name: 'Primeiro nome', exact: true })
                .click();
            await page
                .getByLabel('Tipo de botão', { exact: true })
                .selectOption('reply');
            await expect(
                page.getByRole('alert').filter({ hasText: 'Remova os campos' }),
            ).toContainText('Remova os campos');
            await expect(
                page.getByLabel('Tipo de botão', { exact: true }),
            ).toHaveValue('url');
            await input.evaluate((el) => {
                const dt = new DataTransfer();
                dt.setData(
                    'application/x-chait-contact-template+json',
                    JSON.stringify({
                        workspace_id: 99999,
                        parts: [{ type: 'standard', key: 'nome' }],
                    }),
                );
                el.dispatchEvent(
                    new ClipboardEvent('paste', {
                        clipboardData: dt,
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            });
            await expect(field.getByRole('alert')).toContainText(
                'outro workspace',
            );
            await expect(input.locator('.contact-chip')).toHaveCount(1);
        });
        test('contact fields remain usable at project widths in light and dark themes', async ({
            page,
        }) => {
            await fixture(page);
            const input = page.getByRole('textbox', {
                name: 'Texto da mensagem 1',
                exact: true,
            });
            await input.fill('Olá ');
            await input.press('End');
            const field = input.locator('..');
            await field
                .getByRole('button', { name: 'Inserir campo', exact: true })
                .click();
            await field
                .getByRole('button', { name: 'Curso IA', exact: false })
                .click();

            for (const theme of ['light', 'dark']) {
                await page.evaluate((t) => {
                    document.documentElement.classList.toggle(
                        'dark',
                        t === 'dark',
                    );
                }, theme);

                for (const width of [390, 768, 1024, 1280, 1440]) {
                    await page.setViewportSize({ width, height: 900 });
                    await input.scrollIntoViewIfNeeded();
                    await expect(input).toBeVisible();
                    expect(
                        await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                window.innerWidth,
                        ),
                    ).toBeTruthy();
                    await field
                        .getByRole('button', {
                            name: 'Inserir campo',
                            exact: true,
                        })
                        .click();
                    const picker = field.locator('.contact-field-picker');
                    await expect(picker).toBeVisible();
                    const box = await picker.boundingBox();
                    expect(box!.x).toBeGreaterThanOrEqual(0);
                    expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
                    await page.screenshot({
                        path: `artifacts/contact-template/${browserName}-${theme}-${width}.png`,
                    });
                    await picker
                        .getByRole('button', { name: 'Fechar', exact: true })
                        .click();
                }
            }
        });
    });
}

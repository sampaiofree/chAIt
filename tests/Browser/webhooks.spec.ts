import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page, email = 'admin@example.test') {
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({
            json: { data: { status: 'connected', provider_available: false } },
        }),
    );
    await page.route('**/workspaces/*/conversations**', (route) =>
        route.fulfill({
            json: { data: [], selected_thread: null, history_limited: false },
        }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill(email);
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
async function create(page: Page, name: string) {
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await expect(page).toHaveURL(/\/settings\/profile$/);
    await expect(
        page
            .getByRole('navigation', { name: 'Menu principal' })
            .getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole('navigation', { name: 'Seções de configurações' })
        .getByRole('link', { name: 'Webhooks', exact: true })
        .click();
    await expect(page).toHaveURL(/\/settings\/webhooks$/);
    await expect(
        page.getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await page
        .getByRole('button', { name: 'Criar Webhook', exact: true })
        .first()
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Nome do Webhook').fill(name);
    await dialog
        .getByRole('button', { name: 'Criar Webhook', exact: true })
        .click();
    await expect(page).toHaveURL(/\/webhooks\/\d+$/);
    await expect(
        page.getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(
        page
            .getByRole('navigation', { name: 'Menu principal' })
            .getByRole('link', { name: 'Configurações', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.topbar-title')).toHaveText('Configurações');
}
async function configure(page: Page) {
    await page.getByLabel('JSON de exemplo').fill(
        JSON.stringify(
            {
                compradores: [
                    {
                        telefone: '5527999999999',
                        nome: 'Maria',
                        email: 'maria@example.com',
                    },
                ],
                compra: { status: 'approved', produto: 'Curso' },
            },
            null,
            2,
        ),
    );
    await page
        .getByRole('button', { name: 'Identificar campos', exact: true })
        .click();
    await expect(page.getByRole('status').first()).toHaveText(
        'Estrutura identificada.',
    );
    await page
        .getByRole('button', { name: 'Adicionar mapeamento', exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Origem 1', exact: true })
        .selectOption(JSON.stringify(['compradores', 0, 'telefone']));
    await page
        .getByRole('button', { name: 'Adicionar mapeamento', exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Origem 2', exact: true })
        .selectOption(JSON.stringify(['compradores', 0, 'nome']));
}
async function noOverflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
}

test('configure simulate activate capture and preserve pending edits', async ({
    page,
    request,
}) => {
    await login(page);
    await create(page, 'Compras integração');
    await expect(
        page.getByText('Estado atual:', { exact: false }),
    ).toContainText('Teste');
    await configure(page);
    await page
        .getByRole('button', { name: 'Adicionar regra', exact: true })
        .click();
    await page.getByLabel('Executar sempre', { exact: true }).uncheck();
    await page
        .getByRole('combobox', { name: 'SE — Campo do payload', exact: true })
        .selectOption(JSON.stringify(['compra', 'status']));
    await page.getByLabel('Valor esperado', { exact: true }).fill('approved');
    await page
        .getByRole('listbox', { name: 'Adicionar tags', exact: true })
        .selectOption({ label: 'Interesse IA' });
    await page
        .getByRole('button', { name: 'Simular sem alterar dados', exact: true })
        .click();
    await expect(
        page.getByText('Simulado — sem alterações no CRM', { exact: true }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Criar campo personalizado', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog
        .getByLabel('Nome do campo', { exact: true })
        .fill('Origem Webhook');
    await dialog
        .getByRole('button', { name: 'Criar campo', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await expect(
        page.getByRole('combobox', { name: 'Origem 2', exact: true }),
    ).toHaveValue(JSON.stringify(['compradores', 0, 'nome']));
    await expect(
        page
            .getByRole('combobox', { name: 'Destino 2', exact: true })
            .locator('option'),
    ).toContainText(['Origem Webhook (personalizado)']);
    await page
        .getByRole('combobox', { name: /^Estado ao salvar/ })
        .selectOption('active');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(page.getByRole('status').first()).toHaveText(
        'Configuração salva.',
    );
    await page.reload();
    await expect(
        page.getByText('Estado atual:', { exact: false }),
    ).toContainText('Ativo');
    await expect(
        page.getByLabel('Valor esperado', { exact: true }),
    ).toHaveValue('approved');
    const url = await page
        .getByLabel('URL do Webhook', { exact: true })
        .inputValue();
    await page
        .getByRole('button', { name: 'Capturar automaticamente', exact: true })
        .click();
    await expect(
        page.getByText('Aguardando requisição até', { exact: false }),
    ).toBeVisible();
    await page
        .getByLabel('Nome do Webhook', { exact: true })
        .fill('Rascunho ainda não salvo');
    const response = await request.post(url, {
        data: {
            compradores: [{ telefone: '5527999999999', nome: 'Atualizado' }],
            compra: { status: 'approved' },
        },
    });
    expect(response.status()).toBe(200);
    expect((await response.json()).status).toBe('processed');
    await expect(page.getByLabel('JSON de exemplo')).toHaveValue(/Atualizado/, {
        timeout: 10000,
    });
    await expect(
        page.getByLabel('Nome do Webhook', { exact: true }),
    ).toHaveValue('Rascunho ainda não salvo');
    await page
        .getByRole('link', { name: 'Voltar aos Webhooks', exact: true })
        .click();
    await expect(
        page.getByRole('dialog').getByText('Descartar alterações?'),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(
        page.getByLabel('Nome do Webhook', { exact: true }),
    ).toHaveValue('Rascunho ainda não salvo');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Apagar exemplo', exact: true })
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Confirmar', exact: true })
        .click();
    await expect(page.getByLabel('JSON de exemplo')).toHaveValue('');
    await expect(
        page.getByRole('combobox', { name: 'Origem 1', exact: true }),
    ).toHaveValue(JSON.stringify(['compradores', 0, 'telefone']));
    await page.locator('.receipts summary').click();
    await expect(page.locator('.receipts')).toContainText('Processado');
    const workspaceList = page.url().replace(/\/\d+$/, '');
    await page
        .getByRole('link', { name: 'Voltar aos Webhooks', exact: true })
        .click();
    await expect(page).toHaveURL(workspaceList);
    await expect(
        page.getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await page.getByRole('link', { name: 'Perfil', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/profile$/);
    await page.goBack();
    await expect(page).toHaveURL(workspaceList);
});

test('activation and payload errors keep values next to the affected fields', async ({
    page,
}) => {
    await login(page);
    await create(page, 'Validação Webhook');
    await page
        .getByRole('combobox', { name: /^Estado ao salvar/ })
        .selectOption('active');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(page.locator('.error-summary')).toContainText(
        'Mapeie o Telefone WhatsApp',
    );
    await page.getByLabel('JSON de exemplo').fill('{oops');
    await page
        .getByRole('button', { name: 'Identificar campos', exact: true })
        .click();
    await expect(page.locator('.error-summary')).toContainText('JSON inválido');
    await expect(page.getByLabel('JSON de exemplo')).toHaveValue('{oops');
    await configure(page);
    await page
        .getByRole('combobox', { name: 'Destino 2', exact: true })
        .selectOption('native:phone');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(page.locator('#target-error-1')).toHaveText(
        'Este destino já está mapeado.',
    );
    await page
        .getByRole('combobox', { name: 'Destino 2', exact: true })
        .selectOption('native:display_name');
    await page
        .getByRole('button', { name: 'Adicionar regra', exact: true })
        .click();
    await page
        .getByRole('listbox', { name: 'Adicionar tags', exact: true })
        .selectOption({ label: 'Interesse IA' });
    await page
        .getByRole('button', { name: 'Adicionar regra', exact: true })
        .click();
    await page
        .getByRole('listbox', { name: 'Remover tags', exact: true })
        .nth(1)
        .selectOption({ label: 'Interesse IA' });
    await expect(page.locator('.warning')).toContainText(
        'a última regra prevalece',
    );
});

test('test capture is automatic and does not replace unsaved JSON or mappings', async ({
    page,
    request,
}) => {
    await login(page);
    await create(page, 'Captura Teste');
    const url = await page
        .getByLabel('URL do Webhook', { exact: true })
        .inputValue();
    expect(
        (
            await request.post(url, {
                data: { phone: '5527999999999', status: 'pending' },
            })
        ).status(),
    ).toBe(200);
    await expect(page.getByLabel('JSON de exemplo')).toHaveValue(/pending/, {
        timeout: 10000,
    });
    await page
        .getByRole('button', { name: 'Adicionar mapeamento', exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Origem 1', exact: true })
        .selectOption('["phone"]');
    await page
        .getByLabel('JSON de exemplo')
        .fill('{"phone":"5527999999999","status":"rascunho"}');
    await request.post(url, {
        data: { phone: '5527999999999', status: 'captured-again' },
    });
    await page.waitForTimeout(3300);
    await expect(page.getByLabel('JSON de exemplo')).toHaveValue(/rascunho/);
    await expect(
        page.getByRole('combobox', { name: 'Origem 1', exact: true }),
    ).toHaveValue('["phone"]');
});

test('webhook configuration respects themes viewports keyboard and modal behavior', async ({
    page,
}, testInfo) => {
    await login(page);
    await create(
        page,
        'Webhook com nome longo para revisão visual e acessibilidade',
    );
    await configure(page);
    await page
        .getByRole('button', { name: 'Adicionar regra', exact: true })
        .click();
    await page
        .getByRole('listbox', { name: 'Adicionar tags', exact: true })
        .selectOption({ label: 'Interesse IA' });

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
            await page.setViewportSize({ width, height: 900 });
            await noOverflow(page);
            await expect
                .poll(() =>
                    page
                        .getByRole('navigation', {
                            name: 'Seções de configurações',
                        })
                        .evaluate((nav) => {
                            const active = nav
                                .querySelector('[aria-current="page"]')!
                                .getBoundingClientRect();
                            const bounds = nav.getBoundingClientRect();

                            return (
                                active.left >= bounds.left - 1 &&
                                active.right <= bounds.right + 1
                            );
                        }),
                )
                .toBe(true);
            await page
                .locator('.app-page')
                .evaluate((element) => element.scrollTo(0, 0));
            await page.screenshot({
                path: testInfo.outputPath(
                    `webhooks-${theme}-${width}-header.png`,
                ),
                fullPage: true,
            });
            await page
                .locator('.app-page')
                .evaluate((element) =>
                    element.scrollTo(0, element.scrollHeight),
                );
            await page.screenshot({
                path: testInfo.outputPath(
                    `webhooks-${theme}-${width}-tags.png`,
                ),
                fullPage: true,
            });
            await page.locator('.section').nth(2).scrollIntoViewIfNeeded();
            await page.screenshot({
                path: testInfo.outputPath(`webhooks-${theme}-${width}.png`),
                fullPage: true,
            });
        }
    }

    await page
        .getByRole('button', { name: 'Criar campo personalizado', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Nome do campo')).toBeFocused();
    await dialog.getByLabel('Nome do campo').fill('Campo não salvo');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('alertdialog')).toContainText(
        'Descartar alterações?',
    );
    await page
        .getByRole('button', { name: 'Continuar editando', exact: true })
        .click();
    await expect(dialog.getByLabel('Nome do campo')).toHaveValue(
        'Campo não salvo',
    );
});

test('agents cannot see or access Webhooks', async ({ page }) => {
    await login(page, 'agent@example.test');
    await expect(
        page.getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveCount(0);
    await page.goto('/settings/profile');
    await expect(
        page
            .getByRole('navigation', { name: 'Seções de configurações' })
            .getByRole('link', { name: 'Webhooks', exact: true }),
    ).toHaveCount(0);
    const settingsResponse = await page.goto('/settings/webhooks');
    expect(settingsResponse?.status()).toBe(403);
    const response = await page.goto('/workspaces/1/webhooks');
    expect(response?.status()).toBe(403);
});

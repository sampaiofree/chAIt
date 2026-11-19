import { test, expect } from '@playwright/test';

test('create a lead, open its new conversation and delete it from the list', async ({
    page,
}) => {
    test.skip(
        process.env.CHAIT_BROWSER_WHATSAPP_CHECK !== 'true',
        'Requires isolated provider fixture',
    );
    await page.route('**/conversations/*/messages*', (route) =>
        route.fulfill({ json: { data: [], read_version: 0 } }),
    );
    await page.route('**/conversations/*/read', (route) =>
        route.fulfill({ json: { data: {} } }),
    );
    await page.route('**/conversations/*/avatar*', (route) =>
        route.fulfill({ json: { data: { url: null } } }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.getByRole('link', { name: 'Leads', exact: true }).click();
    await page.getByRole('button', { name: /Novo lead/i }).click();
    const dialog = page.getByRole('dialog');
    await dialog
        .getByLabel('Nome', { exact: false })
        .fill('Lead ciclo completo');
    await dialog.getByLabel('WhatsApp com DDI').fill('5511987654324');
    await dialog.getByRole('button', { name: 'Cadastrar lead' }).click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    await page
        .getByRole('button', { name: 'Abrir conversa', exact: true })
        .click();
    await expect(page).toHaveURL(/dashboard\?thread_id=\d+/);
    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Lead ciclo completo',
    );
    await page.getByRole('link', { name: 'Leads', exact: true }).click();
    await page
        .getByRole('button', {
            name: 'Excluir lead Lead ciclo completo',
            exact: true,
        })
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Cancelar' })
        .click();
    await expect(
        page.getByText('Lead ciclo completo', { exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page
        .getByRole('button', {
            name: 'Excluir lead Lead ciclo completo',
            exact: true,
        })
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Confirmar' })
        .click();
    await expect(
        page.getByText('Lead ciclo completo', { exact: true }),
    ).toHaveCount(0);
    await page.screenshot({ path: 'artifacts/leads-delete-mobile.png' });
});

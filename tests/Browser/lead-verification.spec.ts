import { test, expect } from '@playwright/test';

test('lead verification preserves errors, blocks pending actions and saves confirmed numbers', async ({
    page,
}) => {
    test.skip(
        process.env.CHAIT_BROWSER_WHATSAPP_CHECK !== 'true',
        'Requires isolated provider fixture',
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.getByRole('link', { name: 'Leads', exact: true }).click();
    await page.getByRole('button', { name: /Novo lead/i }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Nome', { exact: false }).fill('Lead verificado');
    const phone = dialog.getByLabel('WhatsApp com DDI');
    await phone.fill('5511987654322');
    await dialog.getByRole('button', { name: 'Cadastrar lead' }).click();
    await expect(
        dialog.getByText('Este número não está cadastrado no WhatsApp.', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(dialog.getByLabel('Nome', { exact: false })).toHaveValue(
        'Lead verificado',
    );
    await phone.fill('5511987654323');
    await dialog.getByRole('button', { name: 'Cadastrar lead' }).click();
    await expect(
        dialog.getByText(/Não foi possível verificar este número/),
    ).toBeVisible();
    await phone.fill('5511987654321');
    let release!: () => void;
    const pending = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/workspaces/*/leads', async (route) => {
        if (route.request().method() === 'POST') {
            await pending;
        }

        await route.continue();
    });
    await dialog.getByRole('button', { name: 'Cadastrar lead' }).click();
    await expect(
        dialog.getByRole('button', { name: 'Cancelar', exact: true }),
    ).toBeDisabled();
    await expect(phone).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    release();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('h1')).toContainText('Lead verificado');
});

import { test, expect } from '@playwright/test';

test('admin user modals, additional workspaces and responsive layout', async ({
    page,
}) => {
    await page.goto('/login');
    await page
        .getByLabel('E-mail', { exact: true })
        .fill('sampaio.free@gmail.com');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.goto('/admin/usuarios?search=Conta');
    await page
        .getByRole('button', { name: 'Novo usuário', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(page.getByLabel('Nome', { exact: true })).toBeFocused();
    await page.getByLabel('Nome', { exact: true }).fill('Rascunho');
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await page.getByRole('button', { name: 'Descartar', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await page.getByRole('link', { name: 'Editar Conta existente' }).click();
    await expect(dialog.getByLabel('Nome', { exact: true })).toHaveValue(
        'Conta existente',
    );
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page).toHaveURL(/search=Conta/);
    await page
        .getByRole('button', {
            name: 'Novo workspace para Conta existente',
            exact: true,
        })
        .click();
    await dialog.getByLabel('Nome do workspace').fill('Filial de teste');
    await dialog
        .getByRole('button', { name: 'Criar workspace', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await expect(
        page.getByText('Filial de teste · Proprietário'),
    ).toBeVisible();

    for (const colorScheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme });

        for (const width of [390, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            expect(
                await page
                    .locator('.app-page')
                    .evaluate((el) => el.scrollWidth <= el.clientWidth),
            ).toBe(true);
            await page
                .getByRole('button', { name: 'Novo usuário', exact: true })
                .click();
            await expect(dialog).toBeVisible();
            const bounds = await dialog.boundingBox();
            expect(bounds!.width).toBeLessThanOrEqual(560);
            expect(bounds!.x).toBeGreaterThanOrEqual(16);
            await page.screenshot({
                path: `/tmp/chait-admin-${colorScheme}-${width}.png`,
                animations: 'disabled',
            });
            await page.keyboard.press('Escape');
            await expect(dialog).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: 'Novo usuário', exact: true }),
            ).toBeFocused();
        }
    }
});

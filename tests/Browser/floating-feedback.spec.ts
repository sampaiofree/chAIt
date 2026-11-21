import { test, expect } from '@playwright/test';

for (const width of [390, 768, 1024, 1280, 1440]) {
    test(`help floats without moving fields at ${width}px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/login');
        await page
            .getByLabel('E-mail', { exact: true })
            .fill('admin@example.test');
        await page.getByLabel('Senha', { exact: true }).fill('password');
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
        await expect(page).toHaveURL(/dashboard/);
        await page.goto('/settings/profile');
        await page
            .getByLabel('Telefone / WhatsApp', { exact: true })
            .fill('5511999999999');
        const input = page.locator('input').first();
        const before = await input.boundingBox();
        const trigger = page
            .getByRole('button', { name: 'Sobre Ajuda' })
            .first();
        await trigger.click();
        const help = page.locator('.app-help-content');
        await expect(help).toBeVisible();
        await page.screenshot({
            path: `artifacts/design-review/floating-help-${width}.png`,
        });
        await page.evaluate(() =>
            document.documentElement.classList.add('dark'),
        );
        await page.screenshot({
            path: `artifacts/design-review/floating-help-${width}-dark.png`,
        });
        await page.evaluate(() =>
            document.documentElement.classList.remove('dark'),
        );
        expect(await input.boundingBox()).toEqual(before);
        const box = await help.boundingBox();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(width);
        await page.keyboard.press('Escape');
        await expect(help).toHaveCount(0);
        await expect(trigger).toBeFocused();
        await page
            .getByRole('button', { name: /Salvar/ })
            .first()
            .click();
        await expect(page.locator('.app-notification')).toContainText(
            /atualizado/i,
        );
        expect(await input.boundingBox()).toEqual(before);
        await page.getByRole('button', { name: 'Fechar notificação' }).click();
        await expect(page.locator('.app-notification')).toHaveCount(0);
    });
}

test('errors persist and timed notifications pause during interaction', async ({
    page,
}) => {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.goto('/settings/openai');
    let fail = true;
    await page.route('**/workspaces/*/openai', (route) =>
        route.fulfill({
            status: fail ? 503 : 200,
            json: fail
                ? { message: 'Serviço indisponível. Tente novamente.' }
                : { data: { configured: true } },
        }),
    );
    await page.getByLabel('Chave de API').fill('test-placeholder');
    const button = page.getByRole('button', {
        name: /Salvar chave|Substituir chave/,
    });
    const before = await button.boundingBox();
    await button.click();
    const notice = page.locator('.app-notification');
    await expect(notice).toContainText('Serviço indisponível');
    expect(await button.boundingBox()).toEqual(before);
    await page.clock.install();
    await page.clock.fastForward(6000);
    await expect(notice).toBeVisible();
    await page.getByRole('button', { name: 'Fechar notificação' }).click();
    fail = false;
    await button.click();
    await expect(notice).toContainText('Chave configurada');
    await notice.hover();
    await page.clock.fastForward(6000);
    await expect(notice).toBeVisible();
    await page.mouse.move(0, 0);
    await page.clock.fastForward(5000);
    await expect(notice).toHaveCount(0);
});

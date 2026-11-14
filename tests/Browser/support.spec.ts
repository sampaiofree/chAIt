import { expect, test } from '@playwright/test';

for (const colorScheme of ['light', 'dark'] as const) {
    for (const width of [390, 1440]) {
        test(`public support works without navigation (${colorScheme}, ${width})`, async ({
            page,
        }, testInfo) => {
            await page.emulateMedia({ colorScheme });
            await page.setViewportSize({ width, height: 900 });
            const response = await page.goto('/suporte');
            expect(response?.status()).toBe(200);
            await expect(page).toHaveURL(/\/suporte$/);
            await expect(
                page.getByRole('heading', { name: 'Como podemos ajudar?' }),
            ).toBeVisible();
            await expect(
                page.getByText('Suporte CHAIT', { exact: true }),
            ).toBeVisible();
            await expect(page.getByRole('link')).toHaveCount(1);
            await expect(page.getByRole('link')).toHaveAttribute(
                'href',
                'mailto:suporte@chait.com',
            );
            await expect(
                page.getByText('suporte@chait.com', { exact: true }),
            ).toBeVisible();
            await expect(
                page.locator('nav, .app-navigation, .app-topbar'),
            ).toHaveCount(0);
            await expect(page.locator('a[href="/login"]')).toHaveCount(0);
            await expect(
                page.getByText(/Rascunho|pendente de configuração/),
            ).toHaveCount(0);
            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            ).toBe(true);
            await page.screenshot({
                path: testInfo.outputPath('support.png'),
                fullPage: true,
            });
        });
    }
}

import { expect, test } from '@playwright/test';

test('brand lettering follows the theme while preserving the colored logo', async ({
    page,
}, testInfo) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    const brand = page.locator('.app-brand');
    const lettering = brand.locator('.dark-lettering');
    await expect(brand).toBeVisible();
    await expect(lettering.first()).toBeHidden();
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await expect(lettering.first()).toBeVisible();
    await expect(lettering.last()).toBeVisible();
    await expect(brand.locator('img').first()).toHaveAttribute(
        'src',
        '/brand/logo.png',
    );
    await expect(lettering.first()).toHaveCSS(
        'filter',
        'brightness(0) invert(1)',
    );
    await brand.screenshot({ path: testInfo.outputPath('brand-dark.png') });
    await page.evaluate(() =>
        document.documentElement.classList.remove('dark'),
    );
    await expect(lettering.first()).toBeHidden();
});

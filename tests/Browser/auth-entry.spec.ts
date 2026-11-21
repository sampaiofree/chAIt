import { test, expect } from '@playwright/test';

for (const width of [390, 1440]) {
    test(`home offers password recovery and registration at ${width}px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/');
        await expect(page).toHaveURL('/');
        await expect(
            page.getByRole('heading', { name: 'Bem-vindo de volta' }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Recuperar senha', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Fazer cadastro', exact: true }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({ path: `test-results/login-home-${width}.png` });

        await page
            .getByRole('link', { name: 'Recuperar senha', exact: true })
            .click();
        await expect(page).toHaveURL('/forgot-password');
        await expect(
            page.getByRole('heading', { name: 'Recuperar senha' }),
        ).toBeVisible();
        await page.getByRole('link', { name: 'Voltar ao login' }).click();
        await expect(page).toHaveURL('/login');
        await page
            .getByRole('link', { name: 'Fazer cadastro', exact: true })
            .click();
        await expect(page).toHaveURL('/register');
        await expect(
            page.getByRole('heading', { name: 'Crie sua conta' }),
        ).toBeVisible();
    });
}

test('home redirects an authenticated user to the dashboard', async ({
    page,
}) => {
    await page.goto('/');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.goto('/');
    await expect(page).toHaveURL(/dashboard/);
});

for (const remember of [false, true]) {
    test(`reopening login restores ${remember ? 'a remember cookie' : 'an existing session'} with bounded dashboard headers`, async ({
        page,
        context,
        browser,
    }) => {
        await page.goto('/login');
        await page
            .getByLabel('E-mail', { exact: true })
            .fill('admin@example.test');
        await page.getByLabel('Senha', { exact: true }).fill('password');
        await page.getByLabel('Manter conectado').setChecked(remember);
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);

        const storageState = await context.storageState();

        if (remember) {
            // Restore authentication from the recaller, without a session cookie.
            expect(
                storageState.cookies.some(({ name }) =>
                    name.startsWith('remember_'),
                ),
            ).toBe(true);
            storageState.cookies = storageState.cookies.filter(
                ({ name }) => name !== 'chait_browser_test',
            );
        }

        const reopened = await browser.newContext({
            baseURL: new URL(page.url()).origin,
            storageState,
        });

        try {
            const reopenedPage = await reopened.newPage();
            const response = await reopenedPage.goto('/login');
            expect(response?.status()).toBe(200);
            await expect(reopenedPage).toHaveURL(/\/dashboard$/);
            expect(response?.request().redirectedFrom()?.url()).toMatch(
                /\/login$/,
            );

            const headers = await response!.allHeaders();
            const preloads = headers.link.match(/>; /g) ?? [];
            expect(preloads.length).toBeGreaterThan(0);
            expect(preloads.length).toBeLessThanOrEqual(10);
            expect(Buffer.byteLength(headers.link)).toBeLessThan(2048);
            expect(Buffer.byteLength(JSON.stringify(headers))).toBeLessThan(
                4096,
            );
            expect(
                await reopenedPage
                    .locator('link[rel="preload"], link[rel="modulepreload"]')
                    .count(),
            ).toBeGreaterThan(10);
            expect((await reopenedPage.reload())?.status()).toBe(200);
            await expect(reopenedPage).toHaveURL(/\/dashboard$/);

            // Stop dashboard polling before removing cookies, so an in-flight
            // response cannot recreate the session during this guest check.
            await reopenedPage.close();
            await reopened.clearCookies();
            const signedOutPage = await reopened.newPage();
            expect((await signedOutPage.goto('/dashboard'))?.status()).toBe(
                200,
            );
            await expect(signedOutPage).toHaveURL(/\/login$/);
            await expect(
                signedOutPage.getByRole('heading', {
                    name: 'Bem-vindo de volta',
                }),
            ).toBeVisible();
        } finally {
            await reopened.close();
        }
    });
}

test('password recovery displays the sent confirmation', async ({ page }) => {
    await page.goto('/forgot-password');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByRole('button', { name: 'Enviar instruções' }).click();
    await expect(page.locator('.app-notification')).toContainText(
        'We have emailed your password reset link.',
    );
    await expect(
        page.getByRole('button', { name: 'Enviar instruções' }),
    ).toBeEnabled();
});

test('password reset displays an invalid token error', async ({ page }) => {
    await page.goto('/reset-password/invalid-token?email=admin%40example.test');
    await page.getByLabel('Nova senha', { exact: true }).fill('newpassword123');
    await page
        .getByLabel('Confirmar senha', { exact: true })
        .fill('newpassword123');
    await page.getByRole('button', { name: 'Salvar senha' }).click();
    await expect(page.locator('.app-notification')).toContainText(
        'This password reset token is invalid.',
    );
    await expect(
        page.getByRole('button', { name: 'Salvar senha' }),
    ).toBeEnabled();
});

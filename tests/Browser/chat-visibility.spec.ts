import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill(email);
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/dashboard|settings\/profile/);
}

test('team permissions persist, queue-only users consult, and revocation removes chat', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(60000);
    test.skip(
        process.env.CHAIT_BROWSER_WHATSAPP_CHECK !== 'true',
        'Requires isolated provider fixture',
    );
    await login(page, 'admin@example.test');
    await page.goto('/settings/team');
    await page
        .getByRole('button', { name: 'Adicionar usuário', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByLabel('Ver próprias conversas', { exact: true }),
    ).toBeChecked();
    await expect(
        dialog.getByLabel('Ver todas as conversas', { exact: true }),
    ).not.toBeChecked();
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
    const row = page
        .locator('.member-row')
        .filter({ hasText: 'agent@example.test' });
    async function setPermission(label: string, value: boolean) {
        const control = row.getByLabel(label, { exact: true });
        if ((await control.isChecked()) === value) return;
        const saved = page.waitForResponse(
            (response) =>
                response.request().method() === 'PATCH' &&
                response.url().includes('/permissions'),
        );
        await control.click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(row.locator('.member-permissions')).toHaveAttribute('aria-busy', 'false');
        await expect(control).toBeChecked({ checked: value });
    }
    await setPermission('Ver conversas não atribuídas', true);
    await setPermission('Ver próprias conversas', false);
    await page.reload();
    await expect(
        row.getByLabel('Ver próprias conversas', { exact: true }),
    ).not.toBeChecked();
    await expect(
        row.getByLabel('Ver conversas não atribuídas', { exact: true }),
    ).toBeChecked();
    for (const theme of ['light', 'dark']) {
        await page.evaluate(
            (theme) =>
                document.documentElement.classList.toggle(
                    'dark',
                    theme === 'dark',
                ),
            theme,
        );
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await row.scrollIntoViewIfNeeded();
            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            ).toBeTruthy();
            await page.screenshot({
                path: info.outputPath(`permissions-${theme}-${width}.png`),
            });
        }
    }
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
    });
    const agent = await context.newPage();
    await agent.route('**/conversations**', (route) => {
        const path = new URL(route.request().url()).pathname;
        return route.fulfill({
            json: path.endsWith('/conversations')
                ? {
                      data: [
                          {
                              id: 1,
                              status: 'bot',
                              unread_count: 0,
                              unread_version: 0,
                              assigned_to: null,
                              last_message_preview: 'Consulta da fila',
                              last_message_at: null,
                              contact: {
                                  id: 1,
                                  display_name: 'Conversa da fila',
                                  phone: '5511999999999',
                                  tags: [],
                                  custom_fields: [],
                              },
                              notes: [],
                              automation: {
                                  state: 'allowed',
                                  can_pause: true,
                                  can_resume: false,
                                  pause_reasons: [],
                              },
                          },
                      ],
                      selected_thread: null,
                      next_cursor: null,
                      counts: { all: 1, mine: 0, unassigned: 1 },
                  }
                : path.endsWith('/messages')
                  ? { data: [], read_version: 0 }
                  : { data: {} },
        });
    });
    await login(agent, 'agent@example.test');
    await expect(
        agent.getByText('Conversa da fila', { exact: true }).first(),
    ).toBeVisible();
    await agent.getByText('Conversa da fila', { exact: true }).first().click();
    await expect(
        agent.getByText('Seu acesso permite consultar esta conversa.'),
    ).toBeVisible();
    await expect(
        agent.getByRole('button', { name: 'Assumir para mim', exact: true }),
    ).toHaveCount(0);
    await expect(
        agent.getByRole('button', { name: 'Pausar automação', exact: true }),
    ).toHaveCount(0);
    await setPermission('Ver conversas não atribuídas', false);
    // Uses the real access endpoint on the existing 15-second verification interval.
    await expect(agent).toHaveURL(/settings\/profile/, { timeout: 25000 });
    await expect(
        agent.getByRole('link', { name: 'Conversas', exact: true }),
    ).toHaveCount(0);
    await expect(
        agent.getByRole('link', { name: 'chAIt — Início' }),
    ).toHaveAttribute('href', '/settings/profile');
    await context.close();
    await setPermission('Ver próprias conversas', true);
});

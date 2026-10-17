import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page) {
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({
            json: {
                data: {
                    status: 'connected',
                    phone: '+5511999999999',
                    provider_available: true,
                },
            },
        }),
    );
    await page.route('**/workspaces/*/conversations**', (route) => {
        if (new URL(route.request().url()).pathname.endsWith('/messages')) {
            return route.fulfill({
                json: { data: [], history_limited: false },
            });
        }

        if (new URL(route.request().url()).pathname.endsWith('/avatar')) {
            return route.fulfill({ json: { data: { url: null } } });
        }

        if (new URL(route.request().url()).pathname.endsWith('/read')) {
            return route.fulfill({
                json: { data: { unread_count: 0, unread_version: 0 } },
            });
        }

        const thread = {
            id: 1,
            status: 'bot',
            last_message_at: null,
            unread_count: 0,
            unread_version: 0,
            assigned_to: null,
            notes: [],
            contact: {
                id: 1,
                display_name: 'Contato de teste',
                phone: '+5511999999999',
                email: null,
                cpf: null,
                tags: [],
                custom_fields: [],
            },
        };

        return route.fulfill({
            json: { data: [thread], selected_thread: thread },
        });
    });
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
async function noOverflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
}

for (const width of [390, 768, 1024, 1280, 1440]) {
    test(`sequence layout and both themes at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await login(page);
        await page.goto('/workspaces/1/sequences');
        await expect(
            page.getByRole('heading', { name: 'Sequências', exact: true }),
        ).toBeVisible();

        for (const theme of ['light', 'dark']) {
            await page.evaluate(
                (mode) =>
                    document.documentElement.classList.toggle(
                        'dark',
                        mode === 'dark',
                    ),
                theme,
            );
            await noOverflow(page);
            await page.screenshot({
                path: testInfo.outputPath(`list-${theme}-${width}.png`),
                fullPage: true,
            });
            await page
                .getByRole('link', { name: /Pós-venda e acompanhamento/ })
                .click();
            await expect(
                page.getByRole('heading', { name: 'Programação da sequência' }),
            ).toBeVisible();
            await page.evaluate(
                (mode) =>
                    document.documentElement.classList.toggle(
                        'dark',
                        mode === 'dark',
                    ),
                theme,
            );
            await expect(page.getByLabel('Nome da sequência')).toHaveValue(
                'Pós-venda e acompanhamento de clientes',
            );
            await noOverflow(page);
            await page.screenshot({
                path: testInfo.outputPath(`editor-${theme}-${width}.png`),
                fullPage: true,
            });
            await page
                .getByRole('link', { name: 'Voltar às sequências' })
                .click();
        }
    });
}

test('sequence create, draft validation, publication and dirty navigation', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/sequences');
    await page
        .getByRole('button', { name: 'Nova sequência', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog
        .getByLabel('Nome da sequência')
        .fill('Sequência criada pelo navegador');
    await dialog.getByRole('button', { name: 'Criar sequência' }).click();
    await expect(
        page.getByRole('heading', { name: 'Programação da sequência' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Adicionar etapa' }).click();
    await page.getByRole('button', { name: 'Salvar rascunho' }).click();
    await expect(page.getByRole('status')).toContainText('Rascunho salvo');
    await page.getByRole('button', { name: 'Publicar sequência' }).click();
    await expect(page.locator('.stage .error')).toContainText(
        'Selecione um fluxo',
    );
    await page
        .getByRole('combobox', { name: /^Fluxo publicado/ })
        .selectOption({ label: 'Acompanhamento publicado' });
    await page.getByLabel('Quantidade').fill('2');
    await page.getByRole('button', { name: 'Publicar sequência' }).click();
    await expect(page.getByRole('status')).toContainText('Sequência publicada');
    await page.getByRole('button', { name: 'Desativar', exact: true }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Confirmar', exact: true })
        .click();
    await expect(page.getByRole('status')).toContainText(
        'Novas entradas bloqueadas',
    );
    await page.getByRole('button', { name: 'Reativar', exact: true }).click();
    await expect(page.getByRole('status')).toContainText(
        'Novas entradas liberadas',
    );
    await page.getByLabel('Nome da sequência').fill('Alterada sem salvar');
    await page.getByRole('link', { name: 'Voltar às sequências' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Cancelar', exact: true })
        .click();
    await expect(page.getByLabel('Nome da sequência')).toHaveValue(
        'Alterada sem salvar',
    );
});

test('contact enrollment modal shares the backend service and preserves repeat entries', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByRole('button', { name: 'Abrir perfil do contato' }).click();
    const section = page.getByRole('region', { name: 'Sequência do contato' });
    await expect(
        section.getByRole('button', {
            name: 'Adicionar à sequência',
            exact: true,
        }),
    ).toBeVisible();
    await section
        .getByRole('button', { name: 'Adicionar à sequência', exact: true })
        .click();
    await page
        .getByLabel('Sequência publicada')
        .selectOption({ label: 'Pós-venda e acompanhamento de clientes' });
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Adicionar à sequência', exact: true })
        .click();
    await expect(section.getByRole('status')).toContainText('Contato incluído');
    await section
        .getByRole('button', { name: 'Adicionar à sequência', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'O fluxo em andamento continuará',
    );
    await page
        .getByLabel('Sequência publicada')
        .selectOption({ label: 'Pós-venda e acompanhamento de clientes' });
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Adicionar à sequência', exact: true })
        .click();
    await expect(section.getByRole('status')).toContainText(
        'A programação foi preservada',
    );
});

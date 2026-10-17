import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

const thread = {
    id: 1,
    status: 'in_progress',
    last_message_at: '2026-10-13T15:00:00Z',
    unread_count: 0,
    unread_version: 0,
    assigned_to: { id: 1, name: 'Admin de teste' },
    contact: {
        id: 1,
        display_name: 'Contato com nome longo para revisão do atendimento',
        phone: '+5511999999999',
        email: 'contact@example.test',
        cpf: null,
        tags: [],
        custom_fields: [],
    },
    notes: [],
};
type MockThread = Omit<typeof thread, 'assigned_to'> & {
    assigned_to: { id: number; name: string } | null;
};
async function mockChat(page: Page, activeThread: MockThread = thread) {
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
        const url = new URL(route.request().url());

        if (url.pathname.endsWith('/messages')) {
            return route.fulfill({
                json: {
                    data: [
                        {
                            id: 'message-1',
                            from_me: false,
                            type: 'text',
                            text: 'Olá, preciso de ajuda.',
                            timestamp: '2026-10-13T15:00:00Z',
                        },
                    ],
                    history_limited: false,
                    read_version: 0,
                },
            });
        }

        if (url.pathname.endsWith('/avatar')) {
            return route.fulfill({ json: { data: { url: null } } });
        }

        if (url.pathname.endsWith('/read')) {
            return route.fulfill({
                json: { data: { unread_count: 0, unread_version: 0 } },
            });
        }

        return route.fulfill({
            json: { data: [activeThread], selected_thread: activeThread },
        });
    });
}
async function login(
    page: Page,
    email = 'admin@example.test',
    activeThread: MockThread = thread,
) {
    await mockChat(page, activeThread);
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill(email);
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(
        page.getByRole('button', { name: 'Alternar menu principal' }),
    ).toBeVisible();
}
async function noOverflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    expect(
        await page
            .locator('.app-page')
            .evaluate((element) => element.scrollWidth <= element.clientWidth),
    ).toBe(true);
}
async function sidebarWidth(page: Page, width: number) {
    await expect
        .poll(
            async () =>
                (await page.locator('.desktop-navigation').boundingBox())
                    ?.width,
        )
        .toBe(width);
    await expect(page.locator('.desktop-navigation .app-brand')).toHaveText(
        'chAIt',
    );
}

test.beforeEach(async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
});

test('unassigned chat offers immediate reply while occupied chat requires explicit assignment', async ({
    page,
}) => {
    await login(page, 'admin@example.test', {
        ...thread,
        status: 'bot',
        assigned_to: null,
    });
    await expect(page.locator('.chat-heading')).toContainText(
        'Automação · Sem responsável',
    );
    await expect(page.getByPlaceholder('Mensagem…')).toBeEnabled();
    await expect(page.locator('.composer-hint')).toContainText(
        'Ao enviar, você assume',
    );
    await page.getByRole('button', { name: 'Abrir perfil do contato' }).click();
    await expect(
        page.getByRole('button', { name: 'Abrir atendimento' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Assumir para mim' }),
    ).toBeVisible();
    await expect(page.getByLabel('Atribuir a membro da equipe')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Finalizar atendimento' }),
    ).toHaveCount(0);

    await page.route('**/workspaces/*/conversations**', (route) => {
        const path = new URL(route.request().url()).pathname;

        if (!path.endsWith('/conversations')) {
            return route.fallback();
        }

        const occupied = {
            ...thread,
            assigned_to: { id: 2, name: 'Atendente de teste' },
        };

        return route.fulfill({
            json: { data: [occupied], selected_thread: occupied },
        });
    });
    await page.reload();
    await expect(page.getByPlaceholder('Mensagem…')).toBeDisabled();
    await expect(page.locator('.composer-hint')).toContainText(
        'Altere a atribuição',
    );
    await expect(page.locator('.chat-heading')).toContainText(
        'Atendente de teste',
    );
});

test('menu persists through conversations, flows, settings, editor, reload and history', async ({
    page,
}) => {
    await login(page);
    await sidebarWidth(page, 64);
    await page.getByRole('button', { name: 'Alternar menu principal' }).click();
    await sidebarWidth(page, 240);
    const header = await page.locator('.app-topbar').elementHandle();
    await page.getByRole('link', { name: 'Fluxos', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Fluxos', exact: true, level: 1 }),
    ).toBeVisible();
    expect(await header?.evaluate((element) => element.isConnected)).toBe(true);
    await sidebarWidth(page, 240);
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await page.getByRole('link', { name: 'Equipe', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Equipe', exact: true }),
    ).toBeVisible();
    await page.reload();
    await sidebarWidth(page, 240);
    await page.getByRole('link', { name: 'Fluxos', exact: true }).click();
    await page.getByRole('link').filter({ hasText: 'Fluxo de teste' }).click();
    await expect(
        page.getByLabel('Nome do fluxo', { exact: true }),
    ).toBeVisible();
    await sidebarWidth(page, 240);
    await page.getByRole('link', { name: 'Conversas', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goBack();
    await expect(
        page.getByLabel('Nome do fluxo', { exact: true }),
    ).toBeVisible();
    await sidebarWidth(page, 240);
    await page.getByRole('button', { name: 'Alternar menu principal' }).click();
    await sidebarWidth(page, 64);
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await sidebarWidth(page, 64);
    await noOverflow(page);
});

test('team modal handles focus, keyboard, discard and validation without losing entered values', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/team');
    await expect(page.getByLabel('Nome', { exact: true })).toHaveCount(0);
    const trigger = page.getByRole('button', {
        name: 'Adicionar usuário',
        exact: true,
    });
    await trigger.click();
    const dialog = page.getByRole('dialog', {
        name: 'Adicionar usuário',
        exact: true,
    });
    await expect(dialog.getByLabel('Nome', { exact: true })).toBeFocused();
    await dialog
        .getByRole('button', { name: 'Adicionar à equipe' })
        .press('Tab');
    await expect(
        dialog.getByRole('button', { name: 'Fechar formulário' }),
    ).toBeFocused();
    await dialog.getByLabel('Nome', { exact: true }).fill('Rascunho');
    await dialog
        .getByLabel('Número do WhatsApp', { exact: true })
        .fill('5511999999999');
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await page.getByRole('button', { name: 'Continuar editando' }).click();
    await expect(dialog.getByLabel('Nome', { exact: true })).toHaveValue(
        'Rascunho',
    );
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Descartar', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await expect(dialog.getByLabel('Nome', { exact: true })).toHaveValue('');
    await expect(
        dialog.getByLabel('Número do WhatsApp', { exact: true }),
    ).toHaveValue('');
    await dialog
        .getByLabel('Email', { exact: true })
        .fill('missing-data@example.test');
    await dialog.getByRole('button', { name: 'Adicionar à equipe' }).click();
    await expect(dialog.getByRole('alert').first()).toBeVisible();
    await expect(dialog.locator('#member-phone-error')).toContainText(
        'WhatsApp com DDI',
    );
    await expect(dialog.getByLabel('Email', { exact: true })).toHaveValue(
        'missing-data@example.test',
    );
    await dialog.getByRole('button', { name: 'Fechar formulário' }).click();
    await page.getByRole('button', { name: 'Descartar', exact: true }).click();
    await expect(trigger).toBeFocused();
});

test('new account and existing account can be added; busy modal cannot close or submit twice', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/team');
    let submissions = 0;
    await page.route('**/workspaces/*/members', async (route) => {
        submissions++;
        await new Promise((resolve) => setTimeout(resolve, 500));
        await route.continue();
    });
    await page
        .getByRole('button', { name: 'Adicionar usuário', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'Adicionar usuário',
        exact: true,
    });
    await dialog.getByLabel('Nome', { exact: true }).fill('Novo usuário');
    const phone = dialog.getByLabel('Número do WhatsApp', { exact: true });
    await phone.fill('+55 (11) 99999-9999');
    await expect(phone).toHaveValue('5511999999999');
    await dialog.getByLabel('Email', { exact: true }).fill('new@example.test');
    await dialog
        .getByLabel('Senha inicial', { exact: true })
        .fill('test-password-123');
    await dialog
        .getByLabel('Confirmar senha', { exact: true })
        .fill('test-password-123');
    await dialog.getByRole('button', { name: 'Adicionar à equipe' }).click();
    await expect(
        dialog.getByRole('button', { name: 'Fechar formulário' }),
    ).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    await expect(dialog).toBeHidden();
    expect(submissions).toBe(1);
    await expect(
        page.getByText('new@example.test', { exact: true }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Adicionar usuário', exact: true })
        .click();
    await expect(phone).toHaveValue('');
    await dialog
        .getByLabel('Email', { exact: true })
        .fill('existing@example.test');
    await dialog.getByRole('button', { name: 'Adicionar à equipe' }).click();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByText('Conta existente', { exact: true }),
    ).toBeVisible();
});

test('field and tag create/edit use modals and clean forms remain cancellable', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/contact-data');
    await page
        .getByRole('button', { name: 'Cadastrar campo', exact: true })
        .click();
    let dialog = page.getByRole('dialog');
    await dialog
        .getByLabel('Nome do campo', { exact: true })
        .fill('Cidade de teste');
    await dialog
        .getByRole('button', { name: 'Cadastrar campo', exact: true })
        .click();
    await expect(dialog).toBeHidden();
    await page
        .getByRole('button', { name: 'Editar campo Cidade de teste' })
        .click();
    await expect(dialog.getByLabel('Nome do campo')).toHaveValue(
        'Cidade de teste',
    );
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await page.getByRole('tab', { name: 'Tags', exact: true }).click();
    await page
        .getByRole('button', { name: 'Cadastrar tag', exact: true })
        .click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Nome da tag').fill('Cliente de teste');
    await dialog
        .getByRole('button', { name: 'Cadastrar tag', exact: true })
        .click();
    await expect(dialog).toBeHidden();
    await page
        .getByRole('button', { name: 'Editar tag Cliente de teste' })
        .click();
    await dialog.getByLabel('Nome da tag').fill('Cliente atualizado');
    await dialog
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByText('Cliente atualizado', { exact: true }),
    ).toBeVisible();
});

test('editor asks before leaving an unsaved draft via the shared menu', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/flows/1');
    await page
        .getByLabel('Nome do fluxo', { exact: true })
        .fill('Rascunho alterado');
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Descartar alterações', exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(page.getByLabel('Nome do fluxo', { exact: true })).toHaveValue(
        'Rascunho alterado',
    );
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/profile$/);
});

for (const width of [390, 768, 1024, 1280, 1440]) {
    test(`responsive layout and themes at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await login(page);

        for (const theme of ['light', 'dark'] as const) {
            await page.goto('/settings/appearance');
            const themeOption = page.getByRole('radio', {
                name:
                    theme === 'light'
                        ? 'Claro Sempre usar o tema claro'
                        : 'Escuro Sempre usar o tema escuro',
            });

            if (!(await themeOption.isChecked())) {
                await themeOption.check();
                await expect(
                    page.getByText('Preferência salva.', { exact: true }),
                ).toBeVisible();
            }

            await expect(page.locator('html')).toHaveClass(
                theme === 'dark' ? /dark/ : /^(?!.*dark)/,
            );

            for (const path of [
                '/settings/team',
                '/settings/contact-data',
                '/settings/profile',
                '/settings/security',
                '/settings/openai',
                '/workspaces/1/flows',
                '/workspaces/1/flow-triggers',
                '/workspaces/1/flows/1',
                '/dashboard',
            ]) {
                await page.goto(path);

                if (page.url().includes('/user/confirm-password')) {
                    await page
                        .getByLabel('Senha', { exact: true })
                        .fill('password');
                    await page
                        .getByRole('button', { name: 'Confirmar', exact: true })
                        .click();
                }

                await expect(
                    page.getByRole('button', {
                        name: 'Alternar menu principal',
                    }),
                ).toBeVisible();
                await noOverflow(page);
            }

            if (width < 1024) {
                await page
                    .getByRole('button', { name: 'Alternar menu principal' })
                    .click();
                const drawer = page.getByRole('dialog');
                await expect(drawer).toBeVisible();
                await drawer
                    .getByRole('link', { name: 'Configurações', exact: true })
                    .click();
                await expect(drawer).toBeHidden();
                await expect(
                    page.getByRole('button', {
                        name: 'Alternar menu principal',
                    }),
                ).toHaveAttribute('aria-expanded', 'false');
            }

            await page.goto('/settings/team');
            await page
                .getByRole('button', { name: 'Adicionar usuário', exact: true })
                .click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            const bounds = await dialog.boundingBox();
            expect(bounds?.width).toBeLessThanOrEqual(
                Math.min(560, width - 32),
            );
            expect(bounds?.x).toBeGreaterThanOrEqual(0);
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(`team-${theme}-${width}.png`),
            });
            await dialog
                .getByRole('button', { name: 'Cancelar', exact: true })
                .click();
        }
    });
}

test('contact panel overlays when space is limited and mobile displays one pane at a time', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.getByRole('button', { name: 'Alternar menu principal' }).click();
    await page.getByRole('button', { name: 'Abrir perfil do contato' }).click();
    await expect(page.locator('.profile-pane')).toBeVisible();
    await expect(page.locator('.chat-heading')).toContainText('Em atendimento');
    await expect(page.locator('.chat-heading')).toContainText('Admin de teste');
    await expect(page.locator('.service-controls')).toContainText(
        'Atendimento',
    );
    await expect(
        page.getByRole('button', { name: 'Finalizar atendimento' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Finalizar atendimento' }).click();
    await expect(
        page.getByRole('dialog', { name: 'Finalizar atendimento' }),
    ).toContainText('libera a automação');
    await page.getByRole('button', { name: 'Cancelar' }).click();
    expect(
        await page
            .locator('.profile-pane')
            .evaluate((element) => getComputedStyle(element).position),
    ).toBe('absolute');
    expect(
        (await page.locator('.chat-pane').boundingBox())?.width,
    ).toBeGreaterThanOrEqual(360);
    await noOverflow(page);
    await page.setViewportSize({ width: 390, height: 900 });
    await expect(page.locator('.list-pane')).toBeVisible();
    await expect(page.locator('.chat-pane')).toBeHidden();
    await page
        .getByRole('button')
        .filter({ hasText: thread.contact.display_name })
        .click();
    await expect(page.locator('.chat-pane')).toBeVisible();
    await expect(page.locator('.list-pane')).toBeHidden();
    await page.getByRole('button', { name: /perfil do contato/ }).click();
    await expect(page.locator('.profile-pane')).toBeVisible();
    await expect(page.locator('.service-controls')).toBeVisible();
    await page
        .getByRole('button', { name: 'Voltar para conversa', exact: true })
        .click();
    await expect(page.locator('.profile-pane')).toBeHidden();
    await expect(page.locator('.chat-pane')).toBeVisible();
    await noOverflow(page);
});

test('mobile chat keeps the profile button beside long contact details', async ({
    page,
}) => {
    await login(page, 'admin@example.test', {
        ...thread,
        assigned_to: {
            id: 1,
            name: 'Responsável com nome longo para revisão do atendimento',
        },
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page
        .getByRole('button')
        .filter({ hasText: thread.contact.display_name })
        .click();

    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        const heading = await page.locator('.chat-heading').boundingBox();
        const avatar = await page
            .locator('.chat-heading .avatar')
            .boundingBox();
        const title = await page.locator('.chat-heading .title').boundingBox();
        const button = await page
            .getByRole('button', { name: 'Abrir perfil do contato' })
            .boundingBox();

        expect(heading && avatar && title && button).toBeTruthy();
        expect(
            Math.abs(
                avatar!.y +
                    avatar!.height / 2 -
                    (button!.y + button!.height / 2),
            ),
        ).toBeLessThanOrEqual(2);
        expect(title!.x + title!.width).toBeLessThanOrEqual(button!.x);
        expect(button!.x + button!.width).toBeLessThanOrEqual(
            heading!.x + heading!.width,
        );
        expect(button!.width).toBeGreaterThanOrEqual(44);
        expect(button!.height).toBeGreaterThanOrEqual(44);
        await noOverflow(page);
    }

    await page.getByRole('button', { name: 'Abrir perfil do contato' }).click();
    await expect(page.locator('.profile-pane')).toBeVisible();
});

test('conversation filters apply together, clear, and discard unapplied edits', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    const trigger = page.getByRole('button', { name: 'Filtrar conversas' });
    await expect(page.locator('.list-pane h1')).toHaveClass(/sr-only/);
    await expect(page.locator('.connection-warning')).toHaveCount(0);
    await trigger.click();

    const filters = page.getByRole('dialog', { name: 'Filtrar conversas' });
    const assignmentFilter = filters.getByRole('combobox', {
        name: 'Atribuição',
    });
    const openFilter = filters.getByRole('checkbox', {
        name: 'Atendimentos abertos',
    });
    const tagFilter = filters.getByRole('checkbox', { name: 'Interesse IA' });
    await assignmentFilter.selectOption('mine');
    await openFilter.check();
    await tagFilter.check();
    await page.keyboard.press('Escape');
    await expect(filters).toBeHidden();

    await trigger.click();
    await expect(assignmentFilter).toHaveValue('all');
    await expect(openFilter).not.toBeChecked();
    await expect(tagFilter).not.toBeChecked();
    await assignmentFilter.selectOption('mine');
    await openFilter.check();
    await tagFilter.check();

    const appliedRequest = page.waitForRequest((request) => {
        const url = new URL(request.url());

        return (
            url.pathname.endsWith('/conversations') &&
            url.searchParams.get('assignment') === 'mine'
        );
    });
    await filters.getByRole('button', { name: 'Aplicar filtros' }).click();
    const appliedUrl = new URL((await appliedRequest).url());
    expect(appliedUrl.searchParams.get('status')).toBe('open');
    expect(appliedUrl.searchParams.getAll('tag_ids[]')).toHaveLength(1);
    await expect(filters).toBeHidden();
    await expect(
        page.getByRole('button', {
            name: 'Filtrar conversas, 3 critérios ativos',
        }),
    ).toBeVisible();

    await page
        .getByRole('button', { name: 'Filtrar conversas, 3 critérios ativos' })
        .click();
    const clearedRequest = page.waitForRequest((request) => {
        const url = new URL(request.url());

        return (
            url.pathname.endsWith('/conversations') &&
            url.searchParams.get('assignment') === 'all'
        );
    });
    await filters.getByRole('button', { name: 'Limpar filtros' }).click();
    const clearedUrl = new URL((await clearedRequest).url());
    expect(clearedUrl.searchParams.get('status')).toBe('all');
    expect(clearedUrl.searchParams.getAll('tag_ids[]')).toHaveLength(0);
    await expect(trigger).toBeVisible();
    await noOverflow(page);
});

test('conversation search and filter button share a row across themes and widths', async ({
    page,
}, testInfo) => {
    await login(page);

    for (const theme of ['light', 'dark'] as const) {
        await page.goto('/settings/appearance');
        const option = page.getByRole('radio', {
            name:
                theme === 'light'
                    ? 'Claro Sempre usar o tema claro'
                    : 'Escuro Sempre usar o tema escuro',
        });

        if (!(await option.isChecked())) {
            await option.check();
        }

        await page.goto('/dashboard');

        for (const width of [320, 390, 1440]) {
            await page.setViewportSize({ width, height: 844 });
            const search = (await page
                .getByRole('textbox', { name: 'Buscar por nome ou telefone' })
                .boundingBox())!;
            const trigger = (await page
                .getByRole('button', { name: 'Filtrar conversas' })
                .boundingBox())!;
            expect(
                Math.abs(
                    search.y +
                        search.height / 2 -
                        (trigger.y + trigger.height / 2),
                ),
            ).toBeLessThanOrEqual(1);
            expect(search.x + search.width).toBeLessThan(trigger.x);
            expect(trigger.width).toBeGreaterThanOrEqual(
                width < 1024 ? 44 : 40,
            );
            await noOverflow(page);

            if (width === 320 || width === 1440) {
                await page.screenshot({
                    animations: 'disabled',
                    path: testInfo.outputPath(
                        `conversation-filters-${theme}-${width}.png`,
                    ),
                });
                await page
                    .getByRole('button', { name: 'Filtrar conversas' })
                    .click();
                const dialogBox = (await page
                    .getByRole('dialog', { name: 'Filtrar conversas' })
                    .boundingBox())!;
                expect(dialogBox.x).toBeGreaterThanOrEqual(0);
                expect(dialogBox.x + dialogBox.width).toBeLessThanOrEqual(
                    width,
                );
                expect(dialogBox.height).toBeLessThanOrEqual(844);
                await page.screenshot({
                    animations: 'disabled',
                    path: testInfo.outputPath(
                        `conversation-filter-panel-${theme}-${width}.png`,
                    ),
                });
                await page.keyboard.press('Escape');
            }
        }
    }
});

test('connection warning appears only when disconnected or provider is unavailable', async ({
    page,
}) => {
    await login(page);
    await expect(page.locator('.connection-warning')).toHaveCount(0);
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({
            json: { data: { status: 'disconnected', phone: null } },
        }),
    );
    await page.reload();
    await expect(page.locator('.connection-warning')).toContainText(
        'WhatsApp desconectado',
    );
    await expect(
        page
            .locator('.connection-warning')
            .getByRole('link', { name: 'Configurar conexão' }),
    ).toBeVisible();
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({
            json: {
                data: {
                    status: 'connected',
                    phone: null,
                    provider_available: false,
                },
            },
        }),
    );
    await page.reload();
    await expect(page.locator('.connection-warning')).toContainText(
        'WhatsApp indisponível',
    );
});

test('message composer aligns controls and highlights the text field across layouts and themes', async ({
    page,
}, testInfo) => {
    await login(page);

    for (const theme of ['light', 'dark'] as const) {
        await page.goto('/settings/appearance');
        const option = page.getByRole('radio', {
            name:
                theme === 'light'
                    ? 'Claro Sempre usar o tema claro'
                    : 'Escuro Sempre usar o tema escuro',
        });

        if (!(await option.isChecked())) {
            await option.check();
            await expect(
                page.getByText('Preferência salva.', { exact: true }),
            ).toBeVisible();
        }

        await page.goto('/dashboard');

        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({ width, height: 844 });

            if (!(await page.locator('.composer').isVisible())) {
                await page
                    .getByRole('button')
                    .filter({ hasText: thread.contact.display_name })
                    .click();
            }

            const field = page.getByRole('textbox', {
                name: 'Mensagem para o contato',
            });
            const attach = page.locator('.composer .attach');
            const voice = page.getByRole('button', {
                name: 'Gravar mensagem de voz',
            });
            const send = page.getByRole('button', {
                name: 'Enviar mensagem',
            });
            await field.fill('');
            await expect(send).toBeDisabled();
            await expect(
                page.locator('.composer input[type="file"]'),
            ).toBeEnabled();
            await expect(voice).toBeEnabled();

            const composerBox = (await page
                .locator('.composer')
                .boundingBox())!;
            const attachBox = (await attach.boundingBox())!;
            const fieldBox = (await field.boundingBox())!;
            const voiceBox = (await voice.boundingBox())!;
            const sendBox = (await send.boundingBox())!;
            const controlSize = width < 1024 ? 44 : 40;
            expect(fieldBox.height).toBe(controlSize);
            expect(attachBox.width).toBeGreaterThanOrEqual(controlSize);
            expect(voiceBox.width).toBeGreaterThanOrEqual(controlSize);
            expect(sendBox.width).toBeGreaterThanOrEqual(controlSize);
            expect(attachBox.x + attachBox.width).toBeLessThan(fieldBox.x);
            expect(fieldBox.x + fieldBox.width).toBeLessThan(voiceBox.x);
            expect(voiceBox.x + voiceBox.width).toBeLessThan(sendBox.x);
            expect(sendBox.x + sendBox.width).toBeLessThanOrEqual(
                composerBox.x + composerBox.width,
            );

            for (const buttonBox of [attachBox, voiceBox, sendBox]) {
                expect(
                    Math.abs(
                        buttonBox.y +
                            buttonBox.height -
                            (fieldBox.y + fieldBox.height),
                    ),
                ).toBeLessThanOrEqual(1);
            }

            const restingBorder = await field.evaluate((element) => {
                element.blur();

                return getComputedStyle(element).borderColor;
            });
            await field.focus();
            const focusedStyle = await field.evaluate((element) => {
                const style = getComputedStyle(element);

                return {
                    border: style.borderColor,
                    outline: style.outlineStyle,
                };
            });
            expect(focusedStyle.border).not.toBe(restingBorder);
            expect(focusedStyle.outline).toBe('solid');

            await field.fill(
                Array.from({ length: 12 }, (_, i) => `Linha ${i + 1}`).join(
                    '\n',
                ),
            );
            await expect(send).toBeEnabled();
            const expandedBox = (await field.boundingBox())!;
            expect(expandedBox.height).toBeGreaterThan(fieldBox.height);
            expect(expandedBox.height).toBeLessThanOrEqual(120);
            const expandedBottom = expandedBox.y + expandedBox.height;

            for (const button of [attach, voice, send]) {
                const box = (await button.boundingBox())!;
                expect(
                    Math.abs(box.y + box.height - expandedBottom),
                ).toBeLessThanOrEqual(1);
            }

            await noOverflow(page);

            if (width === 320 || width === 1440) {
                await field.fill('');
                await page.screenshot({
                    animations: 'disabled',
                    path: testInfo.outputPath(`composer-${theme}-${width}.png`),
                });
            }
        }
    }

    const field = page.getByRole('textbox', {
        name: 'Mensagem para o contato',
    });
    await field.fill('Primeira linha');
    await field.press('Shift+Enter');
    await expect(field).toHaveValue('Primeira linha\n');

    await field.fill('Mensagem pelo teclado');
    const keyboardRequest = page.waitForRequest((request) =>
        request.url().endsWith('/send'),
    );
    await field.press('Enter');
    expect((await keyboardRequest).postDataJSON()).toMatchObject({
        text: 'Mensagem pelo teclado',
    });
    await expect(field).toHaveValue('');

    await field.fill('Mensagem pelo botão');
    const buttonRequest = page.waitForRequest((request) =>
        request.url().endsWith('/send'),
    );
    await page.getByRole('button', { name: 'Enviar mensagem' }).click();
    expect((await buttonRequest).postDataJSON()).toMatchObject({
        text: 'Mensagem pelo botão',
    });
    await expect(field).toHaveValue('');
});

test('agent navigation follows permissions and workspace switch stays available', async ({
    page,
}) => {
    await login(page, 'agent@example.test');
    await expect(
        page.getByRole('link', { name: 'Fluxos', exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await expect(
        page.getByRole('link', { name: 'WhatsApp', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('link', { name: 'Equipe', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('link', { name: 'Workspace', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: /Workspace atual:/ }),
    ).toBeVisible();
});

test('workspace menu contains only workspaces and admin can rename the current one', async ({
    page,
}) => {
    await login(page);
    const navigation = page.locator('.desktop-navigation');
    await navigation.getByRole('button', { name: /Workspace atual:/ }).click();
    const menu = page.getByRole('menu');
    await expect(menu.getByRole('menuitem')).toHaveCount(2);
    await expect(menu).toContainText('Workspace de revisão visual');
    await expect(menu).toContainText('Outro workspace');
    await expect(menu).not.toContainText('Admin de teste');
    await expect(menu).not.toContainText('Meu perfil');

    await page.keyboard.press('Escape');
    await page
        .getByRole('link', { name: 'Configurações', exact: true })
        .click();
    await page.getByRole('link', { name: 'Workspace', exact: true }).click();
    await page
        .getByRole('textbox', { name: 'Nome do workspace' })
        .fill('Workspace atualizado');
    await page.getByRole('button', { name: 'Salvar alterações' }).click();
    await expect(page.getByRole('status')).toHaveText('Workspace atualizado.');
    await expect(
        navigation.getByRole('button', {
            name: /Workspace atual: Workspace atualizado/,
        }),
    ).toBeVisible();
    await expect(navigation.locator('.user-avatar')).toHaveText('W');
});

test('shared editor controls preserve text formatting and numeric values when saving', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/flows/1');
    await page
        .getByRole('button', { name: 'Adicionar bloco', exact: true })
        .click();
    await page.getByRole('button', { name: 'Conteúdo', exact: true }).click();
    await page.getByRole('button', { name: 'Texto', exact: true }).click();
    const message = page.getByRole('textbox', {
        name: 'Texto da mensagem 1',
        exact: true,
    });
    await message.fill('Mensagem de teste');
    await message.press('ControlOrMeta+a');
    await page.getByRole('button', { name: 'Negrito', exact: true }).click();
    await expect(message).toHaveText('*Mensagem de teste*');
    await page.getByRole('button', { name: 'Atraso', exact: true }).click();
    await page.getByLabel('Aguardar antes do próximo item').fill('7');
    const saving = page.waitForRequest(
        (request) =>
            request.method() === 'PUT' && /\/flows\/1$/.test(request.url()),
    );
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    const payload = (await saving).postDataJSON();
    const content = payload.graph.nodes.find(
        (node: any) => node.type === 'content',
    );
    expect(content.data.items[0].text).toBe('*Mensagem de teste*');
    expect(
        content.data.items.find((item: any) => item.kind === 'delay').seconds,
    ).toBe(7);
    await expect(
        page.getByText('Todas as alterações salvas', { exact: true }),
    ).toBeVisible();
});

test('block name editing is internal, validated and reversible; descriptions persist across selection and save', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/flows/1');
    const start = page.locator('.flow-node.node-start');
    await start.click();
    const name = page.getByLabel('Nome interno do bloco', { exact: true });
    const description = page.getByLabel('Descrição interna — opcional', {
        exact: true,
    });
    await expect(name).toHaveCount(0);
    await expect(description).toBeHidden();
    await page
        .getByRole('button', {
            name: 'Editar nome interno do bloco',
            exact: true,
        })
        .click();
    await expect(name).toBeFocused();
    await expect(name).toHaveAttribute('maxlength', '120');
    await name.fill('   ');
    await name.press('Enter');
    await expect(page.getByRole('alert')).toHaveText(
        'Informe um nome de até 120 caracteres.',
    );
    await expect(
        page.getByRole('button', { name: 'Salvar', exact: true }),
    ).toBeDisabled();
    await expect(start.locator('.node-header strong')).toHaveText('Início');
    await name.fill('  Nome interno curto  ');
    await name.press('Enter');
    await expect(name).toHaveCount(0);
    await expect(start.locator('.node-header strong')).toHaveText(
        'Nome interno curto',
    );
    await expect(
        page.getByRole('button', {
            name: 'Editar nome interno do bloco',
            exact: true,
        }),
    ).toBeFocused();
    await page
        .getByRole('button', {
            name: 'Editar nome interno do bloco',
            exact: true,
        })
        .click();
    await name.fill('Nome a descartar');
    await name.press('Escape');
    await expect(start.locator('.node-header strong')).toHaveText(
        'Nome interno curto',
    );
    await page
        .getByRole('button', {
            name: 'Editar nome interno do bloco',
            exact: true,
        })
        .click();
    await name.fill('Nome confirmado por blur');
    await page.getByText('Organização interna', { exact: true }).click();
    await expect(start.locator('.node-header strong')).toHaveText(
        'Nome confirmado por blur',
    );
    await expect(description).toHaveValue('Início do fluxo');
    await description.fill('Observação interna preservada');
    await page
        .getByRole('button', { name: 'Adicionar bloco', exact: true })
        .click();
    await page.getByRole('button', { name: 'Conteúdo', exact: true }).click();
    await expect(description).toBeHidden();
    await expect(
        page.getByRole('heading', {
            name: 'Mensagens para o contato',
            exact: true,
        }),
    ).toBeVisible();
    await start.click();
    await expect(description).toBeHidden();
    await page.getByText('Organização interna', { exact: true }).click();
    await expect(description).toHaveValue('Observação interna preservada');
    const saving = page.waitForRequest(
        (request) =>
            request.method() === 'PUT' && /\/flows\/1$/.test(request.url()),
    );
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    const payload = (await saving).postDataJSON();
    const savedStart = payload.graph.nodes.find(
        (node: any) => node.type === 'start',
    );
    expect(savedStart.data.label).toBe('Nome confirmado por blur');
    expect(savedStart.data.description).toBe('Observação interna preservada');
    await expect(
        page.getByText('Todas as alterações salvas', { exact: true }),
    ).toBeVisible();
    await page.reload();
    await start.click();
    await expect(description).toBeHidden();
    await page.getByText('Organização interna', { exact: true }).click();
    await expect(description).toHaveValue('Observação interna preservada');
    await expect(start).not.toContainText('Observação interna preservada');
});

test('canvas distinguishes messages, media and waits, limits previews and never shows internal descriptions', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/flows/2');
    const content = page.locator('.flow-node.node-content');
    await expect(content.locator('.preview-item')).toHaveCount(2);
    await expect(content.locator('.message-preview')).toContainText(
        'Olá! Esta é uma mensagem para o contato.',
    );
    await expect(content.locator('.preview-label').first()).toHaveText(
        'Mensagem',
    );
    await expect(content.locator('.preview-label').last()).toHaveText('Imagem');
    await expect(content).toContainText('catalogo.png');
    await expect(content).toContainText('+ 2 itens na sequência');
    await expect(content).not.toContainText('DESCRIÇÃO INTERNA EXISTENTE');
    expect(
        await content
            .locator('.message-preview .preview-text')
            .evaluate((element) => element.scrollHeight > element.clientHeight),
    ).toBe(true);
    await content.click();
    await expect(
        page.getByLabel('Descrição interna — opcional', { exact: true }),
    ).toBeHidden();
    await page
        .getByRole('button', { name: 'Remover item 1', exact: true })
        .click();
    await expect(content.locator('.preview-item')).toHaveCount(2);
    await expect(content.locator('.preview-label').first()).toHaveText(
        'Imagem',
    );
    await expect(content.locator('.preview-label').last()).toHaveText('Espera');
    await expect(content).toContainText('Aguardar 7 segundos');
    await expect(content).toContainText('+ 1 item na sequência');
});

for (const width of [390, 1440]) {
    test(`block hierarchy is clear in both themes at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await login(page);

        for (const theme of ['light', 'dark'] as const) {
            await page.goto('/settings/appearance');
            const option = page.getByRole('radio', {
                name:
                    theme === 'light'
                        ? 'Claro Sempre usar o tema claro'
                        : 'Escuro Sempre usar o tema escuro',
            });

            if (!(await option.isChecked())) {
                await option.check();
                await expect(
                    page.getByText('Preferência salva.', { exact: true }),
                ).toBeVisible();
            }

            await page.goto('/workspaces/1/flows/2');
            await page
                .getByRole('button', {
                    name: 'Ajustar visualização',
                    exact: true,
                })
                .click();
            const content = page.locator('.flow-node.node-content');
            await content.click();
            await expect(
                page.getByRole('heading', {
                    name: 'Mensagens para o contato',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                page.getByLabel('Descrição interna — opcional', {
                    exact: true,
                }),
            ).toBeHidden();
            await noOverflow(page);
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(`block-panel-${theme}-${width}.png`),
            });
            await page
                .getByRole('button', { name: 'Fechar painel', exact: true })
                .click();
            await expect(content).toBeVisible();
            await page.screenshot({
                animations: 'disabled',
                path: testInfo.outputPath(`block-canvas-${theme}-${width}.png`),
            });
        }
    });
}

test('empty blocks and configured actions show useful summaries instead of descriptions or prompts', async ({
    page,
}) => {
    await login(page);
    await page.goto('/workspaces/1/flows/2');
    async function add(kind: string) {
        await page
            .getByRole('button', { name: 'Adicionar bloco', exact: true })
            .click();
        await page.getByRole('button', { name: kind, exact: true }).click();
    }
    await add('Conteúdo');
    await expect(page.locator('.flow-node.node-content').last()).toContainText(
        'Adicione uma mensagem ou arquivo',
    );
    await add('Aguardar Resposta');
    await expect(page.locator('.flow-node.node-wait_reply')).toContainText(
        'Prazo: 1 dia.',
    );
    await page.getByLabel('Prazo (segundos, de 60 a 604800)').fill('120');
    await expect(page.locator('.flow-node.node-wait_reply')).toContainText(
        'Prazo: 2 minutos.',
    );
    await add('Operações');
    await expect(page.locator('.flow-node.node-operations')).toContainText(
        'Adicione uma operação para este bloco.',
    );
    await page
        .getByRole('combobox', { name: /Adicionar operação/ })
        .selectOption('open_human');
    await expect(page.locator('.flow-node.node-operations')).toContainText(
        'Abrir atendimento humano',
    );
    await add('Condição');
    await expect(page.locator('.flow-node.node-condition')).toContainText(
        'Adicione uma verificação.',
    );
    await add('Ir para Fluxo');
    await expect(page.locator('.flow-node.node-jump_flow')).toContainText(
        'Escolha o fluxo de destino.',
    );
    await add('IA');
    await expect(page.locator('.flow-node.node-ai')).toContainText(
        'Defina as instruções para a conversa com IA.',
    );
    await page
        .getByLabel('Instruções da IA')
        .fill('INSTRUÇÃO INTERNA PARA O MODELO');
    await expect(page.locator('.flow-node.node-ai')).toContainText(
        'Conversa com IA configurada. Inatividade: 1 hora.',
    );
    await expect(page.locator('.flow-node.node-ai')).not.toContainText(
        'INSTRUÇÃO INTERNA PARA O MODELO',
    );
});

test('assistant permissions persist in the editor with overwrite disabled by default', async ({
    page,
}, testInfo) => {
    await login(page);
    await page.goto('/workspaces/1/flows/2');
    await page
        .getByRole('button', { name: 'Adicionar bloco', exact: true })
        .click();
    await page.getByRole('button', { name: 'IA', exact: true }).click();
    await page
        .getByLabel('Instruções da IA')
        .fill('Identifique o curso de interesse.');
    await page
        .getByRole('button', { name: 'Adicionar mídia', exact: true })
        .click();
    await page.route('**/workspaces/1/flows/2/assets', (route) =>
        route.fulfill({
            status: 201,
            json: {
                data: {
                    id: 9876,
                    media_type: 'image',
                    original_name: 'catalogo.png',
                    mime_type: 'image/png',
                    size: 68,
                },
            },
        }),
    );
    await page.getByLabel('Arquivo 1', { exact: true }).setInputFiles({
        name: 'catalogo.png',
        mimeType: 'image/png',
        buffer: Buffer.from('fixture'),
    });
    await expect(page.locator('.ai-resource strong')).toHaveText(
        'catalogo.png',
    );
    await page
        .getByLabel('Quando enviar a mídia 1', { exact: true })
        .fill('Quando solicitar o catálogo.');
    await page
        .getByRole('button', { name: 'Autorizar tag', exact: true })
        .click();
    await page
        .getByLabel('Tag autorizada 1', { exact: true })
        .selectOption({ label: 'Interesse IA' });
    await page
        .getByLabel('Quando aplicar a tag 1', { exact: true })
        .fill('Quando demonstrar interesse.');
    await page
        .getByRole('button', { name: 'Autorizar campo', exact: true })
        .click();
    await page
        .getByLabel('Campo autorizado 1', { exact: true })
        .selectOption({ label: 'Curso IA' });
    await page
        .getByLabel('Instruções do campo 1', { exact: true })
        .fill('Registrar o curso escolhido.');
    await expect(
        page.getByLabel('Permitir sobrescrever valor existente — campo 1'),
    ).not.toBeChecked();
    const response = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().endsWith('/workspaces/1/flows/2'),
    );
    await page.getByRole('button', { name: 'Salvar', exact: true }).click();
    const saved = await (await response).json();
    const assistant = saved.data.graph.nodes.find(
        (node: { type: string }) => node.type === 'ai',
    );
    expect(assistant.data.ai_media).toHaveLength(1);
    expect(assistant.data.ai_tags[0].instruction).toBe(
        'Quando demonstrar interesse.',
    );
    expect(assistant.data.ai_fields[0].allow_overwrite).toBe(false);
    await page.reload();
    await page.locator('.flow-node.node-ai').click();
    await expect(
        page.getByLabel('Instruções do campo 1', { exact: true }),
    ).toHaveValue('Registrar o curso escolhido.');
    await expect(page.locator('.ai-resource strong')).toContainText(
        'Arquivo indisponível',
    );
    await page
        .getByLabel('Permitir sobrescrever valor existente — campo 1')
        .check();
    await page.setViewportSize({ width: 390, height: 844 });
    await noOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('assistant-mobile.png'),
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Remover mídia 1', exact: true })
        .click();
    await expect(
        page.getByLabel('Quando enviar a mídia 1', { exact: true }),
    ).toHaveCount(0);
});

test('profile phone sanitizes pasted numbers, validates DDI and persists after reload', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/profile');
    const phone = page.getByLabel('Telefone / WhatsApp', { exact: true });
    await expect(phone).toHaveAttribute('required', '');
    await expect(phone).toHaveAttribute('inputmode', 'numeric');
    await phone.fill('11999999999');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(page.locator('#profile-phone-error')).toContainText(
        'telefone internacional válido com DDI',
    );
    await expect(phone).toHaveValue('11999999999');

    await phone.fill('');
    await page
        .context()
        .grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.evaluate(() =>
        navigator.clipboard.writeText('+55 (11) 99999-9999'),
    );
    await phone.focus();
    await phone.press('ControlOrMeta+V');
    await expect(phone).toHaveValue('5511999999999');
    await phone.press('End');
    await phone.press('a');
    await expect(phone).toHaveValue('5511999999999');
    await page
        .getByRole('button', { name: 'Salvar alterações', exact: true })
        .click();
    await expect(page.getByRole('status')).toHaveText('Perfil atualizado.');
    await page.reload();
    await expect(phone).toHaveValue('5511999999999');
});

async function expectFieldSpacing(page: Page) {
    const fields = page.locator('.settings-card .form-field');
    await expect(fields.first()).toBeVisible();
    const measurements = await fields.evaluateAll((elements) =>
        elements.map((element) => {
            const label = element
                .querySelector('label')!
                .getBoundingClientRect();
            const input = element
                .querySelector('input')!
                .getBoundingClientRect();

            return {
                top: element.getBoundingClientRect().top,
                bottom: element.getBoundingClientRect().bottom,
                labelGap: input.top - label.bottom,
            };
        }),
    );

    for (const [index, field] of measurements.entries()) {
        expect(field.labelGap).toBeCloseTo(8, 0);

        if (index) {
            expect(field.top - measurements[index - 1].bottom).toBeCloseTo(
                16,
                0,
            );
        }
    }

    const footer = await page
        .locator('.settings-card .form-actions')
        .boundingBox();
    expect(footer!.y - measurements.at(-1)!.bottom).toBeCloseTo(24, 0);
    await noOverflow(page);
}

for (const width of [390, 1440]) {
    test(`form spacing survives direct entry, reload and navigation at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });

        for (const path of ['/login', '/register', '/forgot-password']) {
            await page.goto(path);
            const card = page.locator('.auth-card');
            await expect(card).toHaveCSS('gap', '16px');

            for (const label of await card
                .locator('label:not(.remember)')
                .all()) {
                await expect(label).toHaveCSS('gap', '8px');
            }

            const submit = card.locator('button[type="submit"]');
            const gap = await submit.evaluate(
                (element) =>
                    element.getBoundingClientRect().top -
                    element.previousElementSibling!.getBoundingClientRect()
                        .bottom,
            );
            expect(gap).toBeCloseTo(24, 0);
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        window.innerWidth,
                ),
            ).toBe(true);
        }

        await login(page);

        for (const path of ['/settings/security', '/settings/profile']) {
            await page.goto(path);

            if (page.url().includes('/user/confirm-password')) {
                await page
                    .getByLabel('Senha', { exact: true })
                    .fill('password');
                await page
                    .getByRole('button', { name: 'Confirmar', exact: true })
                    .click();
            }

            await expectFieldSpacing(page);
            await page.screenshot({
                path: testInfo.outputPath(
                    path.endsWith('security') ? 'security.png' : 'profile.png',
                ),
                fullPage: true,
            });
            await page.reload();
            await expectFieldSpacing(page);
        }

        await page
            .getByRole('link', { name: 'Segurança', exact: true })
            .click();
        await expectFieldSpacing(page);
        await page.getByRole('link', { name: 'Perfil', exact: true }).click();
        await expectFieldSpacing(page);

        await page.goto('/settings/team');
        const trigger = page.getByRole('button', {
            name: 'Adicionar usuário',
            exact: true,
        });
        await trigger.click();
        const dialog = page.getByRole('dialog', {
            name: 'Adicionar usuário',
            exact: true,
        });
        const footer = dialog.locator('.form-actions');
        await expect(footer).toHaveCSS('gap', '12px');
        await expect(footer).toHaveCSS('margin-top', '24px');
        await expect(dialog.locator('.form-field').first()).toHaveCSS(
            'gap',
            '8px',
        );
        const cancel = footer.getByRole('button', {
            name: 'Cancelar',
            exact: true,
        });
        const submit = footer.locator('button[type="submit"]');
        // A long action label must wrap inside a narrow modal instead of overflowing.
        await submit.evaluate(
            (element) =>
                (element.textContent =
                    'Adicionar este usuário com acesso ao atendimento à equipe do workspace'),
        );
        await dialog.evaluate(async (element) => {
            await Promise.all(
                element.getAnimations().map((animation) => animation.finished),
            );
        });
        const box = await dialog.boundingBox();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(width);
        expect(
            await dialog.evaluate(
                (element) => element.scrollWidth <= element.clientWidth,
            ),
        ).toBe(true);
        const first = await cancel.boundingBox();
        const second = await submit.boundingBox();

        if (Math.abs(first!.y - second!.y) < 1) {
            expect(second!.x - first!.x - first!.width).toBeCloseTo(12, 0);
        } else {
            expect(second!.y - first!.y - first!.height).toBeGreaterThanOrEqual(
                12,
            );
        }

        await footer.scrollIntoViewIfNeeded();
        await page.screenshot({
            path: testInfo.outputPath('modal-long-action.png'),
        });
        await cancel.click();
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test(`standard HTML dialogs handle prompt, alert and confirmation at ${width}px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        const nativeDialogs: string[] = [];
        page.on('dialog', async (dialog) => {
            nativeDialogs.push(dialog.type());
            await dialog.dismiss();
        });
        await login(page);
        await page.goto('/workspaces/1/flows');
        const trigger = page
            .getByRole('button', { name: 'Novo fluxo', exact: true })
            .first();
        await trigger.click();
        let dialog = page.getByRole('dialog', {
            name: 'Nome do fluxo',
            exact: true,
        });
        const input = dialog.getByRole('textbox');
        await expect(input).toBeFocused();
        await expect(input).toHaveValue('Novo fluxo');
        await expect(dialog).toHaveCSS('max-width', '560px');
        await expect(dialog.locator('.form-actions')).toHaveCSS('gap', '12px');
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();

        let submittedName = '';
        await page.route('**/workspaces/1/flows', (route) => {
            if (route.request().method() !== 'POST') {
                return route.continue();
            }

            submittedName = route.request().postDataJSON().name;

            return route.fulfill({
                status: 422,
                json: { message: 'Não foi possível criar o fluxo de teste.' },
            });
        });
        await trigger.click();
        await input.fill('Novo nome de teste');
        await input.press('Enter');
        dialog = page.getByRole('dialog', { name: 'Aviso', exact: true });
        await expect(dialog).toContainText(
            'Não foi possível criar o fluxo de teste.',
        );
        expect(submittedName).toBe('Novo nome de teste');
        await dialog
            .getByRole('button', { name: 'Entendi', exact: true })
            .click();
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();

        const menu = page.getByRole('button', {
            name: 'Ações do fluxo Fluxo de teste',
            exact: true,
        });
        await menu.click();
        await page
            .getByRole('menuitem', { name: 'Excluir', exact: true })
            .click();
        dialog = page.getByRole('dialog', {
            name: 'Excluir fluxo',
            exact: true,
        });
        await expect(dialog).toBeVisible();
        await dialog
            .getByRole('button', { name: 'Cancelar', exact: true })
            .click();
        await expect(dialog).toBeHidden();
        await expect(
            page.getByRole('link').filter({ hasText: 'Fluxo de teste' }),
        ).toBeVisible();
        expect(nativeDialogs).toEqual([]);
    });
}

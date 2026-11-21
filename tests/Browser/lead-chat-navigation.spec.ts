import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.skip(
    process.env.CHAIT_BROWSER_REALTIME === 'true',
    'Uses the polling browser fixture.',
);

async function setup(page: Page, includeTarget = true) {
    const first = {
        id: 9001,
        status: 'bot',
        last_message_at: '2026-11-21T12:00:00Z',
        last_message_type: 'text',
        last_message_preview: 'Olá, preciso de ajuda com meu atendimento',
        unread_count: 0,
        unread_version: 0,
        assigned_to: null,
        contact: {
            id: 9001,
            display_name: 'Primeira conversa',
            phone: '5511999999001',
            email: null,
            cpf: null,
        },
        notes: [],
    };
    // The isolated browser seed contains one lead and its thread, both with ID 1.
    const target = {
        ...first,
        id: 1,
        contact: {
            ...first.contact,
            id: 1,
            display_name: 'Lead selecionado',
            phone: '5511999999002',
        },
    };
    const requestedIds: (string | null)[] = [];
    const histories: string[] = [];
    let available = true;
    let gate: Promise<void> | undefined;

    await page.route('**/workspaces/*/chat-access*', (route) =>
        route.fulfill({ json: { allowed: true, status: 'connected' } }),
    );
    await page.route('**/workspaces/*/uazapi/*', (route) =>
        route.fulfill({ json: { data: { status: 'connected' } } }),
    );
    await page.route('**/workspaces/*/conversations**', async (route) => {
        const url = new URL(route.request().url());

        if (url.pathname.endsWith('/messages')) {
            const id = url.pathname.split('/').at(-2)!;
            histories.push(id);

            return route.fulfill({
                json: {
                    data: [
                        {
                            id: `message-${id}`,
                            type: 'text',
                            text: `Histórico ${id}`,
                            from_me: false,
                        },
                    ],
                    read_version: 0,
                },
            });
        }

        if (!url.pathname.endsWith('/conversations')) {
            return route.fulfill({ json: { data: { url: null } } });
        }

        const requested = url.searchParams.get('thread_id');
        requestedIds.push(requested);
        const selected =
            requested === '1' ? (available ? target : null) : first;
        const data = includeTarget && available ? [first, target] : [first];
        await gate;

        return route.fulfill({
            json: { data, counts: { all: data.length, mine: 0, unassigned: data.length }, selected_thread: selected, next_cursor: null },
        });
    });
    await page.route(
        '**/workspaces/*/contacts/*/sequence-enrollment',
        (route) =>
            route.fulfill({
                json: { data: null, can_enroll: false, sequences: [] },
            }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Primeira conversa',
    );

    return {
        requestedIds,
        histories,
        unavailable: () => {
            available = false;
        },
        includeTarget: () => {
            includeTarget = true;
        },
        pause: () => {
            let release!: () => void;
            gate = new Promise<void>((resolve) => {
                release = resolve;
            });

            return release;
        },
        openLead: async () => {
            await page
                .getByRole('link', { name: 'Leads', exact: true })
                .click();
            await page.locator('.leads-list-row').first().click();
            await expect(
                page.getByRole('link', { name: 'Abrir conversa', exact: true }),
            ).toHaveAttribute('href', '/dashboard?thread_id=1');
        },
    };
}

for (const cachedTarget of [true, false]) {
    test(`opening a lead honors its thread with ${cachedTarget ? 'a cached target' : 'a target outside the cached page'}`, async ({
        page,
    }) => {
        const fixture = await setup(page, cachedTarget);
        await fixture.openLead();
        const historyStart = fixture.histories.length;
        const requestStart = fixture.requestedIds.length;
        const release = fixture.pause();

        try {
            await page
                .getByRole('link', { name: 'Abrir conversa', exact: true })
                .click();
            await expect
                .poll(() => fixture.requestedIds.slice(requestStart))
                .toContain('1');
            await expect(page.locator('.thread')).toHaveCount(
                cachedTarget ? 2 : 1,
            );

            if (cachedTarget) {
                await expect(page.locator('.chat-heading .title b')).toHaveText(
                    'Lead selecionado',
                );
            } else {
                await expect(page.locator('.chat-heading')).toHaveCount(0);
            }

            expect(fixture.histories.slice(historyStart)).not.toContain('9001');
        } finally {
            release();
        }

        await expect(page.locator('.chat-heading .title b')).toHaveText(
            'Lead selecionado',
        );
        await expect(
            page.getByText('Histórico 1', { exact: true }),
        ).toBeVisible();
    });
}

test('a manual choice survives the pending lead response', async ({ page }) => {
    const fixture = await setup(page, false);
    await fixture.openLead();
    fixture.includeTarget();
    const requestStart = fixture.requestedIds.length;
    const release = fixture.pause();

    try {
        await page
            .getByRole('link', { name: 'Abrir conversa', exact: true })
            .click();
        await expect
            .poll(() => fixture.requestedIds.slice(requestStart))
            .toContain('1');
        await page
            .locator('.thread')
            .filter({ hasText: 'Primeira conversa' })
            .click();
        await expect(page.locator('.chat-heading .title b')).toHaveText(
            'Primeira conversa',
        );
    } finally {
        release();
    }

    await expect(page.locator('.thread')).toHaveCount(2);
    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Primeira conversa',
    );
});

test('an unavailable lead conversation does not fall back to the first cached chat', async ({
    page,
}) => {
    const fixture = await setup(page, false);
    await fixture.openLead();
    fixture.unavailable();
    await page
        .getByRole('link', { name: 'Abrir conversa', exact: true })
        .click();
    await expect(page.locator('.thread')).toHaveCount(1);
    await expect(page.locator('.chat-heading')).toHaveCount(0);
    await expect(
        page.getByRole('heading', { name: 'Selecione uma conversa' }),
    ).toBeVisible();
});

test('opening a lead on mobile shows its conversation pane', async ({
    page,
}) => {
    const fixture = await setup(page);
    await fixture.openLead();
    await page.setViewportSize({ width: 390, height: 844 });
    await page
        .getByRole('link', { name: 'Abrir conversa', exact: true })
        .click();
    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Lead selecionado',
    );
    await expect(page.locator('.chat-pane')).toBeVisible();
    await expect(page.getByText('Histórico 1', { exact: true })).toBeVisible();
    const navigation = page.getByRole('navigation', {
        name: 'Navegação inferior',
    });
    await expect(navigation).toBeHidden();
    await expect(page.locator('.app-topbar')).toBeHidden();
    await page
        .getByRole('button', { name: 'Voltar para conversas', exact: true })
        .click();
    await expect(navigation).toBeVisible();
    await expect(page.locator('.list-pane')).toBeVisible();
    await page.locator('.thread').first().click();
    await expect(navigation).toBeHidden();
    await page.goBack();
    await expect(navigation).toBeVisible();
    await expect(page.locator('.app-topbar')).toBeVisible();
});

test('a direct mobile link opens the requested conversation with an empty cache', async ({
    page,
}) => {
    const fixture = await setup(page, false);
    await fixture.openLead();
    await page.evaluate(async () => {
        const database = await new Promise<IDBDatabase>((resolve, reject) => {
            const request = indexedDB.open('chait-chat-cache', 1);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        await new Promise<void>((resolve, reject) => {
            const transaction = database.transaction('entries', 'readwrite');
            transaction.objectStore('entries').clear();
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
        });
        database.close();
    });
    await page.setViewportSize({ width: 390, height: 844 });
    const requestStart = fixture.requestedIds.length;
    const release = fixture.pause();

    try {
        await page.goto('/dashboard?thread_id=1');
        await expect
            .poll(() => fixture.requestedIds.slice(requestStart))
            .toContain('1');
        await expect(page.locator('.thread')).toHaveCount(0);
        await expect(page.locator('.chat-heading')).toHaveCount(0);
    } finally {
        release();
    }

    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Lead selecionado',
    );
    await expect(page.getByText('Histórico 1', { exact: true })).toBeVisible();
});

test('opening the chat without a thread still selects the first cached conversation', async ({
    page,
}) => {
    const fixture = await setup(page);
    await fixture.openLead();
    await page.getByRole('link', { name: 'Conversas', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.locator('.chat-heading .title b')).toHaveText(
        'Primeira conversa',
    );
});

test('drafts belong to each conversation and survive a reload', async ({ page }) => {
    await setup(page);
    const composer = page.locator('textarea').first();
    await composer.fill('Rascunho da primeira conversa');
    await page.locator('.thread').filter({ hasText: 'Lead selecionado' }).click();
    await expect(composer).toHaveValue('');
    await composer.fill('Rascunho do lead');
    await page.locator('.thread').filter({ hasText: 'Primeira conversa' }).click();
    await expect(composer).toHaveValue('Rascunho da primeira conversa');
    await page.reload();
    await expect(composer).toHaveValue('Rascunho da primeira conversa');
});

test('conversation list remains usable at the review sizes in both themes', async ({ page }) => {
    await setup(page);
    for (const theme of ['light', 'dark']) {
        await page.evaluate((value) => document.documentElement.classList.toggle('dark', value === 'dark'), theme);
        for (const width of [390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            if (width < 1024 && await page.getByRole('button', { name: 'Voltar para conversas', exact: true }).isVisible()) {
                await page.getByRole('button', { name: 'Voltar para conversas', exact: true }).click();
            }
            await expect(page.getByRole('group', { name: 'Atribuição das conversas' })).toBeVisible();
            await expect(page.getByRole('button', { name: /^Não atribuídos/ })).toBeVisible();
            await page.waitForTimeout(250);
            await page.screenshot({ path: `artifacts/design-review/conversas-etapa2-${width}-${theme}.png` });
        }
    }
});

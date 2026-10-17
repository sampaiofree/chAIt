import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

type Automation = {
    state: string;
    pause_reasons: string[];
    execution_status: string | null;
    can_pause: boolean;
    can_resume: boolean;
    expires_at: string | null;
};
const allowed: Automation = {
    state: 'allowed',
    pause_reasons: [],
    execution_status: 'running',
    can_pause: true,
    can_resume: false,
    expires_at: null,
};
async function setup(
    page: Page,
    initial: Automation | null = allowed,
    status = 'bot',
) {
    let automation = initial ? { ...initial } : null;
    const requests: Record<string, string>[] = [];
    let fail = false;
    let release: (() => void) | undefined;
    let pending = false;
    const thread = () => ({
        id: 1,
        status,
        last_message_at: '2026-10-14T15:00:00Z',
        unread_count: 0,
        unread_version: 0,
        assigned_to: null,
        contact: {
            id: 1,
            display_name: 'Contato de teste',
            phone: '+5511999999999',
            email: null,
            cpf: null,
            tags: [],
            custom_fields: [],
        },
        notes: [],
        automation,
    });
    await page.route('**/workspaces/*/uazapi/status', (r) =>
        r.fulfill({
            json: { data: { status: 'connected', provider_available: true } },
        }),
    );
    await page.route('**/workspaces/*/conversations**', async (r) => {
        const path = new URL(r.request().url()).pathname;

        if (path.endsWith('/automations/pause')) {
            requests.push(r.request().postDataJSON());

            if (pending) {
                await new Promise<void>((resolve) => {
                    release = resolve;
                });
            }

            if (fail) {
                return r.fulfill({
                    status: 422,
                    json: {
                        message: 'Prazo inválido',
                        errors: {
                            expires_at: [
                                'Escolha uma data e um horário futuros.',
                            ],
                        },
                    },
                });
            }

            const payload = requests.at(-1)!;
            automation = {
                ...allowed,
                state: 'paused',
                pause_reasons: ['manual'],
                can_pause: false,
                can_resume: true,
                expires_at: payload.expires_at
                    ? `${payload.expires_at}:00-03:00`
                    : null,
            };

            return r.fulfill({ json: { data: thread() } });
        }

        if (path.endsWith('/automations/resume')) {
            automation = { ...allowed };

            return r.fulfill({ json: { data: thread() } });
        }

        if (path.endsWith('/messages')) {
            return r.fulfill({
                json: { data: [], history_limited: false, read_version: 0 },
            });
        }

        if (path.endsWith('/avatar')) {
            return r.fulfill({ json: { data: { url: null } } });
        }

        if (path.endsWith('/read')) {
            return r.fulfill({
                json: { data: { unread_count: 0, unread_version: 0 } },
            });
        }

        return r.fulfill({
            json: { data: [thread()], selected_thread: thread() },
        });
    });
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('button', { name: 'Abrir perfil do contato' }).click();
    const region = page.getByRole('region', { name: 'Automação', exact: true });
    await expect(region).toBeVisible();

    return {
        region,
        requests,
        fail: () => {
            fail = true;
        },
        hold: () => {
            pending = true;
        },
        release: () => release?.(),
    };
}

test.use({ timezoneId: 'Asia/Tokyo' });

for (const scenario of [
    {
        name: 'idle bot',
        status: 'bot',
        automation: { ...allowed, execution_status: null },
        label: 'Bot ativo',
    },
    {
        name: 'completed attendance',
        status: 'completed',
        automation: allowed,
        label: 'Bot ativo',
    },
    {
        name: 'legacy open attendance without a human block',
        status: 'open',
        automation: allowed,
        label: 'Bot ativo',
    },
    {
        name: 'waiting human attendance',
        status: 'open',
        automation: { ...allowed, state: 'paused', pause_reasons: ['human'] },
        label: 'Humanizado',
    },
    {
        name: 'ongoing human attendance with a manual pause',
        status: 'in_progress',
        automation: {
            ...allowed,
            state: 'paused',
            pause_reasons: ['manual', 'human'],
        },
        label: 'Humanizado',
    },
    {
        name: 'completed attendance with a manual pause',
        status: 'completed',
        automation: { ...allowed, state: 'paused', pause_reasons: ['manual'] },
        label: 'Bot pausado',
    },
    {
        name: 'unavailable automation',
        status: 'bot',
        automation: { ...allowed, state: 'unavailable' },
        label: null,
    },
    {
        name: 'missing automation summary',
        status: 'bot',
        automation: null,
        label: null,
    },
]) {
    test(`chat badges reflect ${scenario.name} in the list and header`, async ({
        page,
    }) => {
        await setup(page, scenario.automation, scenario.status);

        for (const badge of [
            page.locator('.thread-bottom em'),
            page.locator('.chat-heading .actions em'),
        ]) {
            if (scenario.label) {
                await expect(badge).toHaveText(scenario.label);
            } else {
                await expect(badge).toHaveCount(0);
            }
        }

        if (scenario.status === 'completed') {
            await expect(
                page.getByRole('button', {
                    name: 'Abrir atendimento',
                    exact: true,
                }),
            ).toBeVisible();
        }
    });
}

test('disconnecting hides both chat badges and filters expose only all or open attendance', async ({
    page,
}) => {
    await page.clock.install();
    await setup(page);
    const badges = page.locator('.thread-bottom em, .chat-heading .actions em');
    await expect(badges).toHaveText(['Bot ativo', 'Bot ativo']);
    await expect(
        page
            .getByRole('combobox', { name: 'Status', exact: true })
            .locator('option'),
    ).toHaveText(['Todos', 'Abertos']);
    await expect(
        page
            .getByRole('combobox', { name: 'Atribuição', exact: true })
            .locator('option'),
    ).toHaveText(['Todos', 'Meus chats', 'Não atribuídos']);
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({ json: { data: { status: 'disconnected' } } }),
    );
    await page.clock.fastForward(15000);
    await expect(
        page.getByText('Conecte o WhatsApp para carregar as conversas.'),
    ).toBeVisible();
    await expect(badges).toHaveCount(0);
});

test('native choices hide dates and send indefinite pause with no deadline, even after custom input', async ({
    page,
}) => {
    const { region, requests } = await setup(page);
    const badges = page.locator('.thread-bottom em, .chat-heading .actions em');
    await expect(badges).toHaveText(['Bot ativo', 'Bot ativo']);

    for (const fillCustom of [false, true]) {
        await region
            .getByRole('button', { name: 'Pausar automação', exact: true })
            .click();
        await expect(
            region.getByRole('radio', { name: '30 minutos', exact: true }),
        ).toBeChecked();
        await expect(
            region.getByRole('radio', { name: '30 minutos', exact: true }),
        ).toBeFocused();
        await region
            .getByRole('radio', { name: '1 hora', exact: true })
            .check();
        await expect(
            region.getByRole('radio', { name: '1 hora', exact: true }),
        ).toBeChecked();

        if (fillCustom) {
            await region
                .getByRole('radio', {
                    name: 'Escolher data e horário',
                    exact: true,
                })
                .check();
            await region.getByLabel('Data', { exact: true }).fill('2026-10-20');
            await region.getByLabel('Horário', { exact: true }).fill('14:30');
        }

        await region
            .getByRole('radio', { name: 'Sem prazo', exact: true })
            .check();
        await expect(region.getByLabel('Data', { exact: true })).toHaveCount(0);
        await region
            .getByRole('button', { name: 'Confirmar pausa', exact: true })
            .click();
        await expect(region).toContainText('Até retomar manualmente');
        await expect(badges).toHaveText(['Bot pausado', 'Bot pausado']);
        expect(requests.at(-1)).toEqual({ mode: 'indefinite' });
        await expect(
            region.getByRole('button', {
                name: 'Retomar automação',
                exact: true,
            }),
        ).toBeFocused();
        await region
            .getByRole('button', { name: 'Retomar automação', exact: true })
            .click();
        await expect(region).toContainText('Liberada');
        await expect(badges).toHaveText(['Bot ativo', 'Bot ativo']);
    }
});

for (const [label, expected] of [
    ['30 minutos', '2026-10-15T00:21'],
    ['1 hora', '2026-10-15T00:51'],
]) {
    test(`shortcut ${label} uses workspace timezone and submit time across midnight`, async ({
        page,
    }) => {
        const { region, requests } = await setup(page);
        await page.clock.setFixedTime(new Date('2026-10-15T02:40:01Z'));
        await region
            .getByRole('button', { name: 'Pausar automação', exact: true })
            .click();
        await region.getByRole('radio', { name: label, exact: true }).check();
        await page.clock.setFixedTime(new Date('2026-10-15T02:50:01Z'));
        await region
            .getByRole('button', { name: 'Confirmar pausa', exact: true })
            .click();
        await expect.poll(() => requests.length).toBe(1);
        expect(requests[0]).toEqual({ mode: 'timed', expires_at: expected });
        await expect(region).toContainText('Fuso: America/Sao_Paulo');
    });
}

test('custom fields validate and retain values on error; switching clears error; cancel restores focus and defaults', async ({
    page,
}) => {
    const controls = await setup(page);
    const { region, requests } = controls;
    await region
        .getByRole('button', { name: 'Pausar automação', exact: true })
        .click();
    await region
        .getByRole('radio', { name: '30 minutos', exact: true })
        .press('ArrowDown');
    await expect(
        region.getByRole('radio', { name: '1 hora', exact: true }),
    ).toBeChecked();
    await region
        .getByRole('radio', { name: 'Escolher data e horário', exact: true })
        .check();
    await region
        .getByRole('button', { name: 'Confirmar pausa', exact: true })
        .click();
    expect(requests).toHaveLength(0);
    await region.getByLabel('Data', { exact: true }).fill('2020-01-01');
    await region.getByLabel('Horário', { exact: true }).fill('14:30');
    controls.fail();
    await region
        .getByRole('button', { name: 'Confirmar pausa', exact: true })
        .click();
    await expect(region.getByRole('alert')).toHaveText(
        'Escolha uma data e um horário futuros.',
    );
    await expect(region.getByRole('alert')).toBeFocused();
    await expect(region.getByLabel('Data', { exact: true })).toHaveValue(
        '2020-01-01',
    );
    expect(requests[0]).toEqual({
        mode: 'timed',
        expires_at: '2020-01-01T14:30',
    });
    await region.getByRole('radio', { name: 'Sem prazo', exact: true }).check();
    await expect(region.getByRole('alert')).toBeHidden();
    await region.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(
        region.getByRole('button', { name: 'Pausar automação', exact: true }),
    ).toBeFocused();
    await region
        .getByRole('button', { name: 'Pausar automação', exact: true })
        .click();
    await expect(
        region.getByRole('radio', { name: '30 minutos', exact: true }),
    ).toBeChecked();
});

test('loading disables all inputs and prevents duplicate submissions', async ({
    page,
}) => {
    const controls = await setup(page);
    controls.hold();
    await controls.region
        .getByRole('button', { name: 'Pausar automação', exact: true })
        .click();
    await controls.region
        .getByRole('button', { name: 'Confirmar pausa', exact: true })
        .click();
    await expect(
        controls.region.getByRole('button', { name: 'Pausando…' }),
    ).toBeDisabled();
    await expect(
        controls.region.getByRole('radio', { name: '30 minutos', exact: true }),
    ).toBeDisabled();
    await controls.region.locator('form').evaluate((form) => {
        form.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );
    });
    expect(controls.requests).toHaveLength(1);
    controls.release();
    await expect(
        controls.region.getByRole('button', {
            name: 'Retomar automação',
            exact: true,
        }),
    ).toBeVisible();
});

for (const state of [
    {
        ...allowed,
        state: 'paused',
        pause_reasons: ['manual'],
        can_pause: false,
        can_resume: true,
        expires_at: '2026-10-20T17:30:00Z',
    },
    {
        ...allowed,
        state: 'paused',
        pause_reasons: ['human', 'manual'],
        can_pause: false,
        can_resume: false,
    },
    {
        ...allowed,
        state: 'paused',
        pause_reasons: ['human'],
        can_resume: false,
    },
    { ...allowed, state: 'unavailable', can_pause: false },
]) {
    test(`status ${state.state} reasons ${state.pause_reasons.join(',')} respects permissions`, async ({
        page,
    }) => {
        const { region } = await setup(page, state);
        await expect(
            region.getByRole('button', {
                name: 'Retomar automação',
                exact: true,
            }),
        ).toHaveCount(state.can_resume ? 1 : 0);
        await expect(
            region.getByRole('button', {
                name: 'Pausar automação',
                exact: true,
            }),
        ).toHaveCount(state.can_pause ? 1 : 0);

        if (state.expires_at) {
            await expect(region).toContainText('Até 20/10/2026 às 14:30');
        }

        if (state.pause_reasons.includes('human')) {
            await expect(region).toContainText(
                'Finalize o atendimento humano para liberar a automação.',
            );
        }

        if (state.state === 'unavailable') {
            await expect(region).toContainText(
                'Indisponível sem uma conexão do WhatsApp.',
            );
        }
    });
}

for (const theme of ['light', 'dark'] as const) {
    test(`mobile layout and ${theme} theme fit the panel`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: theme });
        const { region } = await setup(page);
        await region
            .getByRole('button', { name: 'Pausar automação', exact: true })
            .click();
        await region
            .getByRole('radio', {
                name: 'Escolher data e horário',
                exact: true,
            })
            .check();
        await page.evaluate(
            (theme) =>
                document.documentElement.classList.toggle(
                    'dark',
                    theme === 'dark',
                ),
            theme,
        );
        await region.screenshot({
            path: `artifacts/automation-pause-desktop-${theme}.png`,
        });
        await page.setViewportSize({ width: 390, height: 900 });
        await page
            .getByRole('button')
            .filter({ hasText: 'Contato de teste' })
            .click();
        await page.getByRole('button', { name: /perfil do contato/ }).click();
        await expect(region).toBeVisible();
        await region.screenshot({
            path: `artifacts/automation-pause-mobile-${theme}.png`,
        });
        expect(
            await page
                .locator('.profile-pane')
                .evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        await expect(
            region.getByRole('button', {
                name: 'Confirmar pausa',
                exact: true,
            }),
        ).toBeVisible();
    });
}

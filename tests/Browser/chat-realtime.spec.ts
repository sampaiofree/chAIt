import { expect, test } from '@playwright/test';
import type { Page, WebSocketRoute } from '@playwright/test';

// Run with CHAIT_BROWSER_REALTIME=true; the Pusher transport and UAZAPI-facing routes are simulated.
test.skip(
    process.env.CHAIT_BROWSER_REALTIME !== 'true',
    'Requires the isolated realtime browser fixture.',
);

async function setup(page: Page, holdHistory = false, holdConnection = false) {
    let socket: WebSocketRoute | undefined;
    let channel = '';
    let allowConnection = true;
    let access = { allowed: true, status: 'connected' };
    let workspaceId = 0;
    let historyCalls = 0;
    let statusCalls = 0;
    let listCalls = 0;
    let avatarCalls = 0;
    let releaseConnection: () => void = () => {};
    const connectionGate = new Promise<void>((resolve) => {
        releaseConnection = resolve;
    });
    const thread = {
        id: 1,
        status: 'in_progress',
        last_message_at: '2026-10-16T12:00:00Z',
        last_message_preview: '<script>literal preview</script>',
        last_message_type: 'text',
        revision: 1,
        unread_count: 0,
        unread_version: 0,
        assigned_to: { id: 1, name: 'Admin de teste' },
        contact: {
            id: 1,
            display_name: 'Contato em tempo real',
            phone: '+5527999999999',
            email: null,
            cpf: null,
            avatar_url: null,
        },
        notes: [],
    };
    await page.route('**/workspaces/*/chat-access*', (route) => {
        workspaceId = Number(
            new URL(route.request().url()).pathname.split('/')[2],
        );

        return route.fulfill({ json: access });
    });
    await page.route('**/broadcasting/auth', (route) =>
        route.fulfill({ json: { auth: 'browser-test-key:fixture-signature' } }),
    );
    await page.routeWebSocket('**/app/**', (ws) => {
        if (!allowConnection) {
            ws.close();

            return;
        }

        socket = ws;
        ws.send(
            JSON.stringify({
                event: 'pusher:connection_established',
                data: JSON.stringify({
                    socket_id: '123.456',
                    activity_timeout: 120,
                }),
            }),
        );
        ws.onMessage((raw) => {
            const message = JSON.parse(String(raw));

            if (message.event === 'pusher:subscribe') {
                channel = message.data.channel;
                ws.send(
                    JSON.stringify({
                        event: 'pusher_internal:subscription_succeeded',
                        channel,
                        data: '{}',
                    }),
                );
            }

            if (message.event === 'pusher:ping') {
                ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }));
            }
        });
    });
    await page.route('**/workspaces/*/uazapi/*', async (route) => {
        const path = new URL(route.request().url()).pathname;

        if (path.endsWith('/status')) {
            statusCalls++;
        }

        if (holdConnection && path.endsWith('/connection')) {
            await connectionGate;
        }

        return route.fulfill({
            json: { data: { status: 'connected', phone: '+5527999999999' } },
        });
    });
    await page.route('**/workspaces/*/conversations**', async (route) => {
        const path = new URL(route.request().url()).pathname;

        if (path.endsWith('/messages')) {
            historyCalls++;
            await route.fulfill({
                status: 202,
                json: {
                    data: {
                        operation_id: 'history-operation',
                        status: 'pending',
                    },
                },
            });

            if (!holdHistory) {
                publishHistory();
            }

            return;
        }

        if (path.endsWith('/avatar')) {
            avatarCalls++;

            return route.fulfill({
                json: {
                    data: {
                        url: '/brand/app-icon.png',
                    },
                },
            });
        }

        if (path.endsWith('/contact')) {
            return route.fulfill({ json: { data: thread.contact } });
        }

        listCalls++;

        return route.fulfill({
            json: {
                data: [thread],
                selected_thread: thread,
                next_cursor: null,
                import: { status: 'complete', complete: true, offset: 1 },
            },
        });
    });
    await page.route(
        '**/workspaces/*/contacts/*/sequence-enrollment',
        (route) =>
            route.fulfill({
                json: {
                    data: null,
                    can_enroll: true,
                    sequences: [{ id: 1, name: 'Sequência de teste' }],
                },
            }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect.poll(() => channel).not.toBe('');
    await expect.poll(() => historyCalls).toBe(1);
    function emit(type: string, data: any) {
        if (type === 'connection.changed') {
            access = {
                allowed:
                    data.status === 'connected' &&
                    data.provider_available !== false,
                status:
                    data.provider_available === false
                        ? 'unavailable'
                        : data.status,
            };
        }

        socket!.send(
            JSON.stringify({
                event: 'chat.update',
                channel,
                data: JSON.stringify({
                    type,
                    workspace_id: workspaceId,
                    thread_id: 1,
                    data,
                }),
            }),
        );
    }
    function publishHistory() {
        emit('operation.finished', {
            operation_id: 'history-operation',
            status: 'completed',
            result: {
                data: [
                    {
                        id: 'history-1',
                        from_me: false,
                        type: 'text',
                        text: 'Mensagem do histórico',
                        timestamp: '2026-10-16T12:00:00Z',
                    },
                ],
                read_version: 0,
            },
        });
    }

    return {
        emit,
        release: publishHistory,
        releaseConnection,
        counts: () => ({ historyCalls, statusCalls, listCalls }),
        avatarCalls: () => avatarCalls,
        thread,
        realtimeUnavailable: () =>
            socket!.send(
                JSON.stringify({
                    event: 'pusher:subscription_error',
                    channel,
                    data: JSON.stringify({
                        type: 'AuthError',
                        error: 'Unavailable',
                        status: 503,
                    }),
                }),
            ),
        realtimeRecovered: () =>
            socket!.send(
                JSON.stringify({
                    event: 'pusher_internal:subscription_succeeded',
                    channel,
                    data: '{}',
                }),
            ),
        disconnect: () => {
            allowConnection = false;
            socket!.close();
        },
    };
}

test('visible contact avatars load without selecting a conversation', async ({
    page,
}) => {
    const state = await setup(page);
    await expect.poll(state.avatarCalls).toBe(1);
    await expect(page.locator('.thread-list .thread img')).toBeVisible();
});

test('the local list renders while connection status is pending', async ({
    page,
}) => {
    const state = await setup(page, false, true);
    await expect(page.getByText('Contato em tempo real').first()).toBeVisible();
    expect(state.counts().listCalls).toBe(1);
    state.releaseConnection();
});

test('an idle chat does not poll and list updates do not reload history', async ({
    page,
}) => {
    const state = await setup(page);
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('<script>literal preview</script>', { exact: true }),
    ).toBeVisible();
    await page.clock.install();
    await page.clock.fastForward(65000);
    expect(state.counts()).toEqual({
        historyCalls: 1,
        statusCalls: 0,
        listCalls: 1,
    });
    state.emit('conversation.changed', {
        ...state.thread,
        revision: 2,
        last_message_preview: 'Prévia atualizada',
    });
    await expect(
        page.getByText('Prévia atualizada', { exact: true }),
    ).toBeVisible();
    expect(state.counts().historyCalls).toBe(1);
});

test('a message arriving while history opens survives the snapshot and is shown once', async ({
    page,
}) => {
    const state = await setup(page, true);
    state.emit('message.received', {
        id: 'live-1',
        from_me: false,
        type: 'text',
        text: 'Mensagem durante abertura',
        timestamp: '2026-10-16T12:01:00Z',
    });
    await expect(
        page.getByText('Mensagem durante abertura', { exact: true }),
    ).toBeVisible();
    state.release();
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Mensagem durante abertura', { exact: true }),
    ).toHaveCount(1);
    state.emit('message.received', {
        id: 'live-1',
        track_id: 'same-intent',
        from_me: false,
        type: 'text',
        text: 'Mensagem durante abertura',
        timestamp: '2026-10-16T12:01:00Z',
    });
    await expect(
        page.getByText('Mensagem durante abertura', { exact: true }),
    ).toHaveCount(1);
    expect(state.counts().historyCalls).toBe(1);
});

const realtimeNotice = (page: Page) => page.locator('.realtime-notice');
const connectionModal = (page: Page) =>
    page.getByRole('dialog', { name: 'Problema de conexão' });

test('loss of realtime shows an inline notice without reloading history or taking focus', async ({
    page,
}) => {
    const state = await setup(page);
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    const search = page.getByRole('textbox', {
        name: 'Buscar por nome ou telefone',
    });
    await search.focus();
    state.realtimeUnavailable();
    await expect(realtimeNotice(page)).toContainText(
        'As atualizações em tempo real serão retomadas automaticamente',
    );
    await expect(connectionModal(page)).toHaveCount(0);
    await expect(search).toBeFocused();
    expect(state.counts().historyCalls).toBe(1);
    state.realtimeRecovered();
    await expect(realtimeNotice(page)).toHaveCount(0);
});

test('connection events switch to setup or temporary checking and restore the chat', async ({
    page,
}) => {
    const state = await setup(page);
    state.emit('connection.changed', { status: 'disconnected' });
    await expect(
        page.getByRole('heading', {
            name: 'Conecte seu WhatsApp para começar',
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Conectar WhatsApp' }),
    ).toBeVisible();
    await expect(page.locator('.chat-shell')).toHaveCount(0);
    await expect(connectionModal(page)).toHaveCount(0);
    state.emit('connection.changed', {
        status: 'connected',
        provider_available: false,
    });
    await expect(
        page.getByRole('heading', { name: 'Estamos verificando sua conexão' }),
    ).toBeVisible();
    state.emit('connection.changed', {
        status: 'connected',
        provider_available: true,
    });
    await expect(page.locator('.chat-shell')).toBeVisible();
    await expect(page.locator('.connection-setup')).toHaveCount(0);
    await expect(connectionModal(page)).toHaveCount(0);
});

for (const other of [
    'Filtrar conversas',
    'Finalizar atendimento',
    'Adicionar à sequência',
]) {
    test(`realtime notice leaves ${other} usable`, async ({ page }) => {
        const state = await setup(page);

        if (other !== 'Filtrar conversas') {
            await page
                .getByRole('button', {
                    name: 'Abrir perfil do contato',
                    exact: true,
                })
                .click();
        }

        await page.getByRole('button', { name: other, exact: true }).click();
        const existingDialog = page.getByRole('dialog', {
            name: other,
            exact: true,
        });
        await expect(existingDialog).toBeVisible();
        state.realtimeUnavailable();
        await expect(realtimeNotice(page)).toHaveCount(1);
        await expect(existingDialog).toBeVisible();
        await expect(connectionModal(page)).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(existingDialog).toHaveCount(0);
        await expect(realtimeNotice(page)).toBeVisible();
    });
}

test('inline realtime notice fits mobile iPad and desktop', async ({
    page,
}) => {
    const state = await setup(page);
    state.realtimeUnavailable();

    for (const width of [390, 820, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        await expect(realtimeNotice(page)).toBeVisible();
        const box = (await realtimeNotice(page).boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(width);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await expect(connectionModal(page)).toHaveCount(0);
    }
});

test('initial realtime connection gets ten seconds without a warning', async ({
    page,
}) => {
    const state = await setup(page);
    await page.clock.install();
    await page.clock.fastForward(11000);
    await expect(realtimeNotice(page)).toHaveCount(0);
    state.disconnect();
    await page.reload();
    await expect(
        page.getByRole('textbox', { name: 'Buscar por nome ou telefone' }),
    ).toBeVisible();
    await page.clock.fastForward(9000);
    await expect(realtimeNotice(page)).toHaveCount(0);
    await page.clock.fastForward(1000);
    await expect(realtimeNotice(page)).toContainText(
        'As atualizações em tempo real serão retomadas automaticamente',
    );
    await expect(connectionModal(page)).toHaveCount(0);
});

test('filtered searches page through local conversations without importing chats', async ({
    page,
}) => {
    const state = await setup(page);
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    const cursors: string[] = [];
    let imports = 0;
    await page.route('**/workspaces/*/conversations?**', (route) => {
        const cursor = new URL(route.request().url()).searchParams.get(
            'cursor',
        );
        cursors.push(cursor || 'first');

        return route.fulfill({
            json: {
                data: cursor ? [] : [state.thread],
                selected_thread: state.thread,
                next_cursor: cursor ? null : 'second-page',
            },
        });
    });
    await page.route('**/workspaces/*/uazapi/import', (route) => {
        imports++;

        return route.fulfill({ status: 410, json: {} });
    });
    await page.getByPlaceholder('Buscar conversas').fill('Contato');
    await expect(
        page.getByRole('button', { name: 'Carregar mais conversas' }),
    ).toBeEnabled();
    await page.getByRole('button', { name: 'Carregar mais conversas' }).click();
    await expect.poll(() => cursors).toEqual(['first', 'second-page']);
    expect(imports).toBe(0);
});

test('conversation events respect the selected contact tags', async ({
    page,
}) => {
    const state = await setup(page);
    await page.getByRole('button', { name: 'Filtrar conversas' }).click();
    const tag = page
        .getByRole('group', { name: 'Etiquetas' })
        .getByRole('checkbox')
        .first();
    const tagId = Number(await tag.inputValue());
    await tag.check();
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();

    state.emit('conversation.changed', {
        ...state.thread,
        revision: 2,
        tags: [{ id: tagId, name: 'Etiqueta selecionada' }],
    });
    await expect(
        page.getByText('<script>literal preview</script>', { exact: true }),
    ).toBeVisible();

    state.emit('conversation.changed', {
        ...state.thread,
        revision: 3,
        tags: [],
    });
    await expect(
        page.getByText('<script>literal preview</script>', { exact: true }),
    ).toHaveCount(0);
});

test('cached list and history appear after reload while the network is pending', async ({
    page,
}) => {
    await setup(page);
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    await expect
        .poll(() =>
            page.evaluate(async () => {
                const db = await new Promise<IDBDatabase>((resolve, reject) => {
                    const request = indexedDB.open('chait-chat-cache');
                    request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error);
                });

                return await new Promise<number>((resolve) => {
                    const request = db
                        .transaction('entries')
                        .objectStore('entries')
                        .count();
                    request.onsuccess = () => resolve(request.result);
                });
            }),
        )
        .toBeGreaterThanOrEqual(2);
    let release = () => {};
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/workspaces/*/conversations**', async (route) => {
        await gate;
        await route.fallback();
    });
    await page.reload();
    await expect(page.getByText('Contato em tempo real').first()).toBeVisible();
    await expect(
        page.getByText('Mensagem do histórico', { exact: true }),
    ).toBeVisible();
    release();
});

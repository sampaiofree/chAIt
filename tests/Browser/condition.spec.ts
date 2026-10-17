import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page) {
    await page.route('**/workspaces/*/uazapi/status', (route) =>
        route.fulfill({ json: { data: { status: 'connected' } } }),
    );
    await page.route('**/workspaces/*/conversations**', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
async function fixture(page: Page, complete = false) {
    await login(page);
    await page.goto('/workspaces/1/flows');
    const csrf = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': csrf || '', Accept: 'application/json' };
    const tagResponse = await page.request.post('/workspaces/1/flow-tags', {
        headers,
        data: { name: 'Cliente VIP' },
    });
    const tag = (await tagResponse.json()).data;
    const fieldResponse = await page.request.post('/workspaces/1/flow-fields', {
        headers,
        data: { name: 'Interesse' },
    });
    const field = (await fieldResponse.json()).data;
    const created = await page.request.post('/workspaces/1/flows', {
        headers,
        data: { name: 'Condição de teste ' + Date.now() },
    });
    const flow = (await created.json()).data;
    const endpoint = `/workspaces/1/flows/${flow.id}`;
    const graph = {
        schema_version: 2,
        nodes: [
            {
                id: 'start',
                type: 'start',
                position: { x: 200, y: 40 },
                data: { label: 'Início' },
            },
            {
                id: 'condition',
                type: 'condition',
                position: { x: 200, y: 220 },
                data: {
                    label: 'Verificar interesse',
                    condition_operator: 'all',
                    checks: complete
                        ? [
                              { kind: 'tag', tag_id: tag.id, operator: 'has' },
                              {
                                  kind: 'field',
                                  field_id: field.id,
                                  operator: 'contains',
                                  value: 'inglês',
                              },
                          ]
                        : [],
                },
            },
            {
                id: 'yes',
                type: 'content',
                position: { x: 0, y: 470 },
                data: {
                    label: 'Resposta Sim',
                    items: [{ id: 'yes-text', kind: 'text', text: 'Sim' }],
                },
            },
            {
                id: 'no',
                type: 'content',
                position: { x: 400, y: 470 },
                data: {
                    label: 'Resposta Não',
                    items: [{ id: 'no-text', kind: 'text', text: 'Não' }],
                },
            },
        ],
        edges: [
            {
                id: 'start-condition',
                source: 'start',
                target: 'condition',
                sourceHandle: 'next',
                targetHandle: 'input',
            },
            {
                id: 'yes-edge',
                source: 'condition',
                target: 'yes',
                sourceHandle: 'yes',
                targetHandle: 'input',
            },
            {
                id: 'no-edge',
                source: 'condition',
                target: 'no',
                sourceHandle: 'no',
                targetHandle: 'input',
            },
        ],
        viewport: { x: 0, y: 0, zoom: 1 },
    };
    const saved = await page.request.put(endpoint, {
        headers,
        data: { name: flow.name, revision: 1, graph },
    });
    expect(saved.ok()).toBe(true);
    await page.goto(endpoint);
    await page.locator('.vue-flow__node[data-id="condition"]').click();
    await expect(
        page.getByRole('heading', { name: 'Verificações do contato' }),
    ).toBeVisible();

    return { endpoint, field, tag };
}

test('condition cards configure mixed checks and preserve both connections after edits and reload', async ({
    page,
}) => {
    const { endpoint, tag, field } = await fixture(page);
    await expect(
        page.getByText('Adicione uma verificação para configurar a condição.', {
            exact: false,
        }),
    ).toBeVisible();
    await page.getByLabel('Combinar verificações').focus();
    await page.keyboard.press('Tab');
    await expect(
        page.getByRole('button', {
            name: 'Adicionar verificação',
            exact: true,
        }),
    ).toBeFocused();
    await page.keyboard.press('Space');
    await page.locator('#condition-tag-0').selectOption(String(tag.id));
    await page.locator('#condition-operator-0').selectOption('not_has');
    await page
        .getByRole('button', { name: 'Adicionar verificação', exact: true })
        .click();
    await page.locator('#condition-kind-1').selectOption('field');
    await expect(
        page.locator(
            '#condition-field-1 optgroup[label="Campos padrão"] option',
        ),
    ).toHaveCount(3);
    await page.locator('#condition-field-1').selectOption(`custom:${field.id}`);
    await page.locator('#condition-operator-1').selectOption('contains');
    await page.locator('#condition-value-1').fill('inglês');
    await page.getByLabel('Combinar verificações').selectOption('any');
    await expect(page.locator('.vue-flow__edge')).toHaveCount(3);
    await expect(
        page.locator('.vue-flow__node[data-id="condition"]'),
    ).toContainText('Pelo menos uma (OU)');
    await expect
        .poll(
            async () =>
                (
                    await (
                        await page.request.get(endpoint + '/assistant')
                    ).json()
                ).data.session?.graph.nodes.find(
                    (node: { id: string }) => node.id === 'condition',
                )?.data.checks?.[1]?.value,
        )
        .toBe('inglês');
    await page.reload();
    await page.locator('.vue-flow__node[data-id="condition"]').click();
    await expect(page.locator('#condition-value-1')).toHaveValue('inglês');
    await expect(page.locator('.vue-flow__edge')).toHaveCount(3);
    await page
        .getByRole('button', { name: 'Remover verificação 1', exact: true })
        .click();
    await expect(page.locator('.condition-check')).toHaveCount(1);
    await expect(page.locator('#condition-value-0')).toHaveValue('inglês');
    await expect(page.locator('.vue-flow__edge')).toHaveCount(3);
    await page.locator('#condition-kind-0').selectOption('tag');
    await expect(page.locator('#condition-value-0')).toHaveCount(0);
    await expect(page.locator('#condition-tag-0')).toHaveValue('');
});

test('publication shows the affected condition and inline diagnostics for an empty block', async ({
    page,
}) => {
    await fixture(page);
    await page.getByRole('button', { name: 'Publicar', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText(
        'Adicione de 1 a 50 verificações',
    );
    await page.getByRole('button', { name: 'Entendi', exact: true }).click();
    await expect(page.locator('.settings-panel [role="alert"]')).toContainText(
        'Adicione de 1 a 50 verificações',
    );
});

test('condition cards remain usable with keyboard in both themes and responsive widths', async ({
    page,
}, testInfo) => {
    await fixture(page, true);

    for (const width of [1440, 1280, 1024, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });

        for (const dark of [false, true]) {
            await page.evaluate(
                (value) =>
                    document.documentElement.classList.toggle('dark', value),
                dark,
            );
            await expect(page.locator('#condition-combination')).toBeVisible();
            await page.locator('#condition-combination').focus();
            await expect(page.locator('#condition-combination')).toBeFocused();
            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            ).toBe(true);
            expect(
                await page
                    .locator('.settings-panel')
                    .evaluate(
                        (element) => element.scrollWidth <= element.clientWidth,
                    ),
            ).toBe(true);
            await page.screenshot({
                path: testInfo.outputPath(
                    `condition-${width}-${dark ? 'dark' : 'light'}.png`,
                ),
            });
        }
    }
});

test('resource deletion confirms data loss and explains published dependencies', async ({
    page,
}) => {
    const { endpoint, tag, field } = await fixture(page, true);
    await page.getByRole('button', { name: 'Publicar', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('Fluxo publicado');
    await page.getByRole('button', { name: 'Entendi', exact: true }).click();
    await page.goto('/settings/contact-data');
    await page
        .getByRole('button', {
            name: `Excluir campo ${field.name}`,
            exact: true,
        })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'Todos os valores preenchidos',
    );
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(
        page.getByRole('button', {
            name: `Excluir campo ${field.name}`,
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole('button', {
            name: `Excluir campo ${field.name}`,
            exact: true,
        })
        .click();
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(
        'Não é possível excluir',
    );
    await expect(page.getByRole('alert')).toContainText('Fluxo');
    await page.getByRole('tab', { name: 'Tags', exact: true }).click();
    await page
        .getByRole('button', {
            name: `Excluir etiqueta ${tag.name}`,
            exact: true,
        })
        .click();
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(
        'Não é possível excluir',
    );
    expect((await page.request.get(endpoint + '/assistant')).ok()).toBe(true);
});

test('unused fields and tags can be deleted with explicit confirmation', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/contact-data');
    const name = `Campo descartável ${Date.now()}`;
    await page
        .getByRole('button', { name: 'Cadastrar campo', exact: true })
        .click();
    await page.getByRole('dialog').getByLabel('Nome do campo').fill(name);
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Cadastrar campo', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await page
        .getByRole('button', { name: `Excluir campo ${name}`, exact: true })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'não pode ser desfeita',
    );
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(
        page.getByRole('button', {
            name: `Excluir campo ${name}`,
            exact: true,
        }),
    ).toHaveCount(0);
    await expect(page.getByRole('status')).toContainText(
        'Campo e valores excluídos.',
    );
    await page.getByRole('tab', { name: 'Tags', exact: true }).click();
    const tagName = `Etiqueta descartável ${Date.now()}`;
    await page
        .getByRole('button', { name: 'Cadastrar tag', exact: true })
        .click();
    await page.getByRole('dialog').getByLabel('Nome da tag').fill(tagName);
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Cadastrar tag', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await page
        .getByRole('button', {
            name: `Excluir etiqueta ${tagName}`,
            exact: true,
        })
        .click();
    await expect(page.getByRole('dialog')).toContainText(
        'removida dos contatos',
    );
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(
        page.getByRole('button', {
            name: `Excluir etiqueta ${tagName}`,
            exact: true,
        }),
    ).toHaveCount(0);
});

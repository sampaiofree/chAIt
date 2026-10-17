import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ContactFields } from '@/lib/contactTemplate';
import type { ContentButtonCatalog } from '@/lib/flowContentButton';
import type { FlowMenuData } from '@/lib/flowMenu';

export type FlowGraph = {
    schema_version: 2;
    nodes: {
        id: string;
        type: string;
        position: { x: number; y: number };
        data: Record<string, unknown>;
    }[];
    edges: {
        id: string;
        source: string;
        target: string;
        sourceHandle: string | null;
        targetHandle: string | null;
    }[];
    viewport: { x: number; y: number; zoom: number };
};
export type FlowCatalog = {
    contact_fields?: ContactFields & { provider_compatibility?: string };
    defaults?: { ai: Record<string, unknown> };
    content_button?: ContentButtonCatalog;
    menu?: { defaults: FlowMenuData };
    graph_rules?: string[];
    nodes: {
        type: string;
        label: string;
        usage?: string;
        outputs: { id: string; label: string; meaning?: string }[];
        dynamic_outputs?: {
            field: string;
            prefix: string;
            meaning?: string;
        } | null;
        input: string | null;
    }[];
};
export type AssistantMessage = {
    author?: { name: string } | null;
    id: number;
    role: string;
    content: string;
    kind: string;
    status: string | null;
    approved_at: string | null;
    user_id: number | null;
    created_at: string;
};
export type AssistantState = {
    flow: { name: string; graph: FlowGraph; revision: number };
    session: {
        generation: string;
        name: string;
        graph: FlowGraph;
        revision: number;
        base_revision: number;
    } | null;
    conversation: { model: string; state: string } | null;
    messages: AssistantMessage[];
    run: {
        id: number;
        status: string;
        purpose: string;
        error: string | null;
    } | null;
    can_use_ai: boolean;
    configured: boolean;
    catalog: FlowCatalog;
};

// Explicit reapplication changes only fields edited locally, preserving unrelated remote changes.
export function reapplyFlowChanges(
    base: unknown,
    local: unknown,
    remote: unknown,
): unknown {
    const equal = (a: unknown, b: unknown) =>
        JSON.stringify(a) === JSON.stringify(b);

    if (equal(base, local)) {
        return remote;
    }

    if (equal(base, remote) || equal(local, remote)) {
        return local;
    }

    if (
        Array.isArray(base) &&
        Array.isArray(local) &&
        Array.isArray(remote) &&
        [...base, ...local, ...remote].every(
            (v) => v && typeof v === 'object' && 'id' in v,
        )
    ) {
        const find = (list: Record<string, unknown>[], id: unknown) =>
            list.find((v) => v.id === id);
        const ids = new Set([...remote, ...local, ...base].map((v) => v.id));

        return [...ids]
            .map((id) =>
                reapplyFlowChanges(
                    find(base, id),
                    find(local, id),
                    find(remote, id),
                ),
            )
            .filter((v) => v !== undefined);
    }

    if (
        base &&
        local &&
        remote &&
        typeof base === 'object' &&
        typeof local === 'object' &&
        typeof remote === 'object' &&
        !Array.isArray(base) &&
        !Array.isArray(local) &&
        !Array.isArray(remote)
    ) {
        const b = base as Record<string, unknown>,
            l = local as Record<string, unknown>,
            r = remote as Record<string, unknown>;
        const out: Record<string, unknown> = {};

        for (const key of new Set([
            ...Object.keys(b),
            ...Object.keys(l),
            ...Object.keys(r),
        ])) {
            const value = reapplyFlowChanges(b[key], l[key], r[key]);

            if (value !== undefined) {
                out[key] = value;
            }
        }

        return out;
    }

    return local;
}

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value));
}

export function useFlowAssistant(options: {
    workspaceId: number;
    flowId: number;
    snapshot: () => { name: string; graph: FlowGraph };
    apply: (state: AssistantState) => void;
    initialRevision: number;
    userId: number;
}) {
    const state = ref<AssistantState | null>(null);
    const error = ref('');
    const conflict = ref(false);
    const loaded = ref(false);
    const busy = ref(false);
    const replacing = ref(false);
    const models = ref<string[]>([]);
    const model = ref('auto');
    const recovered = ref(false);
    const backupKey = `flow-preview:${options.userId}:${options.workspaceId}:${options.flowId}`;
    // Capture recovery before Vue Flow mount events can normalize the local graph.
    let startupBackup: {
        expected: {
            base_revision: number;
            generation: string | null;
            draft_revision: number | null;
        };
        snapshot: ReturnType<typeof options.snapshot>;
        baseline: ReturnType<typeof options.snapshot>;
    } | null = null;

    try {
        startupBackup = JSON.parse(sessionStorage.getItem(backupKey) || 'null');
    } catch {
        /* Recovery is optional when storage is unavailable. */
    }

    const clearBackup = () => {
        try {
            sessionStorage.removeItem(backupKey);
        } catch {
            /* Storage may be disabled. */
        }
    };
    const endpoint = `/workspaces/${options.workspaceId}/flows/${options.flowId}`;
    let baseline = clone(options.snapshot());
    let acknowledged = JSON.stringify(baseline);
    let debounce: ReturnType<typeof setTimeout> | undefined;
    let poll: ReturnType<typeof setTimeout> | undefined;
    let flushing: Promise<boolean> | null = null;
    let disposed = false;
    let applying = false;
    let refreshing = false;
    let syncEpoch = 0;
    const running = computed(() =>
        ['queued', 'running'].includes(state.value?.run?.status ?? ''),
    );
    const dirty = computed(
        () =>
            !!state.value?.session &&
            (state.value.session.name !== state.value.flow.name ||
                JSON.stringify(state.value.session.graph) !==
                    JSON.stringify(state.value.flow.graph)),
    );
    const localPending = () =>
        JSON.stringify(options.snapshot()) !== acknowledged;
    const revisions = () => ({
        base_revision: state.value?.flow.revision ?? options.initialRevision,
        generation: state.value?.session?.generation ?? null,
        draft_revision: state.value?.session?.revision ?? null,
    });
    async function request(path: string, method = 'GET', body?: unknown) {
        const response = await fetch(endpoint + path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content || '',
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            if (response.status === 409) {
                conflict.value = true;
            }

            throw new Error(
                result.message || 'Não foi possível sincronizar o editor.',
            );
        }

        return result.data;
    }
    async function apply(incoming: AssistantState) {
        applying = true;
        state.value = incoming;

        if (!busy.value) {
            model.value = incoming.conversation?.model ?? 'auto';
        }

        options.apply(incoming);
        await nextTick();
        baseline = clone(options.snapshot());
        acknowledged = JSON.stringify(baseline);
        applying = false;
        conflict.value = false;
        clearBackup();
    }
    function identity(value: AssistantState | null) {
        return `${value?.flow.revision}:${value?.session?.generation}:${value?.session?.revision}`;
    }
    async function refresh() {
        if (
            disposed ||
            refreshing ||
            flushing ||
            busy.value ||
            replacing.value
        ) {
            return;
        }

        refreshing = true;
        const epoch = syncEpoch;

        try {
            const incoming: AssistantState = await request('/assistant');
            const oldestIncoming = incoming.messages[0]?.id;

            if (oldestIncoming && state.value) {
                incoming.messages = [
                    ...state.value.messages.filter(
                        (message) => message.id < oldestIncoming,
                    ),
                    ...incoming.messages,
                ];
            }

            if (disposed || replacing.value || epoch !== syncEpoch) {
                return;
            }

            const backup = !loaded.value ? startupBackup : null;
            const localBeforeRefresh = clone(options.snapshot());
            const baselineBeforeRefresh = clone(baseline);
            const pending = localPending();
            const remoteChanged = identity(incoming) !== identity(state.value);

            if (pending) {
                if (remoteChanged && (loaded.value || incoming.session)) {
                    conflict.value = true;
                    error.value =
                        'A prévia compartilhada mudou. Suas alterações locais estão preservadas.';
                }

                state.value = incoming;
            } else if (remoteChanged || !loaded.value) {
                await apply(incoming);
            } else {
                state.value = incoming;
            }

            loaded.value = true;

            if (
                backup &&
                backup.expected.base_revision === incoming.flow.revision &&
                backup.expected.generation ===
                    (incoming.session?.generation ?? null)
            ) {
                // Unsynchronized keystrokes are tab-local recovery data, never another saved version.
                const remote = {
                    name: incoming.session?.name ?? incoming.flow.name,
                    graph: incoming.session?.graph ?? incoming.flow.graph,
                };
                const restored = reapplyFlowChanges(
                    baselineBeforeRefresh,
                    localBeforeRefresh,
                    backup.snapshot,
                ) as ReturnType<typeof options.snapshot>;
                options.apply({
                    ...incoming,
                    session: {
                        generation: incoming.session?.generation ?? '',
                        revision: incoming.session?.revision ?? 1,
                        base_revision: incoming.flow.revision,
                        ...restored,
                    },
                });
                await nextTick();
                recovered.value = true;

                if (
                    backup.expected.draft_revision !==
                    (incoming.session?.revision ?? null)
                ) {
                    conflict.value = true;
                    error.value =
                        'Há alterações locais recuperadas e uma prévia mais recente da equipe. Carregue ou reaplique suas alterações.';
                    baseline = clone(backup.baseline ?? remote);
                } else {
                    conflict.value = false;
                    changed();
                }
            } else if (backup && !pending) {
                clearBackup();
            }

            startupBackup = null;

            if (localPending() && !conflict.value && !running.value) {
                changed();
            }
        } catch (caught) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Falha ao consultar o assistente.';
        } finally {
            refreshing = false;
        }
    }
    function schedulePoll() {
        poll = setTimeout(
            async () => {
                if (!document.hidden) {
                    await refresh();
                }

                if (!disposed) {
                    schedulePoll();
                }
            },
            running.value ? 1000 : 5000,
        );
    }
    function changed() {
        if (applying || disposed || replacing.value) {
            return;
        }

        clearTimeout(debounce);

        if (localPending()) {
            try {
                sessionStorage.setItem(
                    backupKey,
                    JSON.stringify({
                        expected: revisions(),
                        snapshot: options.snapshot(),
                        baseline,
                    }),
                );
            } catch {
                /* Server synchronization remains available. */
            }
        } else {
            clearBackup();
        }

        if (!running.value && !conflict.value) {
            debounce = setTimeout(() => {
                void flush();
            }, 500);
        }
    }
    async function flush(): Promise<boolean> {
        clearTimeout(debounce);

        if (replacing.value) {
            return false;
        }

        if (flushing) {
            const ok = await flushing;

            return ok && localPending() ? flush() : ok;
        }

        if (!loaded.value) {
            await refresh();
        }

        if (conflict.value || running.value || !loaded.value) {
            return false;
        }

        if (!localPending()) {
            return true;
        }

        const snapshot = clone(options.snapshot());
        const signature = JSON.stringify(snapshot);
        const expected = revisions();
        flushing = (async () => {
            try {
                const incoming: AssistantState = await request(
                    '/draft-session',
                    'PUT',
                    { ...expected, ...snapshot },
                );
                // Keystrokes during the request stay local and are flushed in the next revision.
                state.value = incoming;
                baseline = snapshot;
                acknowledged = signature;
                error.value = '';

                if (JSON.stringify(options.snapshot()) === signature) {
                    // Keep the current form and its defaults; only the shared revision changed.
                    clearBackup();
                } else {
                    changed();
                }

                return true;
            } catch (caught) {
                error.value =
                    caught instanceof Error
                        ? caught.message
                        : 'Não foi possível sincronizar a prévia.';

                return false;
            } finally {
                flushing = null;
            }
        })();
        const ok = await flushing;

        return ok && localPending() ? flush() : ok;
    }
    async function action(path: string, body: Record<string, unknown> = {}) {
        if (busy.value || replacing.value) {
            return false;
        }

        if (!(await flush())) {
            return false;
        }

        busy.value = true;
        error.value = '';

        try {
            await apply(
                await request(path, 'POST', {
                    ...revisions(),
                    ...body,
                    model: model.value,
                }),
            );

            return true;
        } catch (caught) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Não foi possível iniciar a IA.';

            return false;
        } finally {
            busy.value = false;
        }
    }
    async function loadModels() {
        if (!state.value?.can_use_ai || !state.value.configured) {
            return;
        }

        try {
            models.value = await request('/assistant/models');
        } catch (caught) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Modelos indisponíveis.';
        }
    }
    async function stop() {
        if (!state.value?.run || busy.value) {
            return;
        }

        busy.value = true;

        try {
            const incoming = await request(
                `/assistant/runs/${state.value.run.id}/stop`,
                'POST',
            );

            if (localPending()) {
                state.value = incoming;
            } else {
                await apply(incoming);
            }
        } catch (caught) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Falha ao parar a IA.';
        } finally {
            busy.value = false;
        }
    }
    async function discard() {
        clearTimeout(debounce);

        if (flushing) {
            await flushing;
        }

        busy.value = true;

        try {
            // Fetch current revision before an explicit team-wide discard; local contents are not uploaded.
            state.value = await request('/assistant');
            await apply(await request('/draft-session', 'DELETE', revisions()));
            error.value = '';

            return true;
        } catch (caught) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Falha ao descartar a prévia.';

            return false;
        } finally {
            busy.value = false;
        }
    }
    async function reload() {
        clearTimeout(debounce);

        if (flushing) {
            await flushing;
        }

        await apply(await request('/assistant'));
        error.value = '';
    }
    async function reapply() {
        const local = clone(options.snapshot());
        const incoming: AssistantState = await request('/assistant');

        if (['queued', 'running'].includes(incoming.run?.status ?? '')) {
            error.value =
                'Pare ou aguarde a IA antes de reaplicar suas alterações.';

            return;
        }

        const remote = {
            name: incoming.session?.name ?? incoming.flow.name,
            graph: incoming.session?.graph ?? incoming.flow.graph,
        };
        const merged = reapplyFlowChanges(
            baseline,
            local,
            remote,
        ) as typeof remote;
        state.value = incoming;
        acknowledged = JSON.stringify(remote);
        baseline = clone(remote);
        conflict.value = false;
        options.apply({
            ...incoming,
            session: {
                generation: incoming.session?.generation ?? '',
                revision: incoming.session?.revision ?? 1,
                base_revision: incoming.flow.revision,
                ...merged,
            },
        });
        await nextTick();

        return flush();
    }
    async function saved() {
        await reload();
    }
    async function beginReplacement(): Promise<boolean> {
        if (
            busy.value ||
            replacing.value ||
            running.value ||
            conflict.value ||
            !loaded.value
        ) {
            return false;
        }

        replacing.value = true;
        syncEpoch++;
        clearTimeout(debounce);

        if (flushing) {
            await flushing;
        }

        return true;
    }
    async function acceptReplacement(incoming: AssistantState) {
        await apply(incoming);
        error.value = '';
        startupBackup = null;
        recovered.value = false;
    }
    function endReplacement() {
        replacing.value = false;

        if (localPending()) {
            changed();
        }
    }
    async function olderMessages() {
        const first = state.value?.messages[0];

        if (!first) {
            return;
        }

        const incoming: AssistantState = await request(
            `/assistant?before=${first.id}`,
        );

        if (state.value) {
            state.value.messages = [
                ...incoming.messages,
                ...state.value.messages,
            ];
        }
    }
    onMounted(async () => {
        await refresh();
        schedulePoll();
    });
    onBeforeUnmount(() => {
        disposed = true;
        clearTimeout(debounce);
        clearTimeout(poll);
    });

    return {
        state,
        recovered,
        error,
        conflict,
        loaded,
        busy,
        replacing,
        running,
        dirty,
        models,
        model,
        revisions,
        changed,
        flush,
        refresh,
        action,
        loadModels,
        stop,
        discard,
        reload,
        reapply,
        saved,
        beginReplacement,
        acceptReplacement,
        endReplacement,
        olderMessages,
    };
}

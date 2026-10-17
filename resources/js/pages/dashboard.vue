<script setup lang="ts">
import {
    mergeChatMessages,
    nextMessageStatus,
    sameChatMessage,
} from '@/lib/chatState';
import { openChatRealtime, type ChatUpdate } from '@/lib/chatRealtime';
import { formatMessageDate } from '@/lib/messageDate';
import { Textarea } from '@/components/ui/textarea';
import { Head, usePage } from '@inertiajs/vue3';
import { useVoiceRecorder } from '@/composables/useVoiceRecorder';
import ContactSequence from '@/components/ContactSequence.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppButton from '@/components/AppButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    ArrowLeft,
    ArrowDown,
    Search,
    ListFilter,
    Plus,
    Send,
    X,
    PanelRightClose,
    ContactRound,
    CircleCheck,
    CirclePause,
    CircleOff,
} from '@lucide/vue';
import { Mic } from '@lucide/vue';
import { useAppDialog } from '@/composables/useAppDialog';
import {
    computed,
    onMounted,
    nextTick,
    onUnmounted,
    reactive,
    ref,
    watch,
    type Directive,
} from 'vue';

type Contact = {
    id: number;
    display_name: string | null;
    phone: string | null;
    email: string | null;
    cpf: string | null;
    avatar_url?: string | null;
    tags?: { id: number; name: string }[];
    custom_fields?: { id: number; name: string | null; value: string | null }[];
};
type Note = {
    id: number;
    body: string;
    author: string | null;
    created_at: string;
};
type Thread = {
    id: number;
    status: string;
    last_message_at: string | null;
    last_message_preview?: string | null;
    last_message_type?: string | null;
    revision?: number;
    tags?: { id: number; name: string }[];
    unread_count: number;
    unread_version: number;
    assigned_to: { id: number; name: string } | null;
    contact: Contact;
    notes?: Note[];
    automation?: {
        state: 'unavailable' | 'allowed' | 'paused';
        pause_reasons: ('manual' | 'human')[];
        execution_status:
            | 'running'
            | 'waiting_reply'
            | 'waiting_menu'
            | 'waiting_ai'
            | null;
        can_resume: boolean;
        can_pause: boolean;
        expires_at: string | null;
    };
};
type Message = {
    id: string;
    from_me: boolean;
    whatsapp_id?: string | null;
    status?: string;
    type: string;
    text: string;
    caption?: string;
    media_url?: string | null;
    media_endpoint?: string | null;
    track_id?: string | null;
    timestamp?: string | null;
    is_image?: boolean;
    sending_status?: 'sending' | 'sent' | 'confirmation_pending' | 'failed';
    object_url?: string;
    thread_id?: number;
    is_audio?: boolean;
    is_voice?: boolean;
    mime_type?: string | null;
    duration_seconds?: number | null;
    media_unavailable?: boolean;
    media_loading?: boolean;
};
const page = usePage<any>();
const { dialog, alert, confirm, finish } = useAppDialog();
const workspace = computed(() => page.props.workspace);
const displayNow = ref(new Date());
let dateLabelTimer: number | undefined;
function refreshDateLabels() {
    displayNow.value = new Date();
}

const me = computed(() => page.props.currentUser);
const isAdmin = computed(() => page.props.isAdmin);

const realtimeEnabled = !!page.props.chatRealtime?.enabled;
let realtime: ReturnType<typeof openChatRealtime> | undefined;
const realtimeConnected = ref(false);
const liveMessages = new Map<number, Message[]>();
const liveStatuses = new Map<string, string>();
const nextCursor = ref<string | null>(null);
const endCursor = ref<string | null>(null);
const importState = ref({ status: 'not_started', offset: 0, complete: false });
const loadingThreads = ref(false);
const automaticPages = ref(0);
let searchGeneration = 0;
let importGeneration = 0;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let listAbort: AbortController | undefined;
const threads = ref<Thread[]>([]);
const selected = ref<Thread | null>(null);
const messages = ref<Message[]>([]);
const connection = ref<{
    status: string;
    phone: string | null;
    provider_available?: boolean;
    last_checked_at?: string | null;
} | null>(null);
const connectionLoaded = ref(false);
const connectionWarning = computed(
    () =>
        connectionLoaded.value &&
        (connection.value?.status !== 'connected' ||
            connection.value.provider_available === false),
);
const workspaceTags = computed(
    () =>
        (page.props.tags ?? []) as {
            id: number;
            name: string;
            color: string;
        }[],
);
const historyLimited = ref(false);
const messagesElement = ref<HTMLElement | null>(null);
const isAtLatest = ref(true);
const hasNewMessages = ref(false);
const historyReadVersion = ref<number | null>(null);
const search = ref('');
const assignment = ref('all');
const statusFilter = ref('all');
const appliedTagIds = ref<number[]>([]);
const filterOpen = ref(false);
const draftAssignment = ref('all');
const draftOpenOnly = ref(false);
const draftTagIds = ref<number[]>([]);
const activeFilterCount = computed(
    () =>
        Number(assignment.value !== 'all') +
        Number(statusFilter.value === 'open') +
        Number(appliedTagIds.value.length > 0),
);
function openFilters() {
    draftAssignment.value = assignment.value;
    draftOpenOnly.value = statusFilter.value === 'open';
    draftTagIds.value = [...appliedTagIds.value];
    filterOpen.value = true;
}
function applyFilters() {
    assignment.value = draftAssignment.value;
    statusFilter.value = draftOpenOnly.value ? 'open' : 'all';
    appliedTagIds.value = [...draftTagIds.value];
    filterOpen.value = false;
}
function clearFilters() {
    assignment.value = 'all';
    statusFilter.value = 'all';
    appliedTagIds.value = [];
    filterOpen.value = false;
}
const draft = ref('');
const noteDraft = ref('');
const assignUserId = ref('');
const busy = ref(false);
const automationBusy = ref(false);
const pauseFormOpen = ref(false);
const automationError = ref('');
const pauseForm = reactive({
    choice: '30m' as '30m' | '1h' | 'custom' | 'indefinite',
    date: '',
    time: '',
});
const automationTimezone = computed(() => workspace.value.timezone || 'UTC');
const automationPauseLabel = computed(() => {
    const automation = selected.value?.automation;
    if (!automation?.pause_reasons.includes('manual')) return '';
    if (!automation.expires_at) return 'Até retomar manualmente';
    const parts = new Intl.DateTimeFormat('pt-BR', {
        timeZone: automationTimezone.value,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(automation.expires_at));
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((value) => value.type === type)?.value;
    return `Até ${part('day')}/${part('month')}/${part('year')} às ${part('hour')}:${part('minute')}`;
});
const pauseChoices = [
    { value: '30m', label: '30 minutos', description: 'Uma pausa rápida.' },
    { value: '1h', label: '1 hora', description: 'Uma pausa por uma hora.' },
    {
        value: 'custom',
        label: 'Escolher data e horário',
        description: 'Defina quando a pausa termina.',
    },
    {
        value: 'indefinite',
        label: 'Sem prazo',
        description: 'Até retomar manualmente.',
    },
] as const;
const automationRegion = ref<HTMLElement | null>(null);
const automationStatus = computed(() => {
    switch (selected.value?.automation?.state) {
        case 'paused':
            return 'Pausada';
        case 'unavailable':
            return 'Indisponível';
        default:
            return 'Liberada';
    }
});
function pausePayload() {
    if (pauseForm.choice === 'indefinite') return { mode: 'indefinite' };
    if (pauseForm.choice === 'custom') {
        return {
            mode: 'timed',
            expires_at: `${pauseForm.date}T${pauseForm.time}`,
        };
    }
    const minutes = pauseForm.choice === '30m' ? 30 : 60;
    const deadline = new Date(
        Math.ceil((Date.now() + minutes * 60_000) / 60_000) * 60_000,
    );
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: automationTimezone.value,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(deadline);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((value) => value.type === type)?.value;
    return {
        mode: 'timed',
        expires_at: `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`,
    };
}
async function focusAutomation(selector: string) {
    await nextTick();
    const region = automationRegion.value;
    (
        region?.querySelector<HTMLElement>(selector) ??
        region?.querySelector<HTMLElement>('[data-automation-status]')
    )?.focus();
}
function cancelPauseForm() {
    resetPauseForm();
    void focusAutomation('[data-pause-trigger]');
}
const mobilePane = ref<'list' | 'chat' | 'profile'>('list');

const profileCollapsed = ref(true);
const contactAvatars = reactive<Record<number, string | null>>({});
const avatarLoaded = new Set<number>();
const avatarRetries = new Set<number>();
const visibleAvatars = new Set<number>();
const pendingMedia = ref<Message[]>([]);
const expandedImage = ref('');
const renewedMedia = new Set<string>();
const loadingMedia = new Set<string>();
const mediaCache = new Map<
    string,
    { url: string | null; unavailable: boolean }
>();
const contactForm = reactive({
    display_name: '',
    email: '',
    cpf: '',
});
let statusTimer = 0;
let listTimer = 0;
let renderedThreadId: number | null = null;
let historyGeneration = 0;
let lastReadAttempt: string | null = null;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '';
const root = () => `/workspaces/${workspace.value.id}`;

async function api(path: string, init: RequestInit = {}) {
    const headers = new Headers(init.headers);
    headers.set('X-CSRF-TOKEN', csrf());
    headers.set('Accept', 'application/json');
    if (!(init.body instanceof FormData))
        headers.set('Content-Type', 'application/json');
    if (
        realtimeEnabled &&
        /\/(messages|send|avatar|media|read)(\/|\?|$)/.test(path) &&
        realtime &&
        !realtimeConnected.value &&
        !(await realtime.ready)
    )
        throw new Error(
            'O tempo real está indisponível. Aguarde a conexão antes de iniciar uma operação.',
        );
    const response = await fetch(path, {
        ...init,
        headers,
        credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error: any = new Error(
            data.message || 'Não foi possível concluir a operação.',
        );
        error.status = response.status;
        error.data = data.data;
        error.errors = data.errors;
        throw error;
    }
    if (
        realtimeEnabled &&
        response.status === 202 &&
        data.data?.operation_id &&
        realtime
    ) {
        const outcome = await realtime.operation(data.data.operation_id);
        if (['failed', 'cancelled', 'expired'].includes(outcome.status)) {
            const error: any = new Error(
                outcome.result?.message || 'A operação não foi concluída.',
            );
            error.status = 422;
            error.data = { status: 'failed' };
            throw error;
        }
        return outcome.result;
    }
    return data;
}
function params() {
    const q = new URLSearchParams({
        assignment: assignment.value,
        status: statusFilter.value,
        search: search.value,
    });
    for (const id of appliedTagIds.value) q.append('tag_ids[]', `${id}`);
    if (selected.value) q.set('thread_id', `${selected.value.id}`);
    return q;
}
async function loadThreads(append = false) {
    if (realtimeEnabled) {
        const generation = searchGeneration;
        listAbort?.abort();
        listAbort = new AbortController();
        loadingThreads.value = true;
        try {
            const query = params();
            if (append && (nextCursor.value || endCursor.value))
                query.set('cursor', (nextCursor.value || endCursor.value)!);
            const result = await api(`${root()}/conversations?${query}`, {
                signal: listAbort.signal,
            });
            if (generation !== searchGeneration) return;
            const combined = append
                ? [...threads.value, ...result.data]
                : result.data;
            threads.value = Array.from(
                new Map<number, Thread>(
                    combined.map((item: Thread) => [item.id, item]),
                ).values(),
            );
            nextCursor.value = result.next_cursor;
            endCursor.value = result.end_cursor || endCursor.value;
            importState.value = result.import;
            if (selected.value)
                selected.value =
                    threads.value.find(
                        (item) => item.id === selected.value?.id,
                    ) ||
                    (result.selected_thread?.id === selected.value.id
                        ? result.selected_thread
                        : selected.value);
            if (!selected.value && result.selected_thread)
                choose(result.selected_thread);
        } catch {
            /* Keep existing local data; no provider polling fallback. */
        } finally {
            loadingThreads.value = false;
        }
        return;
    }
    if (connection.value?.status !== 'connected') return;
    try {
        const oldId = selected.value?.id;
        const workspaceId = workspace.value.id;
        const result = await api(`${root()}/conversations?${params()}`);
        if (workspace.value.id !== workspaceId) return;
        threads.value = result.data;
        // A poll that started on another conversation must not replace the one
        // selected while it was in flight (and discard its voice recording).
        const changedSelection = selected.value?.id !== oldId;
        selected.value =
            (changedSelection
                ? threads.value.find(
                      (thread) => thread.id === selected.value?.id,
                  )
                : result.selected_thread) ||
            threads.value.find((t: Thread) => t.id === oldId) ||
            threads.value[0] ||
            null;
        if (selected.value) await loadHistory();
        else messages.value = [];
    } catch {
        /* keep the last loaded conversations while UAZAPI is unavailable */
    }
}
async function loadHistory() {
    if (!selected.value) return;
    const generation = ++historyGeneration;
    const target = selected.value;
    const workspaceId = workspace.value.id;
    const shouldFollow = renderedThreadId !== target.id || isAtLatest.value;
    try {
        const history = await api(
            `${root()}/conversations/${target.id}/messages`,
        );
        if (
            workspace.value.id !== workspaceId ||
            generation !== historyGeneration
        )
            return;
        const pendingForThread = pendingMedia.value.filter(
            (message: any) =>
                message.thread_id === target.id && message.track_id,
        );
        const correlated = realtimeEnabled
            ? []
            : await Promise.all(
                  pendingForThread.map(async (message: any) => {
                      try {
                          return (
                              await api(
                                  `${root()}/conversations/${target.id}/messages?track_id=${encodeURIComponent(message.track_id)}`,
                              )
                          ).data as Message[];
                      } catch {
                          return [];
                      }
                  }),
              );
        if (
            workspace.value.id !== workspaceId ||
            generation !== historyGeneration
        )
            return;
        const available = mergeChatMessages<Message>(
            [],
            [
                ...history.data,
                ...(realtimeEnabled ? liveMessages.get(target.id) || [] : []),
                ...correlated.flat(),
            ],
        );
        for (const message of available) {
            if (realtimeEnabled)
                message.status = nextMessageStatus(
                    message.status,
                    liveStatuses.get(message.id) ||
                        liveStatuses.get(message.whatsapp_id || ''),
                );
            const cached = mediaCache.get(message.media_endpoint || '');
            if (cached) {
                message.media_url = cached.url;
                message.media_unavailable = cached.unavailable;
            }
            message.media_loading = loadingMedia.has(
                message.media_endpoint || '',
            );
            if (['queued', 'pending'].includes(message.status || '')) {
                message.sending_status = 'confirmation_pending';
                const pending = pendingForThread.find(
                    (item) =>
                        item.track_id === message.track_id ||
                        item.id === message.id,
                );
                if (pending?.object_url) {
                    message.media_url ||= pending.object_url;
                    message.is_audio ||= pending.is_audio;
                    message.is_voice ||= pending.is_voice;
                    message.is_image ||= pending.is_image;
                }
            } else if (
                ['failed', 'canceled', 'cancelled'].includes(
                    message.status || '',
                )
            ) {
                message.sending_status = 'failed';
            }
        }
        for (const pending of pendingForThread) {
            const match = available.find(
                (item) =>
                    item.track_id === pending.track_id ||
                    item.id === pending.id,
            );
            if (match && !['queued', 'pending'].includes(match.status || ''))
                releasePending(pending.track_id!);
        }
        if (
            selected.value?.id !== target.id ||
            generation !== historyGeneration
        )
            return;
        const previousMessageKeys = new Set(
            messages.value.map(
                (message) => `${message.track_id || ''}:${message.id}`,
            ),
        );
        const optimistic = pendingMedia.value.filter(
            (message: any) => message.thread_id === target.id,
        );
        const known = new Set(
            available.map(
                (message) => `${message.track_id || ''}:${message.id}`,
            ),
        );
        messages.value = [
            ...available,
            ...optimistic.filter(
                (message: any) =>
                    !available.some(
                        (item) =>
                            item.track_id === message.track_id ||
                            item.id === message.id,
                    ) && !known.has(`${message.track_id || ''}:${message.id}`),
            ),
        ];
        historyReadVersion.value = Number.isInteger(history.read_version)
            ? history.read_version
            : null;
        const hasNewInbound = available.some(
            (message) =>
                !message.from_me &&
                !previousMessageKeys.has(
                    `${message.track_id || ''}:${message.id}`,
                ),
        );
        if (renderedThreadId === target.id && !shouldFollow && hasNewInbound)
            hasNewMessages.value = true;
        renderedThreadId = target.id;
        historyLimited.value = !!history.history_limited;
        await nextTick();
        await Promise.all(
            messages.value
                .filter(
                    (message) =>
                        isImage(message) &&
                        !message.media_unavailable &&
                        !message.media_url &&
                        message.media_endpoint,
                )
                .map((message) => renewMedia(message, false)),
        );
        if (shouldFollow) scrollToLatest();
        else updateLatestPosition();
    } catch {
        if (
            selected.value?.id === target.id &&
            workspace.value.id === workspaceId &&
            generation === historyGeneration
        ) {
            messages.value = pendingMedia.value.filter(
                (message) => message.thread_id === target.id,
            );
            historyLimited.value = true;
            historyReadVersion.value = null;
        }
    }
    if (selected.value?.id === target.id && generation === historyGeneration) {
        contactForm.display_name = target.contact.display_name ?? '';
        contactForm.email = target.contact.email ?? '';
        contactForm.cpf = target.contact.cpf ?? '';
    }
}
function updateLatestPosition() {
    const element = messagesElement.value;
    if (!element) return;
    isAtLatest.value =
        element.scrollHeight - element.scrollTop - element.clientHeight <= 48;
    if (isAtLatest.value) {
        hasNewMessages.value = false;
        markVisibleConversationRead();
    }
}
function scrollToLatest() {
    const element = messagesElement.value;
    if (!element) return;
    element.scrollTop = element.scrollHeight;
    isAtLatest.value = true;
    hasNewMessages.value = false;
    markVisibleConversationRead();
}
function retryReadFromLatestButton() {
    lastReadAttempt = null;
    scrollToLatest();
}
async function markVisibleConversationRead() {
    const target = selected.value;
    const version = historyReadVersion.value;
    const workspaceId = workspace.value.id;
    if (
        !target ||
        version === null ||
        target.unread_count < 1 ||
        (window.matchMedia('(max-width: 1023px)').matches &&
            mobilePane.value !== 'chat') ||
        document.visibilityState !== 'visible' ||
        !isAtLatest.value
    )
        return;
    const attemptKey = `${workspace.value.id}:${target.id}:${version}`;
    if (lastReadAttempt === attemptKey) return;
    lastReadAttempt = attemptKey;
    try {
        const result = await api(`${root()}/conversations/${target.id}/read`, {
            method: 'POST',
            body: JSON.stringify({ read_version: version }),
        });
        if (
            workspace.value.id !== workspaceId ||
            selected.value?.id !== target.id ||
            Number(result.data?.unread_version) < version
        )
            return;
        target.unread_count = result.data.unread_count;
        target.unread_version = result.data.unread_version;
        const listed = threads.value.find((thread) => thread.id === target.id);
        if (listed) {
            listed.unread_count = result.data.unread_count;
            listed.unread_version = result.data.unread_version;
        }
    } catch {
        // Keep the dot; a new visit to the latest messages can retry once.
    }
}
async function refreshConnection() {
    try {
        const old = connection.value?.status;
        const result = await api(
            `${root()}/uazapi/${realtimeEnabled ? 'connection' : 'status'}`,
        );
        connection.value = result.data;
        connectionLoaded.value = true;
        if (
            connection.value?.status === 'connected' &&
            !realtimeEnabled &&
            result.data.provider_available &&
            old !== 'connected'
        )
            await loadThreads();
    } catch {
        /* keep the last known provider status */
    }
}
async function action(kind: string) {
    if (!selected.value || busy.value) return;
    if (
        kind === 'finish' &&
        !(await confirm(
            'Finalizar remove o responsável atual e libera a automação para novos gatilhos. Deseja continuar?',
            'Finalizar atendimento',
        ))
    )
        return;
    busy.value = true;
    try {
        await api(`${root()}/conversations/${selected.value.id}/${kind}`, {
            method: 'POST',
            body: '{}',
        });
        await loadThreads();
    } catch (error: any) {
        await alert(error.message);
        await loadThreads();
    } finally {
        busy.value = false;
    }
}
async function assignContact() {
    if (!selected.value || !assignUserId.value || busy.value) return;
    busy.value = true;
    try {
        await api(`${root()}/conversations/${selected.value.id}/assign`, {
            method: 'POST',
            body: JSON.stringify({ user_id: Number(assignUserId.value) }),
        });
        await loadThreads();
    } catch (error: any) {
        await alert(error.message);
        await loadThreads();
    } finally {
        busy.value = false;
    }
}
async function sendMessage(event?: Event) {
    event?.preventDefault();
    if (!selected.value || !draft.value.trim() || composerBlocked.value) return;
    const threadId = selected.value.id;
    const workspaceId = workspace.value.id;
    const text = draft.value;
    const trackId = crypto.randomUUID();
    const optimistic = reactive<Message>({
        id: trackId,
        track_id: trackId,
        thread_id: threadId,
        from_me: true,
        type: 'text',
        text,
        timestamp: new Date().toISOString(),
        sending_status: 'sending',
    });
    if (realtimeEnabled) {
        pendingMedia.value.push(optimistic);
        messages.value.push(optimistic);
    }
    busy.value = true;
    try {
        const sent = await api(`${root()}/conversations/${threadId}/send`, {
            method: 'POST',
            body: JSON.stringify({
                text,
                ...(realtimeEnabled ? { track_id: trackId } : {}),
            }),
        });
        if (workspace.value.id !== workspaceId) return;
        draft.value = '';
        if (realtimeEnabled) {
            optimistic.sending_status =
                sent.data?.status === 'confirmation_pending'
                    ? 'confirmation_pending'
                    : sent.data?.status === 'pending' ||
                        sent.data?.status === 'running'
                      ? 'sending'
                      : 'sent';
            if (sent.data?.message)
                receiveChat({
                    type: 'message.received',
                    workspace_id: workspaceId,
                    thread_id: threadId,
                    data: sent.data.message,
                });
            await loadThreads();
        } else {
            await loadHistory();
            await loadThreads();
        }
    } catch (error: any) {
        if (realtimeEnabled)
            optimistic.sending_status =
                error.status >= 400 && error.status < 500
                    ? 'failed'
                    : 'confirmation_pending';
        await alert(error.message);
        await loadThreads();
    } finally {
        busy.value = false;
    }
}
async function sendFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !selected.value || composerBlocked.value) {
        input.value = '';
        return;
    }
    try {
        await sendAttachment(file);
    } finally {
        input.value = '';
    }
}
async function sendVoice() {
    if (
        !voiceBlob.value ||
        voiceState.value !== 'preview' ||
        busy.value ||
        !canRecord.value
    )
        return;
    const recording = voiceBlob.value;
    const duration = voiceSeconds.value;
    const extension = recording.type.includes('webm')
        ? 'webm'
        : recording.type.includes('ogg')
          ? 'ogg'
          : 'm4a';
    const file = new File([recording], `mensagem-de-voz.${extension}`, {
        type: recording.type,
    });
    discardVoice();
    await sendAttachment(file, true, duration);
}
async function sendAttachment(file: File, voice = false, duration?: number) {
    if (!selected.value) return;
    const threadId = selected.value.id;
    const workspaceId = workspace.value.id;
    const endpoint = `${root()}/conversations/${threadId}/send`;
    const trackId = crypto.randomUUID();
    const image = file.type.startsWith('image/');
    const preview = image || voice ? URL.createObjectURL(file) : undefined;
    const optimistic = reactive<Message>({
        id: trackId,
        track_id: trackId,
        thread_id: threadId,
        from_me: true,
        type: voice
            ? 'ptt'
            : image
              ? 'image'
              : file.type.startsWith('video/')
                ? 'video'
                : 'document',
        is_image: image,
        is_audio: voice,
        is_voice: voice,
        mime_type: file.type,
        duration_seconds: duration,
        text: '',
        caption: '',
        media_url: preview,
        object_url: preview,
        timestamp: new Date().toISOString(),
        sending_status: 'sending',
    });
    pendingMedia.value.push(optimistic);
    messages.value.push(optimistic);
    const body = new FormData();
    body.append('file', file);
    body.append('track_id', trackId);
    if (voice) body.append('media_type', 'voice');
    busy.value = true;
    try {
        const result = await api(endpoint, { method: 'POST', body });
        optimistic.sending_status =
            result.data.status === 'confirmation_pending'
                ? 'confirmation_pending'
                : result.data.status === 'pending' ||
                    result.data.status === 'running'
                  ? 'sending'
                  : 'sent';
        if (result.data.message) {
            // Keep the local preview until a playable provider URL is available.
            const localUrl = optimistic.object_url;
            Object.assign(optimistic, result.data.message, {
                thread_id: threadId,
                track_id: result.data.track_id || trackId,
                media_url: result.data.message.media_url || localUrl,
            });
            if (localUrl && result.data.message.media_url)
                revokeObjectUrl(optimistic);
        }
        if (workspace.value.id === workspaceId) {
            if (!realtimeEnabled && selected.value?.id === threadId)
                await loadHistory();
            await loadThreads();
        }
    } catch (error: any) {
        const rejected =
            error.data?.status === 'failed' ||
            (error.status >= 400 && error.status < 500);
        optimistic.sending_status = rejected
            ? 'failed'
            : 'confirmation_pending';
        if (workspace.value.id === workspaceId) {
            await alert(
                rejected
                    ? error.message
                    : `${error.message} A confirmação poderá ser consultada no histórico. Não envie novamente enquanto aguarda.`,
            );
            await loadThreads();
        }
    } finally {
        busy.value = false;
    }
}
async function saveContact() {
    if (!selected.value) return;
    try {
        await api(`${root()}/conversations/${selected.value.id}/contact`, {
            method: 'PUT',
            body: JSON.stringify(contactForm),
        });
        await loadThreads();
    } catch (error: any) {
        await alert(error.message);
    }
}
function resetPauseForm() {
    pauseFormOpen.value = false;
    pauseForm.choice = '30m';
    pauseForm.date = '';
    pauseForm.time = '';
    automationError.value = '';
}
function openPauseForm() {
    resetPauseForm();
    pauseFormOpen.value = true;
    void focusAutomation('input[type="radio"]:checked');
}
async function changeAutomation(action: 'pause' | 'resume') {
    if (!selected.value || automationBusy.value) return;
    const target = selected.value;
    if (
        action === 'pause'
            ? !target.automation?.can_pause
            : !target.automation?.can_resume
    )
        return;
    const workspaceId = workspace.value.id;
    const isCurrent = () =>
        selected.value?.id === target.id && workspace.value.id === workspaceId;
    let succeeded = false;
    automationBusy.value = true;
    automationError.value = '';
    try {
        const result = await api(
            `/workspaces/${workspaceId}/conversations/${target.id}/automations/${action}`,
            {
                method: 'POST',
                body: JSON.stringify(action === 'pause' ? pausePayload() : {}),
            },
        );
        if (workspace.value.id === workspaceId) {
            const listed = threads.value.find(
                (thread) => thread.id === target.id,
            );
            if (listed) listed.automation = result.data.automation;
            if (isCurrent() && selected.value) {
                selected.value.automation = result.data.automation;
                resetPauseForm();
                succeeded = true;
            }
            await loadThreads();
        }
    } catch (error: any) {
        if (isCurrent()) {
            automationError.value =
                error.errors?.expires_at?.[0] || error.message;
            void focusAutomation('#automation-error');
        }
    } finally {
        automationBusy.value = false;
        if (succeeded && isCurrent())
            void focusAutomation('[data-automation-action]:not(:disabled)');
    }
}
async function addNote() {
    if (!selected.value || !noteDraft.value.trim()) return;
    try {
        await api(`${root()}/conversations/${selected.value.id}/notes`, {
            method: 'POST',
            body: JSON.stringify({ body: noteDraft.value }),
        });
        noteDraft.value = '';
        await loadThreads();
    } catch (error: any) {
        await alert(error.message);
    }
}
function previewText(thread: Thread) {
    const names: Record<string, string> = {
        image: 'Imagem',
        audio: 'Áudio',
        ptt: 'Áudio',
        video: 'Vídeo',
        document: 'Documento',
    };
    return (
        thread.last_message_preview ||
        names[thread.last_message_type || ''] ||
        'Mídia'
    );
}
function receiveChat(event: ChatUpdate) {
    if (event.workspace_id !== workspace.value.id) return;
    if (event.type === 'access.changed') {
        threads.value = [];
        selected.value = null;
        messages.value = [];
        liveMessages.clear();
        void loadThreads();
        return;
    }
    if (event.type === 'conversation.invalidate') {
        if (event.thread_id === selected.value?.id) void loadThreads();
        return;
    }
    if (event.type === 'connection.changed') {
        const wasConnected = connection.value?.status === 'connected';
        connection.value = { ...connection.value, ...event.data };
        connectionLoaded.value = true;
        if (!wasConnected && connection.value?.status === 'connected')
            void loadThreads();
        return;
    }
    if (event.type === 'conversation.removed') {
        threads.value = threads.value.filter(
            (thread) => thread.id !== event.data.id,
        );
        if (selected.value?.id === event.data.id) {
            selected.value = null;
            messages.value = [];
        }
        liveMessages.delete(event.data.id);
        return;
    }
    if (event.type === 'conversation.changed') {
        const incoming = event.data as Thread;
        if (incoming.contact.avatar_url)
            contactAvatars[incoming.id] = incoming.contact.avatar_url;
        const current = threads.value.find(
            (thread) => thread.id === incoming.id,
        );
        if ((current?.revision || 0) > (incoming.revision || 0)) return;
        const matches =
            (assignment.value !== 'mine' ||
                incoming.assigned_to?.id === me.value.id) &&
            (assignment.value !== 'unassigned' || !incoming.assigned_to) &&
            (statusFilter.value !== 'open' ||
                ['open', 'in_progress'].includes(incoming.status)) &&
            (appliedTagIds.value.length === 0 ||
                (incoming.tags ?? incoming.contact.tags ?? []).some((tag) =>
                    appliedTagIds.value.includes(tag.id),
                )) &&
            (!search.value ||
                `${incoming.contact.display_name || ''} ${incoming.contact.phone || ''}`
                    .toLocaleLowerCase()
                    .includes(search.value.toLocaleLowerCase()));
        threads.value = threads.value.filter(
            (thread) => thread.id !== incoming.id,
        );
        if (matches) threads.value.push(incoming);
        threads.value.sort(
            (a, b) =>
                (Date.parse(b.last_message_at || '') || 0) -
                    (Date.parse(a.last_message_at || '') || 0) || b.id - a.id,
        );
        if (selected.value?.id === incoming.id) {
            selected.value = { ...selected.value, ...incoming };
            historyReadVersion.value = incoming.unread_version;
        }
        return;
    }
    if (
        event.type === 'message.received' &&
        event.thread_id === selected.value?.id
    ) {
        const message = event.data as Message;
        const buffer = liveMessages.get(event.thread_id!) || [];
        const same = (item: Message) => sameChatMessage(item, message);
        liveMessages.set(event.thread_id!, [
            ...buffer.filter((item) => !same(item)),
            message,
        ]);
        const existing = messages.value.find(same);
        if (existing) {
            const localUrl = existing.media_url;
            Object.assign(existing, message);
            if (!['pending', 'queued'].includes(message.status || ''))
                existing.sending_status =
                    message.status === 'failed' ? 'failed' : 'sent';
            existing.media_url ||= localUrl;
        } else messages.value.push(message);
        if (message.track_id && (!existing?.object_url || message.media_url))
            releasePending(message.track_id);
        if (isAtLatest.value) void nextTick().then(scrollToLatest);
        else if (!message.from_me) hasNewMessages.value = true;
        return;
    }
    if (
        event.type === 'message.status' &&
        event.thread_id === selected.value?.id
    ) {
        for (const id of event.data.ids as string[]) {
            const previous =
                liveStatuses.get(id) ||
                messages.value.find(
                    (message) =>
                        message.id === id || message.whatsapp_id === id,
                )?.status ||
                '';
            const status = nextMessageStatus(previous, event.data.status)!;
            liveStatuses.set(id, status);
            for (const message of messages.value)
                if (message.id === id || message.whatsapp_id === id)
                    message.status = status;
        }
        return;
    }
    if (event.type === 'import.failed') {
        importState.value.status = 'failed';
        return;
    }
    if (event.type === 'import.completed') {
        if (event.data.complete) {
            void loadThreads().then(continueSearch);
            return;
        }
        if (importGeneration !== searchGeneration) {
            void loadThreads().then(continueSearch);
            return;
        }
        void loadThreads(true).then(continueSearch);
    }
}
async function continueSearch() {
    if (
        !realtimeEnabled ||
        (!search.value &&
            assignment.value === 'all' &&
            statusFilter.value === 'all' &&
            appliedTagIds.value.length === 0) ||
        threads.value.length >= 200 ||
        importState.value.complete ||
        importState.value.status === 'running' ||
        automaticPages.value >= 3
    )
        return;
    automaticPages.value++;
    importGeneration = searchGeneration;
    await importNextPage();
}
async function importNextPage() {
    if (importState.value.status === 'running') return;
    importState.value.status = 'running';
    try {
        await api(`${root()}/uazapi/import`, { method: 'POST', body: '{}' });
    } catch {
        importState.value.status = 'failed';
    }
}
async function loadMoreChats() {
    if (loadingThreads.value) return;
    if (nextCursor.value) await loadThreads(true);
    else if (
        !importState.value.complete &&
        importState.value.status !== 'running'
    ) {
        importGeneration = searchGeneration;
        await importNextPage();
    }
}
function onListScroll(event: Event) {
    if (!realtimeEnabled) return;
    const element = event.target as HTMLElement;
    if (
        element.scrollHeight - element.scrollTop - element.clientHeight < 120 &&
        (!search.value || automaticPages.value < 3)
    )
        void loadMoreChats();
}
function choose(thread: Thread) {
    selected.value = thread;
    if (realtimeEnabled) {
        messages.value = [];
        liveMessages.delete(thread.id);
    }
    mobilePane.value = 'chat';
    isAtLatest.value = true;
    hasNewMessages.value = false;
    historyReadVersion.value = null;
    lastReadAttempt = null;
    loadHistory();
    loadAvatar(thread);
}
async function loadAvatar(thread: Thread, refresh = false) {
    const forceRefresh = refresh || avatarRetries.has(thread.id);
    if (avatarLoaded.has(thread.id) && !forceRefresh) return;
    if (thread.contact.avatar_url && !forceRefresh) {
        avatarLoaded.add(thread.id);
        contactAvatars[thread.id] = thread.contact.avatar_url;
        return;
    }
    if (realtimeEnabled && !realtimeConnected.value) return;
    avatarLoaded.add(thread.id);
    try {
        const result = await api(
            `${root()}/conversations/${thread.id}/avatar${forceRefresh ? '?refresh=1' : ''}`,
        );
        contactAvatars[thread.id] = result.data.url || null;
    } catch {
        avatarLoaded.delete(thread.id);
        contactAvatars[thread.id] = null;
    }
}
function avatarError(thread: Thread) {
    contactAvatars[thread.id] = null;
    if (avatarRetries.has(thread.id)) return;
    avatarRetries.add(thread.id);
    avatarLoaded.delete(thread.id);
    loadAvatar(thread, true);
}
function messageAvatar(thread: Thread) {
    return Object.prototype.hasOwnProperty.call(contactAvatars, thread.id)
        ? contactAvatars[thread.id]
        : (thread.contact.avatar_url ?? null);
}
function revokeObjectUrl(message: Message) {
    if (message.object_url) URL.revokeObjectURL(message.object_url);
    message.object_url = undefined;
}
function releasePending(trackId: string) {
    const message = pendingMedia.value.find(
        (item) => item.track_id === trackId,
    );
    if (message) revokeObjectUrl(message);
    pendingMedia.value = pendingMedia.value.filter(
        (item) => item.track_id !== trackId,
    );
}
async function renewMedia(message: Message, renewal = true) {
    const key = message.media_endpoint;
    if (!key) {
        if (renewal) {
            message.media_url = null;
            message.media_unavailable = true;
        }
        return;
    }
    if (loadingMedia.has(key)) return;
    if (renewal && renewedMedia.has(key)) {
        message.media_unavailable = true;
        message.media_url = null;
        mediaCache.set(key, { url: null, unavailable: true });
        return;
    }
    if (renewal) renewedMedia.add(key);
    loadingMedia.add(key);
    message.media_loading = true;
    try {
        const result = await api(key);
        mediaCache.set(key, {
            url: result.data.url || null,
            unavailable: !result.data.url,
        });
    } catch {
        mediaCache.set(key, { url: null, unavailable: true });
    } finally {
        loadingMedia.delete(key);
        // Polling may have replaced the object while the download was running.
        for (const item of [
            message,
            ...messages.value.filter((item) => item.media_endpoint === key),
        ]) {
            const cached = mediaCache.get(key);
            item.media_url = cached?.url || null;
            item.media_unavailable = cached?.unavailable ?? true;
            item.media_loading = false;
        }
    }
}
function isAudio(message: Message) {
    return !!message.is_audio || /audio|ptt/i.test(message.type);
}
function durationLabel(seconds: number) {
    const whole = Math.floor(seconds);
    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}
function isImage(message: Message) {
    return !!message.is_image || /image|photo/i.test(message.type);
}
const vAvatar: Directive<HTMLElement, Thread> = {
    mounted(element, binding) {
        (element as any).__avatarThread = binding.value.id;
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    visibleAvatars.add(binding.value.id);
                    void loadAvatar(binding.value);
                    observer.disconnect();
                }
            },
            { rootMargin: '80px' },
        );
        observer.observe(element);
        (element as any).__avatarObserver = observer;
    },
    updated(element, binding) {
        if (binding.value.id !== binding.oldValue?.id) {
            if (binding.oldValue) visibleAvatars.delete(binding.oldValue.id);
            (element as any).__avatarThread = binding.value.id;
            visibleAvatars.add(binding.value.id);
            void loadAvatar(binding.value);
        }
    },
    unmounted(element) {
        const thread = (element as any).__avatarThread as number | undefined;
        if (thread) visibleAvatars.delete(thread);
        (element as any).__avatarObserver?.disconnect();
    },
};
function initials(name?: string | null) {
    return (name || '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((x) => x[0])
        .join('')
        .toUpperCase();
}

function toggleContactProfile() {
    if (window.matchMedia('(max-width: 1023px)').matches) {
        mobilePane.value = 'profile';
        return;
    }

    profileCollapsed.value = !profileCollapsed.value;
}
function time(value?: string | null) {
    return value
        ? new Intl.DateTimeFormat('pt-BR', {
              hour: '2-digit',
              minute: '2-digit',
          }).format(new Date(value))
        : '';
}
function messageDate(value?: string | null) {
    return formatMessageDate(
        value,
        displayNow.value,
        workspace.value.timezone || 'UTC',
    );
}
function statusBadge(thread: Thread) {
    const automation = thread.automation;
    if (
        connection.value?.status !== 'connected' ||
        !automation ||
        automation.state === 'unavailable'
    ) {
        return null;
    }
    if (automation.pause_reasons.includes('human')) {
        return { label: 'Humanizado', className: 'humanized' };
    }
    if (automation.state === 'paused') {
        return { label: 'Bot pausado', className: 'bot-paused' };
    }
    if (automation.state === 'allowed') {
        return { label: 'Bot ativo', className: 'bot-active' };
    }
    return null;
}
const canCompose = computed(
    () =>
        !!selected.value &&
        (!selected.value.assigned_to ||
            selected.value.assigned_to.id === me.value.id),
);
const canRecord = computed(
    () =>
        canCompose.value &&
        connection.value?.status === 'connected' &&
        connection.value.provider_available !== false &&
        !busy.value,
);
const humanStatus = computed(() => {
    switch (selected.value?.status) {
        case 'open':
            return 'Aberto';
        case 'in_progress':
            return 'Em atendimento';
        case 'completed':
            return 'Finalizado';
        default:
            return 'Automação';
    }
});
watch(
    () => selected.value?.id,
    () => {
        assignUserId.value = '';
    },
);
const recordingContext = computed(() =>
    selected.value ? `${workspace.value.id}:${selected.value.id}` : null,
);
const {
    state: voiceState,
    seconds: voiceSeconds,
    blob: voiceBlob,
    previewUrl: voicePreviewUrl,
    error: voiceError,
    active: voiceActive,
    start: startVoice,
    stop: stopVoice,
    discard: discardVoice,
} = useVoiceRecorder(canRecord, recordingContext);
const composerBlocked = computed(
    () => busy.value || voiceActive.value || !canRecord.value,
);
watch(
    () => workspace.value.id,
    () => {
        for (const id of Object.keys(contactAvatars))
            delete contactAvatars[Number(id)];
        avatarLoaded.clear();
        avatarRetries.clear();
        visibleAvatars.clear();
        pendingMedia.value.forEach(revokeObjectUrl);
        pendingMedia.value = [];
        messages.value = [];
        selected.value = null;
        connectionLoaded.value = false;
        renderedThreadId = null;
        historyReadVersion.value = null;
        hasNewMessages.value = false;
        lastReadAttempt = null;
        liveMessages.clear();
        liveStatuses.clear();
        nextCursor.value = null;
        endCursor.value = null;
        searchGeneration++;
        renewedMedia.clear();
        mediaCache.clear();
        if (realtimeEnabled) {
            void refreshConnection();
            void loadThreads();
        }
    },
    { flush: 'sync' },
);
watch(realtimeConnected, (connected) => {
    if (!connected) return;
    for (const id of visibleAvatars) {
        const thread = threads.value.find((item) => item.id === id);
        if (thread) void loadAvatar(thread);
    }
});
watch([search, assignment, statusFilter, appliedTagIds], () => {
    if (!realtimeEnabled) {
        void loadThreads();
        return;
    }
    searchGeneration++;
    automaticPages.value = 0;
    nextCursor.value = null;
    endCursor.value = null;
    clearTimeout(searchTimer);
    listAbort?.abort();
    searchTimer = setTimeout(
        () => void loadThreads().then(continueSearch),
        300,
    );
});
watch(
    () => selected.value?.id,
    () => resetPauseForm(),
);
watch(
    () => selected.value?.automation?.can_pause,
    (canPause) => {
        if (!canPause) pauseFormOpen.value = false;
    },
);
watch(
    () => pauseForm.choice,
    () => {
        automationError.value = '';
    },
);
watch(mobilePane, (pane) => {
    if (pane === 'chat') void nextTick().then(updateLatestPosition);
});
onMounted(async () => {
    dateLabelTimer = window.setInterval(refreshDateLabels, 60000);
    document.addEventListener('visibilitychange', refreshDateLabels);
    if (realtimeEnabled) {
        realtime = openChatRealtime(
            page.props.chatRealtime,
            page.props.currentUser.id,
            receiveChat,
        );
        watch(
            realtime.connected,
            (value) => (realtimeConnected.value = value),
            { immediate: true },
        );
        void refreshConnection();
        void loadThreads();
        return;
    }
    await refreshConnection();
    statusTimer = window.setInterval(refreshConnection, 15000);
    listTimer = window.setInterval(() => {
        if (connection.value?.status === 'connected') loadThreads();
    }, 30000);
});
onUnmounted(() => {
    window.clearInterval(dateLabelTimer);
    document.removeEventListener('visibilitychange', refreshDateLabels);
    window.clearInterval(statusTimer);
    window.clearInterval(listTimer);
    pendingMedia.value.forEach(revokeObjectUrl);
    clearTimeout(searchTimer);
    listAbort?.abort();
    realtime?.close();
});
</script>

<template>
    <Head title="Conversas" />
    <main
        class="chat-shell"
        :class="{
            'profile-collapsed': profileCollapsed,
        }"
    >
        <section
            class="list-pane"
            :class="{ 'mobile-hidden': mobilePane !== 'list' }"
        >
            <h1 class="sr-only">Conversas</h1>
            <div class="search-row">
                <label class="search"
                    ><Search :size="16" aria-hidden="true" /><Input
                        v-model="search"
                        aria-label="Buscar por nome ou telefone"
                        placeholder="Buscar conversas"
                /></label>
                <button
                    type="button"
                    class="filter-trigger"
                    :class="{ active: activeFilterCount > 0 }"
                    :aria-label="
                        activeFilterCount
                            ? `Filtrar conversas, ${activeFilterCount} critérios ativos`
                            : 'Filtrar conversas'
                    "
                    :aria-expanded="filterOpen"
                    aria-haspopup="dialog"
                    title="Filtrar conversas"
                    @click="openFilters"
                >
                    <ListFilter :size="18" aria-hidden="true" />
                    <span v-if="activeFilterCount" class="filter-count">{{
                        activeFilterCount
                    }}</span>
                </button>
            </div>
            <div
                v-if="connectionWarning"
                class="connection-warning"
                role="status"
            >
                <p v-if="connection?.provider_available === false">
                    WhatsApp indisponível. As conversas podem estar
                    desatualizadas.
                </p>
                <p v-else>
                    WhatsApp desconectado. Conecte para carregar as conversas.
                </p>
                <a v-if="isAdmin" href="/settings/whatsapp"
                    >Configurar conexão</a
                >
            </div>
            <p
                v-if="realtimeEnabled && !realtimeConnected"
                role="status"
                class="notice"
            >
                Tempo real indisponível. Os dados podem estar desatualizados;
                reabra a conversa após reconectar.
            </p>
            <p v-if="realtimeEnabled && !importState.complete" class="notice">
                Lista parcial. Nem todas as conversas foram carregadas.
            </p>
            <p
                v-if="realtimeEnabled && importState.status === 'failed'"
                class="notice"
                role="status"
            >
                Não foi possível atualizar a lista. Uma nova tentativa será
                feita automaticamente.
            </p>
            <AppButton
                v-if="realtimeEnabled && (nextCursor || !importState.complete)"
                :disabled="
                    loadingThreads ||
                    (!nextCursor && importState.status === 'running')
                "
                @click="loadMoreChats"
                >{{
                    !nextCursor && importState.status === 'running'
                        ? 'Carregando conversas…'
                        : 'Carregar mais conversas'
                }}</AppButton
            >
            <div class="thread-list" @scroll="onListScroll">
                <button
                    v-for="thread in threads"
                    :key="thread.id"
                    type="button"
                    class="thread"
                    :class="{ chosen: selected?.id === thread.id }"
                    :aria-pressed="selected?.id === thread.id"
                    @click="choose(thread)"
                >
                    <span v-avatar="thread" class="avatar"
                        >{{ initials(thread.contact.display_name)
                        }}<img
                            v-if="messageAvatar(thread)"
                            :src="messageAvatar(thread)!"
                            alt=""
                            @error="avatarError(thread)" /></span
                    ><span class="thread-main"
                        ><span class="thread-top"
                            ><b>{{
                                thread.contact.display_name ||
                                thread.contact.phone ||
                                'Contato sem nome'
                            }}</b
                            ><span class="thread-time"
                                ><small>{{
                                    messageDate(thread.last_message_at)
                                }}</small
                                ><span
                                    v-if="thread.unread_count > 0"
                                    class="unread-dot"
                                    aria-label="Mensagens não lidas"
                                ></span></span></span
                        ><small
                            v-if="realtimeEnabled && thread.last_message_type"
                            class="message-preview"
                            >{{ previewText(thread) }}</small
                        >
                        <span class="thread-bottom"
                            ><small>{{
                                thread.assigned_to?.name || 'Sem responsável'
                            }}</small
                            ><em
                                v-if="statusBadge(thread)"
                                :class="statusBadge(thread)?.className"
                                >{{ statusBadge(thread)?.label }}</em
                            ></span
                        ></span
                    >
                </button>
                <p
                    v-if="connection?.status === 'connected' && !threads.length"
                    class="empty"
                >
                    Nenhuma conversa encontrada.
                </p>
            </div>
        </section>
        <section
            class="chat-pane"
            :class="{ 'mobile-hidden': mobilePane !== 'chat' }"
        >
            <template v-if="selected">
                <header class="chat-heading">
                    <button
                        class="back"
                        type="button"
                        aria-label="Voltar para conversas"
                        title="Voltar para conversas"
                        @click="mobilePane = 'list'"
                    >
                        <ArrowLeft :size="20" /></button
                    ><span class="avatar mini">
                        {{ initials(selected.contact.display_name)
                        }}<img
                            v-if="messageAvatar(selected)"
                            :src="messageAvatar(selected)!"
                            alt=""
                            @error="avatarError(selected)"
                        />
                    </span>
                    <div class="title">
                        <b>{{
                            selected.contact.display_name ||
                            selected.contact.phone
                        }}</b
                        ><small>{{ selected.contact.phone }}</small>
                        <small class="service-state"
                            >{{ humanStatus }} ·
                            {{
                                selected.assigned_to?.name || 'Sem responsável'
                            }}</small
                        >
                    </div>
                    <div class="actions">
                        <!--
                        <em
                            v-if="statusBadge(selected)"
                            :class="statusBadge(selected)?.className"
                            >{{ statusBadge(selected)?.label }}</em
                        >
                        -->
                        <button
                            class="profile-trigger"
                            type="button"
                            :aria-label="
                                profileCollapsed
                                    ? 'Abrir perfil do contato'
                                    : 'Recolher perfil do contato'
                            "
                            :aria-expanded="
                                !profileCollapsed || mobilePane === 'profile'
                            "
                            title="Perfil do contato"
                            @click="toggleContactProfile"
                        >
                            <ContactRound :size="20" />
                        </button>
                    </div>
                </header>
                <p class="retention">Histórico dos útimos 7 dias.</p>
                <div
                    ref="messagesElement"
                    class="messages"
                    @scroll.passive="updateLatestPosition"
                >
                    <div v-if="historyLimited" class="history-warning">
                        A UAZAPI não disponibilizou este histórico. Mensagens
                        antigas podem ter expirado ou não terem sido
                        sincronizadas pelo aparelho.
                    </div>
                    <p v-else-if="!messages.length" class="no-messages">
                        As mensagens desta conversa aparecerão aqui.
                    </p>
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="message-row"
                        :class="message.from_me ? 'mine' : 'theirs'"
                    >
                        <article class="bubble">
                            <div v-if="isAudio(message)" class="audio-message">
                                <span
                                    >{{
                                        message.is_voice
                                            ? 'Mensagem de voz'
                                            : 'Áudio'
                                    }}<template v-if="message.duration_seconds">
                                        ·
                                        {{
                                            durationLabel(
                                                message.duration_seconds,
                                            )
                                        }}</template
                                    ></span
                                >
                                <audio
                                    v-if="message.media_url"
                                    :src="message.media_url"
                                    controls
                                    preload="none"
                                    aria-label="Reproduzir áudio da conversa"
                                    @error="renewMedia(message)"
                                />
                                <p v-else-if="message.media_unavailable">
                                    Áudio indisponível
                                </p>
                                <AppButton
                                    v-else-if="message.media_endpoint"
                                    type="button"
                                    variant="secondary"
                                    :disabled="message.media_loading"
                                    @click="renewMedia(message, false)"
                                >
                                    {{
                                        message.media_loading
                                            ? 'Carregando áudio…'
                                            : 'Carregar áudio'
                                    }}
                                </AppButton>
                            </div>
                            <button
                                v-else-if="
                                    isImage(message) && message.media_url
                                "
                                class="image-preview"
                                @click="expandedImage = message.media_url || ''"
                            >
                                <img
                                    :src="message.media_url"
                                    alt="Imagem enviada na conversa"
                                    @error="renewMedia(message)"
                                />
                            </button>
                            <p
                                v-else-if="
                                    isImage(message) &&
                                    message.media_unavailable
                                "
                            >
                                Imagem indisponível
                            </p>
                            <a
                                v-else-if="message.media_url"
                                :href="message.media_url"
                                target="_blank"
                                rel="noopener"
                                >Ver anexo</a
                            >
                            <p v-if="message.caption || message.text">
                                {{ message.caption || message.text }}
                            </p>
                            <small
                                >{{ messageDate(message.timestamp)
                                }}<span
                                    v-if="message.sending_status === 'sending'"
                                >
                                    · Enviando</span
                                ><span
                                    v-else-if="
                                        message.sending_status ===
                                        'confirmation_pending'
                                    "
                                >
                                    · Aguardando confirmação</span
                                ><span
                                    v-else-if="
                                        message.sending_status === 'failed'
                                    "
                                >
                                    · Falhou</span
                                ></small
                            >
                        </article>
                    </div>
                    <button
                        v-if="hasNewMessages"
                        class="new-messages-button"
                        type="button"
                        @click="retryReadFromLatestButton"
                    >
                        <ArrowDown :size="16" /> Novas mensagens
                    </button>
                </div>
                <div class="composer-area">
                    <p v-if="!canCompose" class="composer-hint">
                        Responsável: {{ selected.assigned_to?.name }}. Altere a
                        atribuição no Perfil do contato para responder.
                    </p>
                    <p
                        v-else-if="
                            selected.status !== 'in_progress' ||
                            !selected.assigned_to
                        "
                        class="composer-hint"
                    >
                        Ao enviar, você assume o atendimento e pausa a
                        automação.
                    </p>
                    <div
                        v-if="voiceActive"
                        class="voice-composer"
                        aria-live="polite"
                    >
                        <span v-if="voiceState === 'requesting'"
                            >Aguardando permissão do microfone…</span
                        >
                        <template
                            v-else-if="
                                voiceState === 'recording' ||
                                voiceState === 'stopping'
                            "
                        >
                            <span class="recording-indicator"
                                >● Gravando {{ durationLabel(voiceSeconds) }} /
                                2:00</span
                            >
                            <AppButton
                                type="button"
                                variant="secondary"
                                :disabled="voiceState === 'stopping'"
                                @click="stopVoice"
                            >
                                Parar
                            </AppButton>
                        </template>
                        <template v-else-if="voiceState === 'preview'">
                            <span
                                >Prévia ·
                                {{ durationLabel(voiceSeconds) }}</span
                            >
                            <audio
                                :src="voicePreviewUrl"
                                controls
                                preload="metadata"
                                aria-label="Ouvir gravação antes de enviar"
                            />
                            <AppButton
                                type="button"
                                :disabled="!canRecord"
                                @click="sendVoice"
                                variant="primary"
                            >
                                Enviar áudio
                            </AppButton>
                        </template>
                        <AppButton
                            type="button"
                            variant="secondary"
                            @click="discardVoice"
                        >
                            Descartar
                        </AppButton>
                    </div>
                    <p v-if="voiceError" role="alert" class="voice-error">
                        {{ voiceError }}
                    </p>
                    <form class="composer" @submit="sendMessage">
                        <label class="attach" aria-label="Anexar arquivo"
                            ><Plus :size="20" /><input
                                type="file"
                                :disabled="composerBlocked"
                                accept="image/*,video/mp4,video/quicktime,.pdf,.doc,.docx"
                                @change="sendFile"
                        /></label>
                        <Textarea
                            v-model="draft"
                            rows="1"
                            :disabled="composerBlocked"
                            aria-label="Mensagem para o contato"
                            placeholder="Mensagem…"
                            @keydown.enter.exact.prevent="sendMessage"
                        />
                        <button
                            type="button"
                            class="voice-button"
                            :disabled="composerBlocked"
                            aria-label="Gravar mensagem de voz"
                            title="Gravar mensagem de voz"
                            @click="startVoice"
                        >
                            <Mic :size="18" />
                        </button>
                        <button
                            class="send"
                            :disabled="composerBlocked || !draft.trim()"
                            aria-label="Enviar mensagem"
                        >
                            <Send :size="18" />
                        </button>
                    </form>
                </div>
            </template>
            <div v-else class="empty-chat">
                <h2>Selecione uma conversa</h2>
                <p>Escolha um contato na lista para começar o atendimento.</p>
            </div>
        </section>
        <aside
            v-if="selected"
            class="profile-pane"
            :class="{ 'profile-mobile-open': mobilePane === 'profile' }"
        >
            <header class="profile-heading">
                <button
                    class="back"
                    aria-label="Voltar para conversa"
                    title="Voltar para conversa"
                    @click="mobilePane = 'chat'"
                >
                    <ArrowLeft :size="20" /></button
                ><b>Perfil do contato</b
                ><button
                    class="profile-collapse"
                    title="Recolher perfil do contato"
                    aria-label="Recolher perfil do contato"
                    @click="profileCollapsed = true"
                >
                    <PanelRightClose :size="18" />
                </button>
            </header>
            <div class="identity">
                <span class="avatar big"
                    >{{ initials(selected.contact.display_name)
                    }}<img
                        v-if="messageAvatar(selected)"
                        :src="messageAvatar(selected)!"
                        alt=""
                        @error="avatarError(selected)" /></span
                ><b>{{ selected.contact.display_name || 'Contato sem nome' }}</b
                ><small>{{ selected.contact.phone }}</small>
            </div>
            <section class="service-controls" aria-labelledby="service-heading">
                <h3 id="service-heading">Atendimento</h3>
                <p><strong>Estado:</strong> {{ humanStatus }}</p>
                <p>
                    <strong>Responsável:</strong>
                    {{ selected.assigned_to?.name || 'Sem responsável' }}
                </p>
                <AppButton
                    v-if="
                        !selected.assigned_to &&
                        (selected.status === 'bot' ||
                            selected.status === 'completed')
                    "
                    variant="secondary"
                    class="full"
                    :disabled="busy"
                    @click="action('open')"
                    >Abrir atendimento</AppButton
                >
                <AppButton
                    v-if="
                        selected.assigned_to?.id !== me.id ||
                        selected.status !== 'in_progress'
                    "
                    variant="primary"
                    class="full"
                    :disabled="busy"
                    @click="action('claim')"
                    >Assumir para mim</AppButton
                >
                <div
                    v-if="isAdmin && page.props.assignableMembers?.length"
                    class="assignment-picker"
                >
                    <AppSelect
                        v-model="assignUserId"
                        aria-label="Atribuir a membro da equipe"
                    >
                        <option value="">Escolher membro da equipe</option>
                        <option
                            v-for="member in page.props.assignableMembers"
                            :key="member.id"
                            :value="String(member.id)"
                        >
                            {{ member.name }}
                        </option>
                    </AppSelect>
                    <AppButton
                        variant="secondary"
                        :disabled="busy || !assignUserId"
                        @click="assignContact"
                        >Atribuir</AppButton
                    >
                </div>
                <AppButton
                    v-if="
                        ['open', 'in_progress'].includes(selected.status) &&
                        (selected.assigned_to?.id === me.id || isAdmin)
                    "
                    variant="secondary"
                    class="full"
                    :disabled="busy"
                    @click="action('finish')"
                    >Finalizar atendimento</AppButton
                >
            </section>
            <form class="profile-form" @submit.prevent="saveContact">
                <label>Nome<Input v-model="contactForm.display_name" /></label
                ><label
                    >E-mail<Input
                        v-model="contactForm.email"
                        type="email" /></label
                ><label
                    >CPF<Input
                        v-model="contactForm.cpf"
                        inputmode="numeric" /></label
                ><AppButton
                    type="submit"
                    variant="secondary"
                    class="full"
                    :disabled="busy"
                    >Salvar perfil</AppButton
                >
            </form>
            <section
                v-if="
                    selected.contact.tags?.length ||
                    selected.contact.custom_fields?.length
                "
                class="profile-data"
            >
                <h3>Dados do contato</h3>
                <p v-if="selected.contact.tags?.length">
                    <b>Etiquetas:</b>
                    {{
                        selected.contact.tags.map((tag) => tag.name).join(', ')
                    }}
                </p>
                <p
                    v-for="field in selected.contact.custom_fields"
                    :key="field.id"
                >
                    <b>{{ field.name }}:</b> {{ field.value || '—' }}
                </p>
            </section>
            <section
                ref="automationRegion"
                class="automation-pause"
                aria-labelledby="automation-heading"
            >
                <div class="automation-heading">
                    <h3 id="automation-heading">Automação</h3>
                    <span
                        data-automation-status
                        tabindex="-1"
                        class="automation-status"
                        :class="`automation-status-${selected.automation?.state || 'allowed'}`"
                        role="status"
                        aria-live="polite"
                    >
                        <CirclePause
                            v-if="selected.automation?.state === 'paused'"
                            :size="14"
                            aria-hidden="true"
                        />
                        <CircleOff
                            v-else-if="
                                selected.automation?.state === 'unavailable'
                            "
                            :size="14"
                            aria-hidden="true"
                        />
                        <CircleCheck v-else :size="14" aria-hidden="true" />
                        {{ automationStatus }}
                    </span>
                </div>
                <div class="automation-summary" aria-live="polite">
                    <p v-if="selected.automation?.state === 'unavailable'">
                        Indisponível sem uma conexão do WhatsApp.
                    </p>
                    <template v-else>
                        <template v-if="automationPauseLabel">
                            <p class="automation-deadline">
                                {{ automationPauseLabel }}
                            </p>
                            <p
                                v-if="selected.automation?.expires_at"
                                class="automation-helper"
                            >
                                Fuso: {{ automationTimezone }}
                            </p>
                            <p>
                                Ao liberar, a automação aguarda um novo gatilho.
                            </p>
                        </template>
                        <p
                            v-if="
                                selected.automation?.pause_reasons.includes(
                                    'human',
                                )
                            "
                            class="automation-human"
                        >
                            Finalize o atendimento humano para liberar a
                            automação.
                        </p>
                        <p
                            v-if="
                                selected.automation?.execution_status ===
                                'running'
                            "
                        >
                            Execução em andamento.
                        </p>
                        <p
                            v-else-if="
                                selected.automation?.execution_status ===
                                'waiting_reply'
                            "
                        >
                            Aguardando resposta do contato.
                        </p>
                        <p
                            v-else-if="
                                selected.automation?.execution_status ===
                                'waiting_menu'
                            "
                        >
                            Aguardando escolha no menu.
                        </p>
                        <p
                            v-else-if="
                                selected.automation?.execution_status ===
                                'waiting_ai'
                            "
                        >
                            Atendimento com IA em andamento.
                        </p>
                        <p class="automation-helper">
                            Apenas este contato nesta conexão do WhatsApp.
                        </p>
                    </template>
                </div>
                <template v-if="selected.automation?.state !== 'unavailable'">
                    <AppButton
                        v-if="selected.automation?.can_pause && !pauseFormOpen"
                        data-pause-trigger
                        data-automation-action
                        type="button"
                        variant="secondary"
                        class="full"
                        :disabled="automationBusy"
                        @click="openPauseForm"
                        >Pausar automação</AppButton
                    >
                    <form
                        v-if="pauseFormOpen && selected.automation?.can_pause"
                        class="automation-form"
                        @submit.prevent="changeAutomation('pause')"
                    >
                        <fieldset :disabled="automationBusy">
                            <legend>Por quanto tempo?</legend>
                            <div
                                v-for="choice in pauseChoices"
                                :key="choice.value"
                                class="automation-choice"
                            >
                                <label
                                    class="automation-option"
                                    :class="{
                                        'automation-option-selected':
                                            pauseForm.choice === choice.value,
                                    }"
                                >
                                    <input
                                        v-model="pauseForm.choice"
                                        type="radio"
                                        :value="choice.value"
                                        :aria-label="choice.label"
                                        name="automation-pause-mode"
                                        :aria-describedby="`pause-description-${choice.value}`"
                                    />
                                    <span
                                        ><strong>{{ choice.label }}</strong
                                        ><span
                                            :id="`pause-description-${choice.value}`"
                                            class="automation-helper"
                                            >{{ choice.description }}</span
                                        ></span
                                    >
                                </label>
                                <div
                                    v-if="
                                        choice.value === 'custom' &&
                                        pauseForm.choice === 'custom'
                                    "
                                    class="automation-custom"
                                >
                                    <div class="automation-date-fields">
                                        <label
                                            >Data<Input
                                                v-model="pauseForm.date"
                                                type="date"
                                                required
                                                aria-describedby="automation-timezone automation-error"
                                        /></label>
                                        <label
                                            >Horário<Input
                                                v-model="pauseForm.time"
                                                type="time"
                                                required
                                                aria-describedby="automation-timezone automation-error"
                                        /></label>
                                    </div>
                                    <p
                                        id="automation-timezone"
                                        class="automation-helper"
                                    >
                                        Fuso: {{ automationTimezone }}
                                    </p>
                                </div>
                            </div>
                            <p class="automation-impact">
                                A execução atual será interrompida. Quando a
                                pausa terminar, a automação poderá iniciar com
                                um novo gatilho.
                            </p>
                            <div class="automation-actions">
                                <AppButton
                                    type="button"
                                    variant="secondary"
                                    @click="cancelPauseForm"
                                    >Cancelar</AppButton
                                >
                                <AppButton
                                    type="submit"
                                    variant="primary"
                                    :loading="automationBusy"
                                    >{{
                                        automationBusy
                                            ? 'Pausando…'
                                            : 'Confirmar pausa'
                                    }}</AppButton
                                >
                            </div>
                        </fieldset>
                    </form>
                    <AppButton
                        v-if="selected.automation?.can_resume"
                        data-automation-action
                        type="button"
                        variant="secondary"
                        class="full"
                        :loading="automationBusy"
                        @click="changeAutomation('resume')"
                        >{{
                            automationBusy ? 'Retomando…' : 'Retomar automação'
                        }}</AppButton
                    >
                    <p
                        id="automation-error"
                        class="automation-error"
                        role="alert"
                        tabindex="-1"
                        :hidden="!automationError"
                    >
                        {{ automationError }}
                    </p>
                </template>
            </section>
            <section class="note-area">
                <ContactSequence
                    v-if="workspace && selected"
                    :key="`${workspace.id}:${selected.contact.id}`"
                    :workspace-id="workspace.id"
                    :contact-id="selected.contact.id"
                />
                <h3>Notas internas</h3>
                <div
                    v-for="note in selected.notes || []"
                    :key="note.id"
                    class="note"
                >
                    <p>{{ note.body }}</p>
                    <small
                        >{{ note.author }} · {{ time(note.created_at) }}</small
                    >
                </div>
                <Textarea
                    v-model="noteDraft"
                    rows="3"
                    placeholder="Adicionar nota interna"
                /><AppButton
                    variant="secondary"
                    class="full"
                    :disabled="!noteDraft.trim()"
                    @click="addNote"
                >
                    Adicionar nota
                </AppButton>
            </section>
        </aside>
        <aside v-else class="profile-pane empty-profile">
            O perfil do contato aparece aqui.
        </aside>
        <div
            v-if="expandedImage"
            class="image-modal"
            @click.self="expandedImage = ''"
            @keydown.esc="expandedImage = ''"
        >
            <button
                class="image-modal-close"
                aria-label="Fechar imagem"
                title="Fechar imagem"
                @click="expandedImage = ''"
            >
                <X :size="20" /></button
            ><img :src="expandedImage" alt="Imagem ampliada" />
        </div>
    </main>
    <Dialog :open="filterOpen" @update:open="filterOpen = $event">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] w-[min(380px,calc(100vw-2rem))] overflow-y-auto p-5"
        >
            <DialogHeader class="text-left">
                <DialogTitle>Filtrar conversas</DialogTitle>
                <DialogDescription>
                    Combine atribuição, atendimento e etiquetas.
                </DialogDescription>
            </DialogHeader>
            <div class="conversation-filter-fields">
                <label class="filter-field">
                    <span>Atribuição</span>
                    <AppSelect
                        v-model="draftAssignment"
                        aria-label="Atribuição"
                    >
                        <option value="all">Todos</option>
                        <option value="mine">Meus chats</option>
                        <option value="unassigned">Não atribuídos</option>
                    </AppSelect>
                </label>
                <label class="filter-checkbox">
                    <input v-model="draftOpenOnly" type="checkbox" />
                    <span>Atendimentos abertos</span>
                </label>
                <fieldset class="filter-tag-fieldset">
                    <legend>Etiquetas</legend>
                    <p v-if="!workspaceTags.length" class="filter-empty">
                        Nenhuma etiqueta cadastrada.
                    </p>
                    <div v-else class="filter-tag-list">
                        <label
                            v-for="tag in workspaceTags"
                            :key="tag.id"
                            class="filter-checkbox"
                        >
                            <input
                                v-model="draftTagIds"
                                type="checkbox"
                                :value="tag.id"
                            />
                            <span
                                class="filter-tag-dot"
                                :style="{ backgroundColor: tag.color }"
                                aria-hidden="true"
                            ></span>
                            <span>{{ tag.name }}</span>
                        </label>
                    </div>
                </fieldset>
            </div>
            <DialogFooter>
                <AppButton variant="secondary" @click="clearFilters">
                    Limpar filtros
                </AppButton>
                <AppButton @click="applyFilters">Aplicar filtros</AppButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
    <AppDialog :dialog="dialog" @resolve="finish" />
</template>

<style scoped>
* {
    box-sizing: border-box;
}
.chat-shell {
    height: 100%;
    min-height: 0;
    min-width: 0;
    display: grid;
    grid-template-columns: 280px minmax(360px, 1fr) 290px;
    background: var(--background);
    color: var(--foreground);
    font-family: var(--font-sans);
    position: relative;
}
.chat-shell.profile-collapsed {
    grid-template-columns: 280px minmax(0, 1fr) 0;
}
.profile-collapsed .profile-pane {
    display: none;
}
.profile-collapse {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--card);
    color: var(--muted-foreground);
    cursor: pointer;
}
.profile-collapse {
    margin-left: auto;
}
.profile-trigger {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: var(--control-height);
    height: var(--control-height);
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--card);
    color: var(--foreground);
    cursor: pointer;
}
.profile-trigger:hover {
    background: var(--secondary);
}
.service-state {
    color: var(--muted-foreground);
}
.composer-hint {
    margin: 8px 12px 0;
    color: var(--muted-foreground);
    font-size: 11px;
}
.service-controls {
    display: grid;
    gap: 9px;
    padding: 14px 0;
    margin-bottom: 18px;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.service-controls h3,
.service-controls p {
    margin: 0;
}
.service-controls h3 {
    font-size: 13px;
}
.service-controls p {
    font-size: 12px;
    color: var(--muted-foreground);
}
.service-controls strong {
    color: var(--foreground);
}
.assignment-picker {
    display: grid;
    gap: 8px;
}
.mobile-workspace-select {
    display: none;
}
.mobile-workspace-label {
    display: none;
}
.user-dot,
.avatar {
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--success-soft);
    color: var(--success);
    font-weight: 700;
    font-size: 12px;
}
.list-pane,
.profile-pane {
    background: var(--card);
    min-width: 0;
    overflow: auto;
    border-right: 1px solid var(--border);
}
.search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px;
    border-bottom: 1px solid var(--border);
}
.search {
    flex: 1;
    min-width: 0;
    height: var(--control-height);
    padding: 0 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--muted-foreground);
}
.search:focus-within {
    border-color: var(--ring);
    outline: 2px solid var(--ring);
    outline-offset: 1px;
}
.search input {
    border: 0;
    outline: 0;
    min-width: 0;
    width: 100%;
    font: inherit;
    font-size: 11px;
}
.filter-trigger {
    position: relative;
    flex: 0 0 var(--control-height);
    display: grid;
    place-items: center;
    width: var(--control-height);
    height: var(--control-height);
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--card);
    color: var(--foreground);
}
.filter-trigger:hover,
.filter-trigger.active {
    background: var(--accent);
    color: var(--accent-foreground);
}
.filter-count {
    position: absolute;
    top: -6px;
    right: -5px;
    display: grid;
    place-items: center;
    min-width: 18px;
    height: 18px;
    padding: 0 3px;
    border-radius: 9px;
    background: var(--primary);
    color: var(--primary-foreground);
    font-size: 10px;
    font-weight: 700;
}
.connection-warning {
    margin: 0 12px 12px;
    padding: 10px 12px;
    border: 1px solid var(--warning);
    border-radius: 8px;
    background: var(--warning-soft);
    color: var(--warning);
    font-size: 12px;
}
.connection-warning p {
    margin: 0 0 4px;
}
.connection-warning a {
    color: inherit;
    font-weight: 600;
    text-decoration: underline;
}
.conversation-filter-fields {
    display: grid;
    gap: 18px;
    min-width: 0;
}
.filter-field {
    display: grid;
    gap: 7px;
    font-size: 13px;
    font-weight: 600;
}
.filter-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 40px;
    font-size: 14px;
    cursor: pointer;
}
.filter-checkbox input {
    flex: none;
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}
.filter-tag-fieldset {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.filter-tag-fieldset legend {
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
}
.filter-tag-list {
    max-height: min(34vh, 220px);
    overflow-y: auto;
}
.filter-tag-dot {
    flex: none;
    width: 9px;
    height: 9px;
    border-radius: 50%;
}
.filter-empty {
    margin: 8px 0 0;
    color: var(--muted-foreground);
    font-size: 12px;
}
.thread-list {
    overflow: auto;
}
.thread {
    width: 100%;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 0;
    border-bottom: 1px solid var(--border);
    background: var(--card);
    text-align: left;
    cursor: pointer;
}
.thread:hover,
.thread.chosen {
    background: var(--accent);
}
.thread-main {
    flex: 1;
    min-width: 0;
    display: grid;
    gap: 8px;
}
.thread-top,
.thread-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.thread-time {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}
.unread-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--primary);
    box-shadow: 0 0 0 1px var(--card);
}
.thread-top b {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 11px;
}
.thread-top small,
.thread-bottom small {
    font-size: 9px;
    color: var(--muted-foreground);
}
.thread-bottom small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.thread-bottom em,
.actions em {
    font-style: normal;
    white-space: nowrap;
    font-size: 9px;
    padding: 4px 6px;
    border-radius: 10px;
    background: var(--secondary);
    color: var(--muted-foreground);
}
.thread-bottom em.humanized,
.actions em.humanized {
    background: var(--warning-soft);
    color: var(--warning);
}
.thread-bottom em.bot-active,
.actions em.bot-active {
    background: var(--success-soft);
    color: var(--success);
}
.thread-bottom em.bot-paused,
.actions em.bot-paused {
    background: var(--secondary);
    color: var(--muted-foreground);
}
.empty {
    text-align: center;
    color: var(--muted-foreground);
    padding: 20px;
    font-size: 11px;
}
.chat-pane {
    min-width: 0;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: var(--background);
}
.chat-heading {
    flex: 0 0 68px;
    height: 68px;
    padding: 0 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
}
.avatar.mini {
    height: 36px;
    width: 36px;
}
.title {
    display: grid;
    gap: 3px;
    min-width: 0;
}
.title b {
    font-size: 12px;
}
.title small {
    font-size: 10px;
    color: var(--muted-foreground);
}
.actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 6px;
}

.compact {
    padding: 7px 9px;
}
.retention {
    margin: 0;
    padding: 8px;
    text-align: center;
    color: var(--muted-foreground);
    font-size: 9px;
}
.messages {
    flex: 1;
    min-height: 0;
    position: relative;
    overflow: auto;
    padding: 15px clamp(12px, 4vw, 55px);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.new-messages-button {
    position: sticky;
    bottom: 10px;
    align-self: center;
    margin: 4px auto 0;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--card);
    color: var(--primary);
    box-shadow: 0 2px 8px #14261b20;
    cursor: pointer;
}
.message-row {
    display: flex;
}
.message-row.mine {
    justify-content: flex-end;
}
.bubble {
    max-width: min(80%, 520px);
    background: var(--card);
    padding: 9px 11px;
    border-radius: 12px;
    box-shadow: 0 1px 2px #12291b10;
    font-size: 12px;
    line-height: 1.5;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.mine .bubble {
    background: var(--accent);
}
.bubble p {
    margin: 0;
}
.bubble small {
    display: block;
    text-align: right;
    color: var(--muted-foreground);
    font-size: 9px;
    margin-top: 4px;
}
.history-warning,
.no-messages {
    margin: auto;
    padding: 15px;
    max-width: 360px;
    text-align: center;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--muted-foreground);
    font-size: 11px;
    line-height: 1.6;
}
.waiting {
    padding: 10px;
    text-align: center;
    background: var(--warning-soft);
    color: var(--warning);
    font-size: 10px;
}
.waiting button {
    border: 0;
    background: none;
    color: var(--success);
    text-decoration: underline;
    cursor: pointer;
    font-weight: bold;
}
.composer {
    margin: 10px 13px 15px;
    padding: 8px;
    display: flex;
    align-items: end;
    gap: 8px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 11px;
}
.composer textarea {
    resize: none;
    flex: 1;
    min-width: 0;
    min-height: var(--control-height);
    max-height: 120px;
    overflow-y: auto;
    padding: 8px 12px;
    border: 1px solid var(--input);
    border-radius: 8px;
    background: var(--background);
    box-shadow: none;
    outline: none;
    font: inherit;
    font-size: 14px;
    line-height: 22px;
}
.composer textarea:focus {
    border-color: var(--ring);
    outline: 2px solid var(--ring);
    outline-offset: 1px;
}
.attach,
.voice-button,
.send {
    position: relative;
    flex: 0 0 var(--control-height);
    width: var(--control-height);
    height: var(--control-height);
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 8px;
    background: var(--accent);
    color: var(--accent-foreground);
    font-size: 20px;
    cursor: pointer;
}
.attach input {
    position: absolute;
    inset: 0;
    width: 100%;
    opacity: 0;
    cursor: pointer;
}
.send {
    background: var(--primary);
    color: var(--primary-foreground);
    font-size: 13px;
}
.locked {
    text-align: center;
    padding: 14px;
    background: var(--card);
    color: var(--muted-foreground);
    border-top: 1px solid var(--border);
    font-size: 10px;
}
.profile-pane {
    border-right: 0;
    border-left: 1px solid var(--border);
    padding: 0 15px 20px;
}
.profile-heading {
    height: 68px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}
.identity {
    padding: 19px 0;
    display: grid;
    justify-items: center;
    gap: 7px;
}
.avatar.big {
    width: 54px;
    height: 54px;
    font-size: 16px;
}
.identity b {
    font-size: 12px;
}
.identity small {
    font-size: 10px;
    color: var(--muted-foreground);
}
.profile-form,
.note-area {
    display: grid;
    gap: var(--form-field-gap);
}
.profile-form label,
.note-area {
    font-size: 10px;
    color: var(--muted-foreground);
}
.profile-form input,
.note-area textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 7px;
    font: inherit;
    font-size: 10px;
    resize: vertical;
}
.profile-form label {
    display: grid;
    gap: var(--form-label-gap);
}
.full {
    width: 100%;
}
.note-area {
    border-top: 1px solid var(--border);
    margin-top: 17px;
    padding-top: 14px;
}
.note-area h3 {
    margin: 0;
    color: var(--foreground);
    font-size: 11px;
}
.profile-data,
.automation-pause {
    margin-top: 15px;
    padding: 12px 0;
    border-top: 1px solid var(--border);
    color: var(--muted-foreground);
    font-size: 10px;
}
.profile-data h3 {
    margin: 0 0 8px;
    color: var(--foreground);
    font-size: 11px;
}
.profile-data p {
    margin: 5px 0;
    line-height: 1.5;
}
.automation-pause {
    font-size: 13px;
    display: grid;
    gap: 12px;
}
.automation-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.automation-pause h3 {
    margin: 0;
    color: var(--foreground);
    font-size: 14px;
}
.automation-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 12px;
    font-weight: 600;
}
.automation-status-allowed {
    background: var(--success-soft);
    color: var(--success);
}
.automation-status-paused {
    background: var(--warning-soft);
    color: var(--warning);
}
.automation-status-unavailable {
    background: var(--muted);
    color: var(--muted-foreground);
}
.automation-pause p {
    margin: 0;
    line-height: 1.5;
}
.automation-summary {
    display: grid;
    gap: 6px;
}
.automation-deadline {
    font-weight: 600;
    color: var(--foreground);
}
.automation-helper {
    display: block;
    font-size: 12px;
    font-weight: 400;
    color: var(--muted-foreground);
    line-height: 1.5;
}
.automation-human {
    border-left: 2px solid var(--warning);
    padding-left: 8px;
}
.automation-form fieldset {
    display: grid;
    gap: 8px;
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}
.automation-form legend {
    margin-bottom: 10px;
    font-weight: 600;
    color: var(--foreground);
}
.automation-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px;
    min-height: 44px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--background);
    color: var(--foreground);
    cursor: pointer;
}
.automation-option-selected {
    border-color: var(--primary);
    background: var(--accent);
}
.automation-option:focus-within {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.automation-option input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin: 3px 0 0;
    accent-color: var(--primary);
}
.automation-option strong {
    font-size: 13px;
    font-weight: 600;
}
.automation-form fieldset:disabled .automation-option {
    opacity: 0.65;
    cursor: wait;
}
.automation-custom {
    margin-top: 10px;
    padding: 0 2px 4px;
}
.automation-date-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 6px;
}
.automation-date-fields label {
    display: grid;
    gap: 6px;
    flex: 1 1 115px;
    min-width: 0;
    color: var(--foreground);
}
.automation-date-fields input {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    box-sizing: border-box;
    padding: 8px;
    font: inherit;
}
.automation-pause .automation-impact {
    margin-top: 4px;
    padding: 10px;
    border-radius: 8px;
    background: var(--muted);
    font-size: 12px;
}
.automation-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}
.automation-actions button {
    flex: 1 1 auto;
    min-height: 44px;
}
.automation-pause > button {
    min-height: 44px;
}
.automation-pause .automation-error {
    color: var(--destructive);
}
@media (pointer: coarse) {
    .automation-date-fields input {
        font-size: 16px;
    }
}
.note {
    padding: 8px;
    border-radius: 7px;
    background: var(--background);
}
.note p {
    margin: 0 0 6px;
    font-size: 10px;
    line-height: 1.45;
    color: var(--foreground);
}
.note small {
    font-size: 9px;
    color: var(--muted-foreground);
}
.empty-profile {
    display: grid;
    place-items: center;
    color: var(--muted-foreground);
    text-align: center;
    font-size: 11px;
}
.empty-chat {
    margin: auto;
    text-align: center;
    color: var(--muted-foreground);
}
.empty-chat h2 {
    font-size: 15px;
    color: var(--muted-foreground);
}
.empty-chat p {
    font-size: 11px;
}
.connect-box {
    margin: auto 14px;
    padding: 20px 15px;
    text-align: center;
    background: var(--background);
    border: 1px solid var(--border);
    border-radius: 13px;
}
.connect-symbol {
    margin: 0 auto 10px;
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--success-soft);
    color: var(--success);
    font-size: 22px;
}
.connect-box h2 {
    font-size: 14px;
    margin: 0 0 6px;
}
.connect-box p,
.connect-box small {
    font-size: 10px;
    line-height: 1.5;
    color: var(--muted-foreground);
}
.connect-box img {
    display: block;
    width: 180px;
    height: 180px;
    object-fit: contain;
    padding: 7px;
    margin: 10px auto;
    background: var(--card);
    border-radius: 8px;
}
.back {
    display: none;
}
.avatar {
    position: relative;
    overflow: hidden;
}
.avatar img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
    background: var(--success-soft);
}
.profile-pane {
    height: 100%;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
}
.profile-form,
.note-area {
    min-width: 0;
}
.profile-form input,
.note-area textarea {
    min-width: 0;
    max-width: 100%;
}
.settings-link {
    display: inline-block;
    text-decoration: none;
    margin-top: 10px;
}
.image-preview {
    display: block;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: zoom-in;
    max-width: min(100%, 360px);
}
.image-preview img {
    display: block;
    max-width: 100%;
    max-height: 360px;
    object-fit: contain;
    border-radius: 8px;
}
.image-modal {
    position: fixed;
    z-index: 30;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 35px;
    background: #07150edc;
    cursor: zoom-out;
}
.image-modal img {
    max-width: min(100%, 1200px);
    max-height: 100%;
    object-fit: contain;
}
.image-modal-close {
    position: absolute;
    right: 18px;
    top: 12px;
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    color: var(--primary-foreground);
    font-size: 28px;
    cursor: pointer;
}
.voice-composer {
    margin: 10px 13px 0;
    padding: 12px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 11px;
    font-size: 12px;
}
.voice-composer audio,
.audio-message audio {
    display: block;
    width: min(100%, 280px);
    max-width: 100%;
}
.voice-composer audio {
    flex: 1 1 200px;
}
.audio-message {
    width: min(280px, 60vw);
    white-space: normal;
    display: grid;
    gap: 6px;
}
.audio-message span {
    font-size: 11px;
}
.recording-indicator,
.voice-error {
    color: var(--danger);
}
.voice-error {
    margin: 8px 13px;
    font-size: 12px;
}
.composer button:disabled,
.attach:has(input:disabled) {
    opacity: 0.5;
    cursor: default;
}
@media (max-width: 1023px) {
    .chat-shell {
        height: 100%;
        min-height: 0;
        display: block;
        position: relative;
    }
    .list-pane,
    .chat-pane {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        border: 0;
    }
    .mobile-workspace-select {
        display: block;
        max-width: min(60vw, 250px);
        padding: 3px 4px;
        border: 0;
        background: transparent;
        color: var(--muted-foreground);
        font-size: 10px;
        text-transform: uppercase;
    }
    .list-pane.mobile-hidden,
    .chat-pane.mobile-hidden {
        display: none;
    }
    .profile-pane {
        display: none;
    }
    .profile-pane.profile-mobile-open {
        position: absolute;
        z-index: 4;
        inset: 0;
        width: 100%;
        height: 100%;
        display: block;
        border: 0;
    }
    .search-row {
        padding-top: max(12px, env(safe-area-inset-top));
    }
    .chat-heading {
        flex-basis: auto;
        min-height: 62px;
        height: auto;
        padding: 0 9px;
    }
    .back {
        display: grid;
        place-items: center;
        width: 25px;
        border: 0;
        background: none;
        color: var(--brand-strong);
        font-size: 24px;
    }
    .actions em {
        display: none;
    }
    .actions .outline,
    .actions .title {
        max-width: 32vw;
    }
    .actions {
        margin-left: 0;
    }
    .messages {
        padding: 12px;
    }
    .bubble {
        max-width: 87%;
    }
    .composer {
        margin: 8px 8px max(8px, env(safe-area-inset-bottom));
    }
    .profile-heading {
        gap: 8px;
    }
    .profile-heading .back {
        display: grid;
    }
}
</style>

<style scoped>
.list-pane,
.chat-pane,
.profile-pane {
    min-width: 0;
    min-height: 0;
}
@container (max-width: 929px) {
    .chat-shell {
        grid-template-columns: 280px minmax(0, 1fr);
    }
    .chat-shell:not(.profile-collapsed) .profile-pane {
        position: absolute;
        top: 0;
        right: 0;
        z-index: 10;
        width: 290px;
        height: 100%;
        box-shadow: -16px 0 40px #0002;
    }
}
@media (max-width: 1023px) {
    .chat-shell,
    .chat-shell.profile-collapsed {
        display: block;
        height: 100%;
    }
    .chat-shell .profile-pane.profile-mobile-open {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        display: block;
        z-index: 10;
    }
    .chat-shell .profile-pane:not(.profile-mobile-open) {
        display: none;
    }
    .profile-heading .back {
        display: flex;
        align-items: center;
    }
    .profile-collapse {
        display: none;
    }
}
</style>

<style scoped>
.back,
.voice-button,
.send-button,
.profile-collapse {
    min-width: var(--control-height);
    min-height: var(--control-height);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
}
.attach {
    min-width: var(--control-height);
    min-height: var(--control-height);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
}
.title {
    flex: 1;
}
.title b {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
}
.title small {
    font-size: 12px;
}
.chat-heading {
    height: auto;
    min-height: 68px;
    flex-basis: auto;
    flex-wrap: wrap;
    padding: 10px 14px;
}
.actions {
    flex-wrap: wrap;
}
.search {
    height: var(--control-height);
}
.search input {
    font-size: 13px;
}
.retention {
    font-size: 12px;
}
.profile-form label {
    font-size: 13px;
}
.profile-form input {
    min-height: var(--control-height);
    font-size: 14px;
}
.profile-form .full {
    margin-top: calc(var(--form-actions-space) - var(--form-field-gap));
    width: 100%;
}
.note-area textarea {
    font-size: 14px;
}
@media (max-width: 1023px) {
    .chat-heading {
        padding: 8px 10px;
        flex-wrap: nowrap;
    }
    .chat-heading .actions {
        flex: 0 0 auto;
        width: auto;
    }
    .title {
        max-width: none;
    }
    .title small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .chat-heading .actions :deep(button) {
        font-size: 13px;
        min-height: 44px;
    }
    .back {
        display: inline-flex;
    }
    .profile-heading .back {
        display: inline-flex;
    }
    .attach,
    .voice-button,
    .send {
        min-width: 44px;
        min-height: 44px;
    }
    .composer {
        gap: 4px;
    }
    .composer textarea {
        min-width: 0;
        font-size: 16px;
    }
}
@media (min-width: 1024px) {
    .chat-heading .back {
        display: none;
    }
}
</style>

<style scoped>
.message-preview {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}
</style>

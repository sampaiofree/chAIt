export type ChatMessageIdentity = {
    id: string;
    track_id?: string | null;
    whatsapp_id?: string | null;
    status?: string;
    timestamp?: string | null;
};

const ranks: Record<string, number> = {
    queued: 0,
    pending: 0,
    sent: 1,
    delivered: 2,
    read: 3,
    played: 4,
};

export function nextMessageStatus(
    previous: string | undefined,
    incoming: string | undefined,
): string | undefined {
    if (['failed', 'canceled', 'cancelled'].includes(incoming || '')) {
        return ['delivered', 'read', 'played'].includes(previous || '')
            ? previous
            : incoming;
    }

    if (
        ['failed', 'canceled', 'cancelled'].includes(previous || '') &&
        !['delivered', 'read', 'played'].includes(incoming || '')
    ) {
        return previous;
    }

    if (!incoming) {
        return previous;
    }

    if ((ranks[incoming] ?? -1) < (ranks[previous || ''] ?? -1)) {
        return previous;
    }

    return incoming;
}

export function sameChatMessage(
    a: ChatMessageIdentity,
    b: ChatMessageIdentity,
): boolean {
    return (
        a.id === b.id ||
        (!!a.track_id && a.track_id === b.track_id) ||
        (!!a.whatsapp_id && a.whatsapp_id === b.whatsapp_id)
    );
}

/** Reconciles snapshots and concurrent live events without duplicating an optimistic send. */
export function mergeChatMessages<T extends ChatMessageIdentity>(
    existing: T[],
    incoming: T[],
): T[] {
    const result = [...existing];

    for (const message of incoming) {
        const index = result.findIndex((item) =>
            sameChatMessage(item, message),
        );

        if (index === -1) {
            result.push(message);
        } else {
            result[index] = {
                ...result[index],
                ...message,
                status: nextMessageStatus(result[index].status, message.status),
            };
        }
    }

    return result.sort(
        (a, b) =>
            (Date.parse(a.timestamp || '') || 0) -
            (Date.parse(b.timestamp || '') || 0),
    );
}

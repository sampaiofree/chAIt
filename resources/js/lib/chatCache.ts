const lifetime = 7 * 24 * 60 * 60 * 1000;
type Entry = { key: string; time: number; value: any };
const memory = new Map<string, Entry>();
let database: Promise<IDBDatabase | null> | undefined;
function db() {
    if (!database) {
        database = new Promise((resolve) => {
            try {
                const request = indexedDB.open('chait-chat-cache', 1);
                request.onupgradeneeded = () =>
                    request.result.createObjectStore('entries', {
                        keyPath: 'key',
                    });
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => resolve(null);
                request.onblocked = () => resolve(null);
            } catch {
                resolve(null);
            }
        });
    }

    return database;
}
export async function cacheGet<T>(key: string): Promise<T | null> {
    let entry = memory.get(key);

    if (!entry) {
        const storage = await db();

        if (storage) {
            entry = await new Promise<Entry | undefined>((resolve) => {
                const request = storage
                    .transaction('entries')
                    .objectStore('entries')
                    .get(key);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => resolve(undefined);
            });
        }
    }

    if (!entry || Date.now() - entry.time > lifetime) {
        return null;
    }

    memory.set(key, entry);

    return entry.value as T;
}
export async function cachePut(key: string, value: unknown) {
    const entry = {
        key,
        time: Date.now(),
        value: JSON.parse(JSON.stringify(value)),
    };
    memory.set(key, entry);
    const storage = await db();

    if (!storage) {
        return;
    }

    try {
        const tx = storage.transaction('entries', 'readwrite');
        const store = tx.objectStore('entries');
        store.put(entry);
        const request = store.getAll();
        request.onsuccess = () => {
            const histories = (request.result as Entry[])
                .filter((item) => item.key.includes(':history:'))
                .sort((a, b) => b.time - a.time);

            for (const item of histories.slice(50)) {
                store.delete(item.key);
            }
        };
    } catch {
        /* Storage is optional. */
    }
}
export async function clearChatCache() {
    memory.clear();
    const storage = await db();

    if (!storage) {
        return;
    }

    await new Promise<void>((resolve) => {
        try {
            const tx = storage.transaction('entries', 'readwrite');
            tx.objectStore('entries').clear();
            tx.oncomplete = () => resolve();
            tx.onerror = () => resolve();
            tx.onabort = () => resolve();
        } catch {
            resolve();
        }
    });
}

/**
 * Dexie-based local cache (IndexedDB).
 * Mirrors the server schema and adds a `dirty` flag for offline-first writes.
 */
import Dexie from 'dexie';

const db = new Dexie('reminder-note');

db.version(1).stores({
    tasks: 'id, status, due_at, updated_at, deleted_at, dirty',
    notes: 'id, pinned, favorite, updated_at, deleted_at, dirty',
    meta:  'key',
});

function uuid() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
}

function now() { return Date.now(); }

/**
 * Strip framework-specific reactive wrappers (e.g. Alpine.js Proxy) so the
 * value can be passed to IndexedDB's structured clone algorithm.
 */
function toPlain(value) {
    if (value === null || value === undefined) return value;
    return JSON.parse(JSON.stringify(value));
}

const Tasks = {
    async list({ includeDeleted = false, status, search } = {}) {
        let coll = db.tasks.orderBy('updated_at').reverse();
        const items = await coll.toArray();
        return items.filter(t => {
            if (!includeDeleted && t.deleted_at) return false;
            if (status && t.status !== status) return false;
            if (search) {
                const s = search.toLowerCase();
                if (!(t.title || '').toLowerCase().includes(s) && !(t.notes || '').toLowerCase().includes(s)) {
                    return false;
                }
            }
            return true;
        });
    },
    async get(id) { return db.tasks.get(id); },
    async upsert(task, markDirty = true) {
        const existing = task.id ? await db.tasks.get(task.id) : null;
        const merged = {
            id: task.id || uuid(),
            title: task.title ?? '',
            notes: task.notes ?? '',
            status: task.status ?? 'todo',
            priority: task.priority ?? 1,
            due_at: task.due_at ?? null,
            remind_at: task.remind_at ?? null,
            repeat_rule: task.repeat_rule ?? null,
            tags: toPlain(task.tags) ?? [],
            subtasks: toPlain(task.subtasks) ?? [],
            created_at: existing?.created_at || task.created_at || now(),
            updated_at: task.updated_at || now(),
            completed_at: task.completed_at ?? (task.status === 'done' ? now() : null),
            deleted_at: task.deleted_at ?? null,
            dirty: markDirty ? 1 : 0,
        };
        await db.tasks.put(merged);
        return merged;
    },
    async toggle(id) {
        const t = await db.tasks.get(id);
        if (!t) return null;
        const newStatus = t.status === 'done' ? 'todo' : 'done';
        return Tasks.upsert({ ...t, status: newStatus, completed_at: newStatus === 'done' ? now() : null, updated_at: now() });
    },
    async softDelete(id) {
        const t = await db.tasks.get(id);
        if (!t) return null;
        return Tasks.upsert({ ...t, deleted_at: now(), updated_at: now() });
    },
    async restore(id) {
        const t = await db.tasks.get(id);
        if (!t) return null;
        return Tasks.upsert({ ...t, deleted_at: null, updated_at: now() });
    },
    /** Permanently remove a record from the local cache (does not affect server). */
    async hardDelete(id) {
        await db.tasks.delete(id);
    },
    async listDeleted() {
        const items = await db.tasks.toArray();
        return items.filter(t => t.deleted_at).sort((a, b) => (b.deleted_at || 0) - (a.deleted_at || 0));
    },
    async dirty() { return db.tasks.where('dirty').equals(1).toArray(); },
    /**
     * Clear dirty flag, but only for records whose updated_at still matches
     * the version that was successfully pushed. This prevents a concurrent
     * local edit (made while the push was in flight) from being marked clean
     * and then clobbered by replaceFromServer.
     *
     * @param {Array<{id: string, updated_at: number}>} pushed
     */
    async clearDirty(pushed) {
        if (!pushed?.length) return;
        await db.transaction('rw', db.tasks, async () => {
            for (const p of pushed) {
                const local = await db.tasks.get(p.id);
                if (local && local.updated_at === p.updated_at) {
                    await db.tasks.update(p.id, { dirty: 0 });
                }
            }
        });
    },
    async replaceFromServer(serverItems) {
        if (!serverItems?.length) return;
        await db.transaction('rw', db.tasks, async () => {
            for (const t of serverItems) {
                const local = await db.tasks.get(t.id);
                // Never clobber a record that still has unpushed local edits.
                if (local && local.dirty) continue;
                await db.tasks.put({ ...t, dirty: 0 });
            }
        });
    },
};

const Notes = {
    async list({ pinned, favorite, search } = {}) {
        const items = (await db.notes.toArray())
            .filter(n => !n.deleted_at)
            .filter(n => pinned ? n.pinned : true)
            .filter(n => favorite ? n.favorite : true)
            .filter(n => {
                if (!search) return true;
                const s = search.toLowerCase();
                return (n.title || '').toLowerCase().includes(s) || (n.content || '').toLowerCase().includes(s);
            })
            .sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || b.updated_at - a.updated_at);
        return items;
    },
    async get(id) { return db.notes.get(id); },
    async upsert(note, markDirty = true) {
        const existing = note.id ? await db.notes.get(note.id) : null;
        const merged = {
            id: note.id || uuid(),
            title: note.title ?? '',
            content: note.content ?? '',
            tags: toPlain(note.tags) ?? [],
            pinned: note.pinned ? 1 : 0,
            favorite: note.favorite ? 1 : 0,
            created_at: existing?.created_at || note.created_at || now(),
            updated_at: note.updated_at || now(),
            deleted_at: note.deleted_at ?? null,
            dirty: markDirty ? 1 : 0,
        };
        await db.notes.put(merged);
        return merged;
    },
    async softDelete(id) {
        const n = await db.notes.get(id);
        if (!n) return null;
        return Notes.upsert({ ...n, deleted_at: now(), updated_at: now() });
    },
    async restore(id) {
        const n = await db.notes.get(id);
        if (!n) return null;
        return Notes.upsert({ ...n, deleted_at: null, updated_at: now() });
    },
    async hardDelete(id) {
        await db.notes.delete(id);
    },
    async listDeleted() {
        const items = await db.notes.toArray();
        return items.filter(n => n.deleted_at).sort((a, b) => (b.deleted_at || 0) - (a.deleted_at || 0));
    },
    async dirty() { return db.notes.where('dirty').equals(1).toArray(); },
    /** @param {Array<{id: string, updated_at: number}>} pushed */
    async clearDirty(pushed) {
        if (!pushed?.length) return;
        await db.transaction('rw', db.notes, async () => {
            for (const p of pushed) {
                const local = await db.notes.get(p.id);
                if (local && local.updated_at === p.updated_at) {
                    await db.notes.update(p.id, { dirty: 0 });
                }
            }
        });
    },
    async replaceFromServer(serverItems) {
        if (!serverItems?.length) return;
        await db.transaction('rw', db.notes, async () => {
            for (const n of serverItems) {
                const local = await db.notes.get(n.id);
                // Never clobber a record that still has unpushed local edits.
                if (local && local.dirty) continue;
                await db.notes.put({ ...n, pinned: n.pinned ? 1 : 0, favorite: n.favorite ? 1 : 0, dirty: 0 });
            }
        });
    },
};

const Meta = {
    async get(key) { return (await db.meta.get(key))?.value; },
    async set(key, value) { await db.meta.put({ key, value }); },
};

/**
 * Hard-reset the local cache. Used on login/logout so a previous user's
 * tasks/notes/lastSyncAt do not leak into the next account on this device.
 */
async function wipeAll() {
    try {
        await db.transaction('rw', db.tasks, db.notes, db.meta, async () => {
            await db.tasks.clear();
            await db.notes.clear();
            await db.meta.clear();
        });
    } catch (e) {
        console.warn('[db-local] wipeAll failed, falling back to deleteDatabase', e);
        try { await db.delete(); await db.open(); } catch { /* ignore */ }
    }
}

export { db, Tasks, Notes, Meta, uuid, now, wipeAll };

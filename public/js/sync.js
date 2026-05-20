/**
 * Bidirectional incremental sync between server and local Dexie cache.
 * Push first (so server sees latest), then pull, and finally clean dirty flags.
 */
import { api, ApiError } from './api.js';
import { Tasks, Notes, Meta } from './db-local.js';

const META_KEY = 'lastSyncAt';

export async function pushDirty() {
    const [tasks, notes] = await Promise.all([Tasks.dirty(), Notes.dirty()]);
    if (!tasks.length && !notes.length) return { pushed: 0 };
    const payload = {
        tasks: tasks.map(serializeTask),
        notes: notes.map(serializeNote),
    };
    const res = await api.syncPush(payload);
    // Only clear dirty for records the server actually accepted. Anything in
    // res.rejected (id_conflict / missing_title / invalid_id ...) keeps its
    // dirty=1 so the next syncNow re-tries — otherwise a rejected record
    // would silently turn into "looks synced but isn't" ghost data.
    //
    // Use the locally-pushed updated_at (not the server-confirmed one) so a
    // local edit made while the push was in flight keeps dirty=1 and gets
    // re-pushed on the next sync.
    const rejected = res.rejected || [];
    const rejectedTaskIds = new Set(rejected.filter(r => r.type === 'task').map(r => r.id));
    const rejectedNoteIds = new Set(rejected.filter(r => r.type === 'note').map(r => r.id));
    const pushedTasks = tasks
        .filter(t => !rejectedTaskIds.has(t.id))
        .map(t => ({ id: t.id, updated_at: t.updated_at }));
    const pushedNotes = notes
        .filter(n => !rejectedNoteIds.has(n.id))
        .map(n => ({ id: n.id, updated_at: n.updated_at }));
    if (pushedTasks.length) await Tasks.clearDirty(pushedTasks);
    if (pushedNotes.length) await Notes.clearDirty(pushedNotes);
    if (res.appliedTasks?.length) await Tasks.replaceFromServer(res.appliedTasks);
    if (res.appliedNotes?.length) await Notes.replaceFromServer(res.appliedNotes);
    return { pushed: tasks.length + notes.length, rejected };
}

export async function pullSince() {
    const since = (await Meta.get(META_KEY)) || 0;
    const res = await api.syncPull(since);
    if (res.tasks?.length) await Tasks.replaceFromServer(res.tasks);
    if (res.notes?.length) await Notes.replaceFromServer(res.notes);
    await Meta.set(META_KEY, res.serverTime);
    return { pulled: (res.tasks?.length || 0) + (res.notes?.length || 0) };
}

export async function syncNow({ silent = false } = {}) {
    if (!navigator.onLine) return { online: false };
    try {
        const pushed = await pushDirty();
        const pulled = await pullSince();
        return { online: true, ...pushed, ...pulled };
    } catch (e) {
        if (!silent) console.warn('[sync] failed', e);
        if (e instanceof ApiError && e.status === 401) {
            return { online: true, error: 'unauthorized' };
        }
        return { online: true, error: e.message || String(e) };
    }
}

function serializeTask(t) {
    return {
        id: t.id, title: t.title, notes: t.notes,
        status: t.status, priority: t.priority,
        due_at: t.due_at, remind_at: t.remind_at, repeat_rule: t.repeat_rule,
        tags: t.tags, subtasks: t.subtasks,
        created_at: t.created_at, updated_at: t.updated_at,
        completed_at: t.completed_at, deleted_at: t.deleted_at,
    };
}
function serializeNote(n) {
    return {
        id: n.id, title: n.title, content: n.content,
        tags: n.tags, pinned: !!n.pinned, favorite: !!n.favorite,
        created_at: n.created_at, updated_at: n.updated_at,
        deleted_at: n.deleted_at,
    };
}

let interval = null;
export function startAutoSync(periodMs = 5 * 60_000, listener = null) {
    stopAutoSync();
    const tick = async () => {
        const result = await syncNow({ silent: true });
        if (listener) listener(result);
    };
    interval = setInterval(tick, periodMs);
    window.addEventListener('online', tick);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) tick();
    });
    setTimeout(tick, 800);
}
export function stopAutoSync() {
    if (interval) { clearInterval(interval); interval = null; }
}

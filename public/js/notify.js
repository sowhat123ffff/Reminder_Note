/**
 * Browser notifications + reminder scheduler.
 *
 * Fired-reminder dedup is persisted in localStorage so a refresh/restart does
 * not re-fire reminders the user already saw. Old keys are pruned to cap size.
 */
import { Tasks } from './db-local.js';
import { assetUrl } from './urls.js';

const FIRED_KEY = 'rn:fired-reminders';
const FIRED_TTL_MS = 7 * 24 * 60 * 60_000;

export async function ensurePermission() {
    if (!('Notification' in window)) return false;
    if (Notification.permission === 'granted') return true;
    if (Notification.permission === 'denied') return false;
    const r = await Notification.requestPermission();
    return r === 'granted';
}

export function fire(title, options = {}) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return null;
    try {
        return new Notification(title, {
            silent: false,
            badge: assetUrl('assets/icons/badge.svg'),
            icon: assetUrl('assets/icons/icon.svg'),
            ...options,
        });
    } catch {
        return null;
    }
}

function loadFired() {
    try {
        const raw = localStorage.getItem(FIRED_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return typeof parsed === 'object' && parsed ? parsed : {};
    } catch {
        return {};
    }
}

function saveFired(map) {
    try {
        const cutoff = Date.now() - FIRED_TTL_MS;
        const pruned = {};
        for (const [k, v] of Object.entries(map)) {
            if (typeof v === 'number' && v >= cutoff) pruned[k] = v;
        }
        localStorage.setItem(FIRED_KEY, JSON.stringify(pruned));
    } catch {
        // localStorage full or disabled — ignore.
    }
}

let timer = null;

export function startReminderLoop() {
    stopReminderLoop();
    const tick = async () => {
        const items = await Tasks.list({ includeDeleted: false });
        const now = Date.now();
        const fired = loadFired();
        let dirty = false;
        for (const t of items) {
            if (t.status === 'done' || t.status === 'archived') continue;
            const target = t.remind_at || t.due_at;
            if (!target) continue;
            const key = t.id + ':' + target;
            if (target <= now && target >= now - 5 * 60_000 && !fired[key]) {
                fire(t.title || '提醒', { body: t.notes ? t.notes.slice(0, 100) : '到时间啦', tag: t.id });
                fired[key] = now;
                dirty = true;
            }
        }
        if (dirty) saveFired(fired);
    };
    timer = setInterval(tick, 30_000);
    setTimeout(tick, 1500);
}
export function stopReminderLoop() { if (timer) { clearInterval(timer); timer = null; } }

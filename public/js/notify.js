/**
 * Browser notifications + reminder scheduler.
 */
import { Tasks } from './db-local.js';
import { assetUrl } from './urls.js';

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

let timer = null;
const FIRED = new Set();

export function startReminderLoop() {
    stopReminderLoop();
    const tick = async () => {
        const items = await Tasks.list({ includeDeleted: false });
        const now = Date.now();
        for (const t of items) {
            if (t.status === 'done' || t.status === 'archived') continue;
            const target = t.remind_at || t.due_at;
            if (!target) continue;
            if (target <= now && target >= now - 5 * 60_000 && !FIRED.has(t.id + ':' + target)) {
                fire(t.title || '提醒', { body: t.notes ? t.notes.slice(0, 100) : '到时间啦', tag: t.id });
                FIRED.add(t.id + ':' + target);
            }
        }
    };
    timer = setInterval(tick, 30_000);
    setTimeout(tick, 1500);
}
export function stopReminderLoop() { if (timer) { clearInterval(timer); timer = null; } }

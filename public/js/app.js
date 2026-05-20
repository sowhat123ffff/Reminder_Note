import Alpine from 'alpinejs';
import { createIcons, icons } from 'lucide';
import confetti from 'canvas-confetti';
import zhCn from '@fullcalendar/core/locales/zh-cn.js';
import { Calendar } from '@fullcalendar/core';
import dayGrid from '@fullcalendar/daygrid';
import timeGrid from '@fullcalendar/timegrid';
import interaction from '@fullcalendar/interaction';

import { api, isAuthenticated, clearTokens } from './api.js';
import { Tasks, Notes, uuid as newId } from './db-local.js';
import { startAutoSync, syncNow } from './sync.js';
import { parse as parseNL } from './parser.js';
import { renderMarkdown, stripMarkdown } from './markdown.js';
import { applyTheme, getTheme, cycleTheme } from './theme.js';
import { ensurePermission as ensureNotifyPermission, startReminderLoop } from './notify.js';

if (!isAuthenticated()) {
    location.replace('./login.html');
}

window.Alpine = Alpine;

const VIEWS = ['today', 'calendar', 'kanban', 'notes', 'stats', 'trash', 'account'];
const PRIORITY_LABEL = ['低', '中', '高', '紧急'];
const PRIORITY_COLOR = ['ink-400', 'brand-500', 'amber-500', 'accent-500'];

const PIN_STORAGE = 'rn:app_pin';

async function digestPin(pin) {
    const enc = new TextEncoder().encode('rn-pin-v1|' + pin);
    const buf = await crypto.subtle.digest('SHA-256', enc);
    return [...new Uint8Array(buf)].map(b => b.toString(16).padStart(2, '0')).join('');
}

function startOfDay(t = Date.now()) { const d = new Date(t); d.setHours(0, 0, 0, 0); return d.getTime(); }
function endOfDay(t = Date.now()) { const d = new Date(t); d.setHours(23, 59, 59, 999); return d.getTime(); }
function fmtTime(ts) {
    if (!ts) return '';
    const d = new Date(ts);
    const today = startOfDay();
    const tomorrow = today + 86400_000;
    const time = d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false });
    const day = d.getTime();
    if (day >= today && day < tomorrow) return '今天 ' + time;
    if (day >= tomorrow && day < tomorrow + 86400_000) return '明天 ' + time;
    return d.toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' }) + ' ' + time;
}

function relativeRemaining(ts) {
    if (!ts) return '';
    const diff = ts - Date.now();
    if (diff < 0) return '已逾期';
    const m = Math.floor(diff / 60000);
    if (m < 60) return `${m} 分钟后`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h} 小时后`;
    const d = Math.floor(h / 24);
    return `${d} 天后`;
}

function dueClass(t) {
    if (!t.due_at || t.status === 'done') return '';
    const diff = t.due_at - Date.now();
    if (diff < 0) return 'text-accent-500';
    if (diff < 60 * 60_000) return 'text-amber-500';
    return 'text-ink-500 dark:text-ink-400';
}

function formatDateTime(ts) {
    if (!ts) return '—';
    return new Date(ts * (ts < 1e12 ? 1000 : 1)).toLocaleString('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false,
    });
}

function formatRelative(ts) {
    if (!ts) return '从未';
    const ms = ts * (ts < 1e12 ? 1000 : 1);
    const diff = Date.now() - ms;
    if (diff < 60_000) return '刚刚';
    if (diff < 3600_000) return Math.floor(diff / 60_000) + ' 分钟前';
    if (diff < 86400_000) return Math.floor(diff / 3600_000) + ' 小时前';
    if (diff < 30 * 86400_000) return Math.floor(diff / 86400_000) + ' 天前';
    return formatDateTime(ts);
}

/**
 * Tiny User-Agent classifier — good enough for the sessions list.
 * We avoid pulling a parser dependency for this one cosmetic feature.
 */
function parseUA(ua) {
    if (!ua) return { label: '未知设备', icon: 'monitor' };
    const u = String(ua);
    let os = 'Unknown OS';
    if (/Windows NT 10\.0/.test(u)) os = 'Windows';
    else if (/Windows/.test(u)) os = 'Windows';
    else if (/Android/.test(u)) os = 'Android';
    else if (/iPhone|iPad|iPod/.test(u)) os = 'iOS';
    else if (/Mac OS X/.test(u)) os = 'macOS';
    else if (/Linux/.test(u)) os = 'Linux';

    let browser = 'Unknown';
    if (/Edg\//.test(u)) browser = 'Edge';
    else if (/OPR\//.test(u)) browser = 'Opera';
    else if (/Firefox\//.test(u)) browser = 'Firefox';
    else if (/Chrome\//.test(u)) browser = 'Chrome';
    else if (/Safari\//.test(u)) browser = 'Safari';
    else if (/curl\//i.test(u)) browser = 'curl';
    else if (/PHP/.test(u)) browser = 'PHP';

    let icon = 'monitor';
    if (os === 'Android' || os === 'iOS') icon = 'smartphone';
    else if (os === 'macOS' || os === 'iOS') icon = 'apple';

    return { label: `${browser} · ${os}`, icon };
}

function dispatchSyncNow() {
    window.dispatchEvent(new CustomEvent('rn:sync-now'));
}

/**
 * Compute next due timestamp for a recurring task.
 * Supports rule shapes: 'daily' | 'weekdays' | 'weekly' (string or {type}).
 * Falls back to null if no rule, no due_at, or unknown type.
 */
function nextOccurrence(task) {
    if (!task?.repeat_rule || !task.due_at) return null;
    let type = '';
    try {
        const v = typeof task.repeat_rule === 'string' ? JSON.parse(task.repeat_rule) : task.repeat_rule;
        type = (v && typeof v === 'object') ? String(v.type || '') : String(v || '');
    } catch {
        type = typeof task.repeat_rule === 'string' ? task.repeat_rule : '';
    }
    if (!type) return null;
    const base = new Date(task.due_at);
    if (type === 'daily') {
        base.setDate(base.getDate() + 1);
        return base.getTime();
    }
    if (type === 'weekly') {
        base.setDate(base.getDate() + 7);
        return base.getTime();
    }
    if (type === 'weekdays') {
        do { base.setDate(base.getDate() + 1); } while (base.getDay() === 0 || base.getDay() === 6);
        return base.getTime();
    }
    return null;
}

Alpine.data('appShell', () => ({
    view: 'today',
    sidebarOpen: false,
    online: navigator.onLine,
    syncing: false,
    syncMessage: '',
    syncError: false,
    justBackOnline: false,
    pullDistance: 0,
    pullActive: false,
    _pullStartY: null,
    showCommand: false,
    showQuickAdd: false,
    quickAddText: '',
    quickAddPriority: 1,
    showTaskDetail: null,
    showNoteDetail: null,
    pinLocked: false,
    pinInput: '',
    pinModal: { open: false, mode: 'set', input1: '', input2: '', error: '' },
    confirmDialog: { open: false, title: '', message: '', confirmLabel: '', cancelLabel: '', danger: false, _resolve: null },
    today: { all: [], overdue: [], todoNow: [], later: [], done: [] },
    notes: [],
    allTasks: [],
    search: '',
    theme: getTheme(),
    me: null,

    filterTasks(items) {
        const s = (this.search || '').trim().toLowerCase();
        if (!s || !items?.length) return items || [];
        return items.filter((t) => {
            const tags = Array.isArray(t.tags) ? t.tags.join(' ') : '';
            return (t.title || '').toLowerCase().includes(s)
                || (t.notes || '').toLowerCase().includes(s)
                || tags.toLowerCase().includes(s);
        });
    },

    filterNotes(items) {
        const s = (this.search || '').trim().toLowerCase();
        if (!s || !items?.length) return items || [];
        return items.filter((n) =>
            (n.title || '').toLowerCase().includes(s)
                || (n.content || '').toLowerCase().includes(s)
                || (Array.isArray(n.tags) ? n.tags.join(' ') : '').toLowerCase().includes(s),
        );
    },

    async init() {
        applyTheme();
        const stored = localStorage.getItem(PIN_STORAGE);
        this.pinLocked = Boolean(stored);
        this.pinInput = '';

        this.bindGlobalShortcuts();
        window.addEventListener('rn:cycle-theme', () => { this.theme = cycleTheme(); });
        window.addEventListener('rn:sync-now', () => { this.runSync(); });
        ensureNotifyPermission().catch(() => {});

        const initialView = (location.hash || '#today').slice(1);
        if (VIEWS.includes(initialView)) this.view = initialView;
        window.addEventListener('hashchange', () => {
            const v = (location.hash || '#today').slice(1);
            if (VIEWS.includes(v)) {
                this.view = v;
                if (v === 'calendar') {
                    this.$nextTick(() => window.dispatchEvent(new CustomEvent('rn:resize-calendar')));
                }
            }
        });

        window.addEventListener('online', () => {
            this.online = true;
            this.justBackOnline = true;
            setTimeout(() => { this.justBackOnline = false; }, 3500);
            this.runSync();
        });
        window.addEventListener('offline', () => {
            this.online = false;
            this.justBackOnline = false;
        });

        // Best-effort: fetch current user so the sidebar shows username and
        // the account view can mark "current session". Token is already valid
        // here (isAuthenticated check above passed), so a 401 means it expired
        // between the check and now — let the auto-refresh in api.js handle it.
        api.me().then(r => { this.me = r?.user || null; }).catch(() => {});

        await this.refreshAll();
        startAutoSync(5 * 60_000, async (result) => {
            if (result?.error === 'unauthorized') {
                clearTokens();
                location.replace('./login.html');
                return;
            }
            await this.refreshAll();
        });
        startReminderLoop();

        this.$nextTick(() => createIcons({ icons }));
    },

    setView(v) {
        this.view = v;
        location.hash = '#' + v;
        this.sidebarOpen = false;
        this.$nextTick(() => {
            createIcons({ icons });
            if (v === 'calendar') {
                window.dispatchEvent(new CustomEvent('rn:resize-calendar'));
            }
        });
    },

    async refreshAll() {
        const all = await Tasks.list({ includeDeleted: false });
        this.allTasks = all;
        const todayStart = startOfDay();
        const todayEnd = endOfDay();
        const groups = { all, overdue: [], todoNow: [], later: [], done: [] };
        for (const t of all) {
            if (t.status === 'done') {
                if (t.completed_at && t.completed_at >= todayStart) groups.done.push(t);
                continue;
            }
            if (t.status === 'archived') continue;
            if (t.due_at && t.due_at < todayStart) groups.overdue.push(t);
            else if (t.due_at && t.due_at <= todayEnd) groups.todoNow.push(t);
            else if (!t.due_at) groups.todoNow.push(t);
            else groups.later.push(t);
        }
        const byDue = (a, b) => (a.due_at || Number.MAX_SAFE_INTEGER) - (b.due_at || Number.MAX_SAFE_INTEGER) || (b.priority - a.priority);
        groups.overdue.sort(byDue);
        groups.todoNow.sort(byDue);
        groups.later.sort(byDue);
        this.today = groups;

        this.notes = await Notes.list();
        this.$nextTick(() => createIcons({ icons }));

        queueMicrotask(() => window.dispatchEvent(new CustomEvent('rn:refresh-views')));
    },

    async runSync() {
        if (this.syncing) return;
        this.syncing = true;
        this.syncError = false;
        const result = await syncNow();
        this.syncing = false;
        if (result?.error === 'unauthorized') {
            clearTokens(); location.replace('./login.html'); return;
        }
        if (result?.online === false) {
            this.syncMessage = '离线模式 · 同步将于网络恢复后进行';
            this.syncError = false;
        } else if (result?.error) {
            this.syncMessage = '同步失败：' + result.error;
            this.syncError = true;
        } else {
            this.syncMessage = '已同步';
            this.syncError = false;
            setTimeout(() => { if (this.syncMessage === '已同步') this.syncMessage = ''; }, 1500);
        }
        await this.refreshAll();
    },

    pullTouchStart(e) {
        const t = e.touches?.[0];
        if (!t || window.scrollY > 8) {
            this._pullStartY = null;
            return;
        }
        this._pullStartY = t.clientY;
    },

    pullTouchMove(e) {
        if (this._pullStartY == null || !e.touches?.[0]) return;
        const y = e.touches[0].clientY;
        const d = Math.max(0, y - this._pullStartY);
        if (d > 16 && window.scrollY <= 8) this.pullActive = true;
        this.pullDistance = Math.min(d, 120);
        if (this.pullDistance > 8) e.preventDefault();
    },

    async pullTouchEnd() {
        this.pullActive = false;
        const shouldRefresh = this.pullDistance > 48;
        this.pullDistance = 0;
        this._pullStartY = null;
        if (shouldRefresh) await this.runSync();
    },

    touchSwipeStart(ev) {
        const touch = ev.touches?.[0];
        if (!touch || !ev.currentTarget) return;
        ev.currentTarget.dataset.sx = String(touch.clientX);
    },

    swipeTask(ev, task) {
        const el = ev.currentTarget;
        const t = ev.changedTouches?.[0];
        const x0 = Number(el?.dataset?.sx ?? NaN);
        if (!t || Number.isNaN(x0)) return;
        const dx = t.clientX - x0;
        if (dx < -56) this.toggleDone(task);
        else if (dx > 56 && task.status !== 'done') this.deleteTask(task);
    },

    async verifyAppPin() {
        const stored = localStorage.getItem(PIN_STORAGE);
        if (!stored) { this.pinLocked = false; return; }
        const pin = this.pinInput.trim();
        if (pin.length < 4) { this.flash('请输入至少 4 位 PIN'); return; }
        const h = await digestPin(pin);
        if (h !== stored) { this.flash('PIN 不正确'); this.pinInput = ''; return; }
        this.pinLocked = false;
        this.pinInput = '';
    },

    setAppPin() {
        this.pinModal = { open: true, mode: 'set', input1: '', input2: '', error: '' };
        this.$nextTick(() => createIcons({ icons }));
    },

    clearAppPin() {
        const stored = localStorage.getItem(PIN_STORAGE);
        if (!stored) {
            this.flash('当前未启用 PIN');
            return;
        }
        this.pinModal = { open: true, mode: 'clear', input1: '', input2: '', error: '' };
        this.$nextTick(() => createIcons({ icons }));
    },

    closePinModal() {
        this.pinModal = { open: false, mode: 'set', input1: '', input2: '', error: '' };
    },

    async submitPinModal() {
        const { mode, input1, input2 } = this.pinModal;
        if (mode === 'set') {
            const a = (input1 || '').trim();
            const b = (input2 || '').trim();
            if (!a || a.length < 4 || a.length > 8) {
                this.pinModal.error = 'PIN 长度需为 4～8 位';
                return;
            }
            if (a !== b) {
                this.pinModal.error = '两次输入不一致';
                return;
            }
            localStorage.setItem(PIN_STORAGE, await digestPin(a));
            this.closePinModal();
            this.flash('PIN 已启用，下次打开页面需要验证');
            return;
        }
        if (mode === 'clear') {
            const cur = (input1 || '').trim();
            const stored = localStorage.getItem(PIN_STORAGE);
            if (!stored) { this.closePinModal(); return; }
            if ((await digestPin(cur)) !== stored) {
                this.pinModal.error = 'PIN 不正确';
                return;
            }
            localStorage.removeItem(PIN_STORAGE);
            this.pinLocked = false;
            this.closePinModal();
            this.flash('应用锁已关闭');
        }
    },

    confirm(message, options = {}) {
        return new Promise((resolve) => {
            this.confirmDialog = {
                open: true,
                title: options.title || '确认操作',
                message: String(message || ''),
                confirmLabel: options.confirmLabel || '确认',
                cancelLabel: options.cancelLabel || '取消',
                danger: !!options.danger,
                _resolve: resolve,
            };
            this.$nextTick(() => createIcons({ icons }));
        });
    },

    resolveConfirm(value) {
        const r = this.confirmDialog._resolve;
        this.confirmDialog = { open: false, title: '', message: '', confirmLabel: '', cancelLabel: '', danger: false, _resolve: null };
        if (typeof r === 'function') r(!!value);
    },

    newSubtaskId() {
        try {
            if (crypto.randomUUID) return crypto.randomUUID();
        } catch {}
        return 'st_' + Math.random().toString(36).slice(2) + '_' + Date.now().toString(36);
    },

    async logout() {
        await api.logout();
        location.replace('./login.html');
    },

    bindGlobalShortcuts() {
        window.addEventListener('keydown', (e) => {
            const inField = e.target?.matches?.('input,textarea,[contenteditable]');
            if (e.key === 'Escape') {
                if (inField && e.target?.blur) e.target.blur();
                this.showCommand = false;
                this.showQuickAdd = false;
                this.showTaskDetail = null;
                this.showNoteDetail = null;
                return;
            }
            if (inField && !(e.metaKey || e.ctrlKey)) return;
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault(); this.showCommand = true;
            } else if (e.key === 'n' && !e.metaKey && !e.ctrlKey) {
                e.preventDefault(); this.showQuickAdd = true;
                this.$nextTick(() => document.getElementById('quickAddModalInput')?.focus());
            } else if (e.key === '/' && !e.metaKey && !e.ctrlKey) {
                e.preventDefault();
                document.getElementById('topSearch')?.focus();
            }
        });
    },

    async handleQuickAdd() {
        const text = this.quickAddText.trim();
        if (!text) return;
        const parsed = parseNL(text);
        const task = await Tasks.upsert({
            title: parsed.title,
            due_at: parsed.due_at,
            priority: this.quickAddPriority,
            status: 'todo',
        });
        this.quickAddText = '';
        this.showQuickAdd = false;
        dispatchSyncNow();
        await this.refreshAll();
        this.flash('已添加：' + task.title);
    },

    async toggleDone(task) {
        const wasDone = task.status === 'done';
        const updated = await Tasks.toggle(task.id);
        if (updated?.status === 'done' && !wasDone) {
            confetti({ particleCount: 60, spread: 70, origin: { y: 0.6 } });
            try { navigator.vibrate?.(40); } catch {}
            const next = nextOccurrence(updated);
            if (next) {
                const clone = { ...updated };
                delete clone.id;
                clone.status = 'todo';
                clone.completed_at = null;
                clone.deleted_at = null;
                clone.due_at = next;
                clone.remind_at = updated.remind_at && updated.due_at
                    ? next - (updated.due_at - updated.remind_at)
                    : null;
                clone.subtasks = (updated.subtasks || []).map(s => ({ ...s, done: false }));
                clone.created_at = Date.now();
                clone.updated_at = Date.now();
                await Tasks.upsert(clone);
                // Detach repeat rule from the now-completed instance so toggling
                // it back to todo and re-completing does not spawn a duplicate.
                await Tasks.upsert({ ...updated, repeat_rule: null, updated_at: Date.now() });
                this.flash('已生成下一次重复任务');
            }
        }
        dispatchSyncNow();
        await this.refreshAll();
    },

    async deleteTask(task) {
        const ok = await this.confirm(`删除任务「${task.title}」？\n\n可在“回收站”视图恢复或彻底清除。`, {
            title: '删除任务',
            confirmLabel: '删除',
            danger: true,
        });
        if (!ok) return;
        await Tasks.softDelete(task.id);
        this.showTaskDetail = null;
        dispatchSyncNow();
        await this.refreshAll();
    },

    async saveTask(task) {
        await Tasks.upsert(task);
        dispatchSyncNow();
        await this.refreshAll();
    },

    async newNote() {
        const note = await Notes.upsert({ title: '未命名笔记', content: '' });
        dispatchSyncNow();
        await this.refreshAll();
        this.showNoteDetail = note;
    },

    async saveNote(note) {
        await Notes.upsert(note);
        dispatchSyncNow();
        await this.refreshAll();
    },

    async deleteNote(note) {
        const ok = await this.confirm(`删除笔记「${note.title || '未命名'}」？\n\n可在“回收站”视图恢复或彻底清除。`, {
            title: '删除笔记',
            confirmLabel: '删除',
            danger: true,
        });
        if (!ok) return;
        await Notes.softDelete(note.id);
        this.showNoteDetail = null;
        dispatchSyncNow();
        await this.refreshAll();
    },

    async togglePin(note) {
        await Notes.upsert({ ...note, pinned: !note.pinned });
        dispatchSyncNow();
        await this.refreshAll();
    },

    async toggleFavorite(note) {
        await Notes.upsert({ ...note, favorite: !note.favorite });
        dispatchSyncNow();
        await this.refreshAll();
    },

    flash(msg) {
        this.syncMessage = msg;
        setTimeout(() => { if (this.syncMessage === msg) this.syncMessage = ''; }, 1800);
    },

    async exportData() {
        const tasks = await Tasks.list({ includeDeleted: true });
        const notes = await Notes.list();
        const payload = {
            kind: 'reminder-note-backup',
            version: 1,
            exportedAt: Date.now(),
            tasks,
            notes,
        };
        const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `reminder-note-backup-${new Date().toISOString().slice(0, 10)}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 0);
        this.flash(`已导出 ${tasks.length} 任务 + ${notes.length} 笔记`);
    },

    async importData(file) {
        if (!file) return;
        try {
            const text = await file.text();
            const data = JSON.parse(text);
            if (!data || data.kind !== 'reminder-note-backup') {
                this.flash('文件格式不正确（不是 Reminder Note 备份）');
                return;
            }
            const taskCount = Array.isArray(data.tasks) ? data.tasks.length : 0;
            const noteCount = Array.isArray(data.notes) ? data.notes.length : 0;
            const ok = await this.confirm(
                `将导入 ${taskCount} 个任务 + ${noteCount} 个笔记到当前账号。\n\n每条记录会以新 id 加入，不会覆盖现有数据；如果你是从其它账号导入，原账号不受影响。继续吗？`,
                { title: '导入备份', confirmLabel: '导入' },
            );
            if (!ok) return;
            // Always re-assign ids on import. The backup file's ids are owned
            // by the original account on the server; reusing them would either
            // (a) hit id_conflict on the server when importing into a *different*
            // account or (b) silently overwrite an existing record in the same
            // account. Treating import as "create new" is the safe default.
            for (const t of data.tasks || []) {
                const { id: _, dirty: __, ...rest } = t || {};
                await Tasks.upsert({ ...rest, id: newId() });
            }
            for (const n of data.notes || []) {
                const { id: _, dirty: __, ...rest } = n || {};
                await Notes.upsert({ ...rest, id: newId() });
            }
            dispatchSyncNow();
            await this.refreshAll();
            this.flash(`导入完成：${taskCount} 任务 + ${noteCount} 笔记`);
        } catch (err) {
            console.error('[import] failed', err);
            this.flash('导入失败：' + (err?.message || err));
        }
    },

    cycleTheme() { this.theme = cycleTheme(); },

    repeatPreset(task) {
        const raw = task?.repeat_rule;
        if (!raw) return '';
        try {
            const j = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return typeof j?.type === 'string' ? j.type : '';
        } catch {
            return '';
        }
    },

    applyRepeatPreset(task, preset) {
        const map = {
            daily: JSON.stringify({ type: 'daily' }),
            weekdays: JSON.stringify({ type: 'weekdays' }),
            weekly: JSON.stringify({ type: 'weekly' }),
        };
        task.repeat_rule = preset && map[preset] ? map[preset] : null;
        task.updated_at = Date.now();
        this.saveTask(task);
        if (this.showTaskDetail?.id === task.id) this.showTaskDetail = { ...task };
    },

    fmtTime, dueClass, relativeRemaining, renderMarkdown, stripMarkdown,
    formatDateTime, formatRelative, parseUA,
    PRIORITY_LABEL, PRIORITY_COLOR,
}));

Alpine.data('accountView', () => ({
    pwd: { old: '', new: '', confirm: '', busy: false, message: '', error: false },
    sessions: [],
    sessionsLoading: false,
    history: [],
    historyLoading: false,
    _viewListener: null,

    formatDateTime, formatRelative, parseUA,

    async init() {
        // Lazy-load when entering the view; refresh whenever the view becomes
        // visible again (e.g. user switches to it from another tab).
        this._viewListener = () => {
            const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
            if (shell?.view === 'account') {
                this.loadSessions();
                this.loadHistory();
            }
        };
        window.addEventListener('hashchange', this._viewListener);
        await Promise.all([this.loadSessions(), this.loadHistory()]);
        this.$nextTick(() => createIcons({ icons }));
    },
    destroy() {
        if (this._viewListener) window.removeEventListener('hashchange', this._viewListener);
    },

    async loadSessions() {
        this.sessionsLoading = true;
        try {
            const r = await api.listSessions();
            this.sessions = r?.sessions || [];
        } catch (e) {
            this.sessions = [];
            console.warn('[account] load sessions failed', e);
        } finally {
            this.sessionsLoading = false;
            this.$nextTick(() => createIcons({ icons }));
        }
    },

    async loadHistory() {
        this.historyLoading = true;
        try {
            const r = await api.loginHistory(50);
            this.history = r?.attempts || [];
        } catch (e) {
            this.history = [];
            console.warn('[account] load history failed', e);
        } finally {
            this.historyLoading = false;
            this.$nextTick(() => createIcons({ icons }));
        }
    },

    async submitPasswordChange() {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        this.pwd.error = false;
        this.pwd.message = '';
        if (!this.pwd.old || !this.pwd.new || !this.pwd.confirm) {
            this.pwd.error = true; this.pwd.message = '请填写所有字段'; return;
        }
        if (this.pwd.new !== this.pwd.confirm) {
            this.pwd.error = true; this.pwd.message = '两次输入的新密码不一致'; return;
        }
        if (this.pwd.new.length < 8) {
            this.pwd.error = true; this.pwd.message = '新密码至少 8 位'; return;
        }
        if (this.pwd.new === this.pwd.old) {
            this.pwd.error = true; this.pwd.message = '新密码不能与旧密码相同'; return;
        }
        this.pwd.busy = true;
        try {
            await api.changePassword(this.pwd.old, this.pwd.new);
            this.pwd.error = false;
            this.pwd.message = '密码已修改，其它设备已强制下线';
            this.pwd.old = this.pwd.new = this.pwd.confirm = '';
            await this.loadSessions();
            shell?.flash?.('密码已修改');
        } catch (e) {
            this.pwd.error = true;
            this.pwd.message = e?.message || '修改失败';
        } finally {
            this.pwd.busy = false;
        }
    },

    async revokeOne(session) {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        if (session.is_current) return;
        const ok = await shell.confirm(`注销该会话？\n${parseUA(session.user_agent).label} · ${session.ip || '未知 IP'}`, {
            title: '注销会话', confirmLabel: '注销', danger: true,
        });
        if (!ok) return;
        try {
            await api.revokeSession(session.jti);
            await this.loadSessions();
            shell?.flash?.('已注销该会话');
        } catch (e) {
            shell?.flash?.('注销失败：' + (e?.message || e));
        }
    },

    async revokeAll() {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        const others = this.sessions.filter(s => !s.is_current).length;
        if (!others) return;
        const ok = await shell.confirm(`将注销其它 ${others} 个会话（保留当前浏览器）。继续吗？`, {
            title: '注销所有其它设备', confirmLabel: '全部注销', danger: true,
        });
        if (!ok) return;
        try {
            const r = await api.revokeAllSessions();
            await this.loadSessions();
            shell?.flash?.('已注销 ' + (r?.revoked ?? others) + ' 个会话');
        } catch (e) {
            shell?.flash?.('操作失败：' + (e?.message || e));
        }
    },
}));

Alpine.data('todayView', () => ({
    init() {
        this.$nextTick(() => createIcons({ icons }));
    },
}));

Alpine.data('trashView', () => ({
    deletedTasks: [],
    deletedNotes: [],
    _reloadListener: null,
    async init() {
        await this.reload();
        this._reloadListener = () => this.reload();
        window.addEventListener('rn:refresh-views', this._reloadListener);
    },
    destroy() {
        window.removeEventListener('rn:refresh-views', this._reloadListener);
    },
    async reload() {
        this.deletedTasks = await Tasks.listDeleted();
        this.deletedNotes = await Notes.listDeleted();
        this.$nextTick(() => createIcons({ icons }));
    },
    async restoreTask(task) {
        await Tasks.restore(task.id);
        await this.reload();
        dispatchSyncNow();
        window.dispatchEvent(new CustomEvent('rn:refresh-views'));
    },
    async restoreNote(note) {
        await Notes.restore(note.id);
        await this.reload();
        dispatchSyncNow();
        window.dispatchEvent(new CustomEvent('rn:refresh-views'));
    },
    async purgeTask(task) {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        const ok = await shell.confirm(`彻底删除任务「${task.title || '未命名'}」？此操作不可撤销。`, {
            title: '彻底删除', confirmLabel: '永久删除', danger: true,
        });
        if (!ok) return;
        await Tasks.hardDelete(task.id);
        await this.reload();
    },
    async purgeNote(note) {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        const ok = await shell.confirm(`彻底删除笔记「${note.title || '未命名'}」？此操作不可撤销。`, {
            title: '彻底删除', confirmLabel: '永久删除', danger: true,
        });
        if (!ok) return;
        await Notes.hardDelete(note.id);
        await this.reload();
    },
    async emptyTrash() {
        const shell = Alpine.$data(document.querySelector('[x-data="appShell"]'));
        const total = this.deletedTasks.length + this.deletedNotes.length;
        if (!total) return;
        const ok = await shell.confirm(`将彻底删除 ${total} 项内容。此操作不可撤销。`, {
            title: '清空回收站', confirmLabel: '彻底清空', danger: true,
        });
        if (!ok) return;
        for (const t of this.deletedTasks) await Tasks.hardDelete(t.id);
        for (const n of this.deletedNotes) await Notes.hardDelete(n.id);
        await this.reload();
    },
}));

Alpine.data('notesView', () => ({
    filter: 'all',
    init() { this.$nextTick(() => createIcons({ icons })); },
    filterAndSort(items) {
        const list = (items || []).slice();
        const out = this.filter === 'pinned' ? list.filter(n => n.pinned)
                  : this.filter === 'favorite' ? list.filter(n => n.favorite)
                  : list;
        return out.sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || b.updated_at - a.updated_at);
    },
}));

Alpine.data('calendarView', () => ({
    instance: null,
    _reloadListener: null,
    _resizeListener: null,
    rebuildEvents(tasks) {
        const colorByPriority = ['#a09d96', '#7c4dff', '#f59e0b', '#ff4d14'];
        const list = tasks.filter(t => t.due_at);
        return list.map(t => {
            const cls = [];
            if (t.status === 'done') cls.push('fc-done');
            cls.push('fc-priority-' + (t.priority || 0));
            return {
                id: t.id,
                title: t.title,
                start: new Date(t.due_at).toISOString(),
                backgroundColor: colorByPriority[t.priority || 0],
                borderColor: colorByPriority[t.priority || 0],
                extendedProps: { task: t },
                classNames: cls,
            };
        });
    },
    async reloadFromTasks() {
        if (!this.instance) return;
        const tasks = await Tasks.list({ includeDeleted: false });
        this.instance.batchRendering(() => {
            [...this.instance.getEvents()].forEach(e => e.remove());
            const evs = this.rebuildEvents(tasks);
            evs.forEach(ev => this.instance.addEvent(ev));
        });
    },
    async init() {
        const tasks = await Tasks.list({ includeDeleted: false });
        const events = this.rebuildEvents(tasks);

        const cal = new Calendar(this.$refs.calRoot, {
            plugins: [dayGrid, timeGrid, interaction],
            initialView: 'dayGridMonth',
            locale: zhCn,
            firstDay: 1,
            height: 'auto',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay',
            },
            buttonText: { today: '今天', month: '月', week: '周', day: '日' },
            events,
            editable: true,
            eventDrop: async (info) => {
                const task = info.event.extendedProps.task;
                await Tasks.upsert({
                    ...task,
                    due_at: info.event.start?.getTime() ?? task.due_at,
                    updated_at: Date.now(),
                });
                dispatchSyncNow();
                await this.reloadFromTasks();
            },
            eventClick: (info) => {
                const task = info.event.extendedProps.task;
                window.dispatchEvent(new CustomEvent('rn:open-task', { detail: task }));
            },
        });
        cal.render();
        this.instance = cal;

        this._reloadListener = () => this.reloadFromTasks();
        window.addEventListener('rn:refresh-views', this._reloadListener);

        this._resizeListener = () => {
            if (!this.instance) return;
            requestAnimationFrame(() => this.instance.updateSize());
        };
        window.addEventListener('rn:resize-calendar', this._resizeListener);

        // First render runs while the parent section may still be display:none
        // (Alpine initializes x-data before applying x-show). Defer until after
        // layout so updateSize() sees the real container width.
        setTimeout(() => this._resizeListener(), 0);
    },

    destroy() {
        window.removeEventListener('rn:refresh-views', this._reloadListener);
        window.removeEventListener('rn:resize-calendar', this._resizeListener);
        if (this.instance) this.instance.destroy();
        this.instance = null;
    },
}));

Alpine.data('kanbanView', () => ({
    columns: [
        { id: 'todo', title: '待办', tasks: [] },
        { id: 'doing', title: '进行中', tasks: [] },
        { id: 'done', title: '已完成', tasks: [] },
    ],
    quickAddText: { todo: '', doing: '', done: '' },
    dragOverCol: null,
    _reloadListener: null,
    async init() {
        await this.refresh();
        this._reloadListener = () => this.refresh();
        window.addEventListener('rn:refresh-views', this._reloadListener);
    },
    destroy() {
        window.removeEventListener('rn:refresh-views', this._reloadListener);
    },
    async refresh() {
        const all = await Tasks.list({ includeDeleted: false });
        this.columns = this.columns.map(col => ({
            ...col,
            tasks: all.filter(t => t.status === col.id).sort((a, b) => b.priority - a.priority || (b.updated_at - a.updated_at)),
        }));
        this.$nextTick(() => createIcons({ icons }));
    },
    async quickAddIn(statusId) {
        const text = (this.quickAddText[statusId] || '').trim();
        if (!text) return;
        const parsed = parseNL(text);
        await Tasks.upsert({
            title: parsed.title,
            due_at: parsed.due_at,
            status: statusId,
            priority: 1,
            completed_at: statusId === 'done' ? Date.now() : null,
        });
        this.quickAddText[statusId] = '';
        await this.refresh();
        dispatchSyncNow();
        window.dispatchEvent(new CustomEvent('rn:refresh-views'));
    },
    async drop(targetCol, ev) {
        ev.preventDefault();
        const id = ev.dataTransfer.getData('text/task-id');
        if (!id) return;
        const t = await Tasks.get(id);
        if (!t || t.status === targetCol.id) return;
        await Tasks.upsert({
            ...t,
            status: targetCol.id,
            completed_at: targetCol.id === 'done' ? Date.now() : null,
            updated_at: Date.now(),
        });
        await this.refresh();
        dispatchSyncNow();
        window.dispatchEvent(new CustomEvent('rn:refresh-views'));
    },
    dragstart(t, ev) {
        ev.dataTransfer.setData('text/task-id', t.id);
    },
}));

Alpine.data('statsView', () => ({
    weekData: [],
    weekMax: 1,
    totals: { all: 0, done: 0, overdue: 0, today: 0 },
    priorityRows: [],
    _reloadListener: null,
    reload() {
        return this.load();
    },
    weekScale(n) {
        if (!this.weekMax) return 0;
        return Math.min(100, Math.round((n / this.weekMax) * 100));
    },
    async load() {
        const all = await Tasks.list({ includeDeleted: false });
        const now = Date.now();
        const todayStart = startOfDay();
        const week = [];
        for (let i = 6; i >= 0; i--) {
            const day = todayStart - i * 86400_000;
            const next = day + 86400_000;
            const completedThisDay = all.filter(t => t.completed_at && t.completed_at >= day && t.completed_at < next).length;
            const dueThisDay = all.filter(t => t.due_at && t.due_at >= day && t.due_at < next).length;
            week.push({
                label: new Date(day).toLocaleDateString('zh-CN', { weekday: 'short' }),
                date: day,
                completed: completedThisDay,
                due: dueThisDay,
            });
        }
        this.weekData = week;
        this.weekMax = Math.max(1, ...week.flatMap(d => [d.completed, d.due]));
        this.totals = {
            all: all.length,
            done: all.filter(t => t.status === 'done').length,
            overdue: all.filter(t => t.due_at && t.due_at < now && t.status !== 'done').length,
            today: all.filter(t => t.due_at && t.due_at >= todayStart && t.due_at < todayStart + 86400_000 && t.status !== 'done').length,
        };
        const open = all.filter(t => t.status !== 'done' && t.status !== 'archived');
        const colors = ['bg-ink-400', 'bg-brand-500', 'bg-amber-500', 'bg-accent-500'];
        const labels = ['低', '中', '高', '紧急'];
        const totalOpen = Math.max(1, open.length);
        this.priorityRows = labels.map((label, i) => {
            const count = open.filter(t => (t.priority || 0) === i).length;
            return { label, count, pct: Math.round((count / totalOpen) * 100), color: colors[i] };
        });
        this.$nextTick(() => createIcons({ icons }));
    },
    async init() {
        await this.load();
        this._reloadListener = () => this.load();
        window.addEventListener('rn:refresh-views', this._reloadListener);
    },
    destroy() {
        window.removeEventListener('rn:refresh-views', this._reloadListener);
    },
}));

Alpine.data('pomodoro', () => ({
    mode: 'idle',
    seconds: 25 * 60,
    timer: null,
    config: { focus: 25 * 60, break: 5 * 60 },

    start(mode = 'focus') {
        this.stop();
        this.mode = mode;
        this.seconds = this.config[mode];
        this.timer = setInterval(() => {
            this.seconds--;
            if (this.seconds <= 0) {
                if (this.timer) clearInterval(this.timer);
                this.timer = null;
                this.mode = 'idle';
                try { navigator.vibrate?.([200, 100, 200]); } catch {}
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification(mode === 'focus' ? '专注时间结束' : '休息结束');
                }
            }
        }, 1000);
    },
    stop() {
        if (this.timer) { clearInterval(this.timer); this.timer = null; }
        this.mode = 'idle';
    },
    formatted() {
        const m = Math.max(0, Math.floor(this.seconds / 60));
        const s = Math.max(0, this.seconds % 60);
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    },
}));

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./service-worker.js').catch(err => console.warn('SW failed', err));
    });
}

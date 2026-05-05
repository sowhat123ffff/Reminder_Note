import Alpine from 'alpinejs';
import { createIcons, icons } from 'lucide';
import confetti from 'canvas-confetti';
import zhCn from '@fullcalendar/core/locales/zh-cn.js';

import { api, isAuthenticated, clearTokens } from './api.js';
import { Tasks, Notes } from './db-local.js';
import { startAutoSync, syncNow } from './sync.js';
import { parse as parseNL } from './parser.js';
import { renderMarkdown, stripMarkdown } from './markdown.js';
import { applyTheme, getTheme, cycleTheme } from './theme.js';
import { ensurePermission as ensureNotifyPermission, startReminderLoop } from './notify.js';

if (!isAuthenticated()) {
    location.replace('./login.html');
}

window.Alpine = Alpine;

const VIEWS = ['today', 'calendar', 'kanban', 'notes', 'stats'];
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

function dispatchSyncNow() {
    window.dispatchEvent(new CustomEvent('rn:sync-now'));
}

Alpine.data('appShell', () => ({
    view: 'today',
    sidebarOpen: false,
    online: navigator.onLine,
    syncing: false,
    syncMessage: '',
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
    today: { all: [], overdue: [], todoNow: [], later: [], done: [] },
    notes: [],
    allTasks: [],
    search: '',
    theme: getTheme(),

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
        await ensureNotifyPermission();

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

        window.addEventListener('online', () => { this.online = true; this.runSync(); });
        window.addEventListener('offline', () => { this.online = false; });

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
        const result = await syncNow();
        this.syncing = false;
        if (result?.error === 'unauthorized') {
            clearTokens(); location.replace('./login.html'); return;
        }
        if (result?.online === false) {
            this.syncMessage = '离线模式';
        } else if (result?.error) {
            this.syncMessage = '同步失败：' + result.error;
        } else {
            this.syncMessage = '已同步';
            setTimeout(() => this.syncMessage = '', 1500);
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

    async setAppPin() {
        const a = prompt('设置 4～8 位应用 PIN（仅存在本浏览器）');
        if (!a || a.length < 4 || a.length > 8) { alert('PIN 长度为 4～8'); return; }
        const b = prompt('再次输入 PIN 确认');
        if (a !== b) { alert('两次不一致'); return; }
        localStorage.setItem(PIN_STORAGE, await digestPin(a));
        alert('PIN 已启用，下次打开页面需要验证');
        location.reload();
    },

    async clearAppPin() {
        const cur = prompt('请输入当前 PIN 以关闭应用锁');
        if (!cur) return;
        const stored = localStorage.getItem(PIN_STORAGE);
        if (!stored) { return; }
        if ((await digestPin(cur)) !== stored) { alert('PIN 不正确'); return; }
        localStorage.removeItem(PIN_STORAGE);
        alert('应用锁已关闭');
        this.pinLocked = false;
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
            if (e.target?.matches?.('input,textarea,[contenteditable]') && !(e.metaKey || e.ctrlKey)) return;
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault(); this.showCommand = true;
            } else if (e.key === 'n' && !e.metaKey && !e.ctrlKey) {
                e.preventDefault(); this.showQuickAdd = true;
                this.$nextTick(() => document.getElementById('quickAddModalInput')?.focus());
            } else if (e.key === '/' && !e.metaKey && !e.ctrlKey) {
                e.preventDefault();
                document.getElementById('topSearch')?.focus();
            } else if (e.key === 'Escape') {
                this.showCommand = false;
                this.showQuickAdd = false;
                this.showTaskDetail = null;
                this.showNoteDetail = null;
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
        const updated = await Tasks.toggle(task.id);
        if (updated?.status === 'done') {
            confetti({ particleCount: 60, spread: 70, origin: { y: 0.6 } });
            try { navigator.vibrate?.(40); } catch {}
        }
        dispatchSyncNow();
        await this.refreshAll();
    },

    async deleteTask(task) {
        if (!confirm(`删除任务「${task.title}」？`)) return;
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
        if (!confirm(`删除笔记「${note.title}」？`)) return;
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

    flash(msg) {
        this.syncMessage = msg;
        setTimeout(() => { if (this.syncMessage === msg) this.syncMessage = ''; }, 1800);
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
    PRIORITY_LABEL, PRIORITY_COLOR,
}));

Alpine.data('todayView', () => ({
    init() {
        this.$nextTick(() => createIcons({ icons }));
    },
}));

Alpine.data('notesView', () => ({
    init() { this.$nextTick(() => createIcons({ icons })); },
}));

Alpine.data('calendarView', () => ({
    instance: null,
    _reloadListener: null,
    _resizeListener: null,
    rebuildEvents(tasks) {
        const list = tasks.filter(t => t.due_at);
        return list.map(t => ({
            id: t.id,
            title: t.title,
            start: new Date(t.due_at).toISOString(),
            extendedProps: { task: t },
            classNames: t.status === 'done' ? ['fc-done'] : [],
        }));
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
        const { Calendar } = await import('@fullcalendar/core');
        const dayGrid = (await import('@fullcalendar/daygrid')).default;
        const timeGrid = (await import('@fullcalendar/timegrid')).default;
        const interaction = (await import('@fullcalendar/interaction')).default;

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
    },
    dragstart(t, ev) {
        ev.dataTransfer.setData('text/task-id', t.id);
    },
}));

Alpine.data('statsView', () => ({
    weekData: [],
    totals: { all: 0, done: 0, overdue: 0, today: 0 },
    _reloadListener: null,
    reload() {
        return this.load();
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
        this.totals = {
            all: all.length,
            done: all.filter(t => t.status === 'done').length,
            overdue: all.filter(t => t.due_at && t.due_at < now && t.status !== 'done').length,
            today: all.filter(t => t.due_at && t.due_at >= todayStart && t.due_at < todayStart + 86400_000 && t.status !== 'done').length,
        };
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

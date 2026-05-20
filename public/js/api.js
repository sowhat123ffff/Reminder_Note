/**
 * Authenticated API client with automatic JWT refresh.
 * Stores tokens in localStorage and queues concurrent refreshes.
 */

import { apiBaseFromPage } from './urls.js';
import { wipeAll as wipeLocalCache } from './db-local.js';

const STORAGE = {
    access:    'rn:accessToken',
    refresh:   'rn:refreshToken',
    accessExp: 'rn:accessExp',
    refreshExp:'rn:refreshExp',
};

function apiBase() {
    return apiBaseFromPage();
}

let refreshing = null;

function getAccess() { return localStorage.getItem(STORAGE.access); }
function getRefresh() { return localStorage.getItem(STORAGE.refresh); }
function setTokens(t) {
    if (t.accessToken) localStorage.setItem(STORAGE.access, t.accessToken);
    if (t.refreshToken) localStorage.setItem(STORAGE.refresh, t.refreshToken);
    if (t.accessExpiresAt) localStorage.setItem(STORAGE.accessExp, String(t.accessExpiresAt));
    if (t.refreshExpiresAt) localStorage.setItem(STORAGE.refreshExp, String(t.refreshExpiresAt));
}
function clearTokens() {
    Object.values(STORAGE).forEach(k => localStorage.removeItem(k));
}

export function isAuthenticated() {
    const access = getAccess();
    const refresh = getRefresh();
    if (!access && !refresh) return false;
    const accessExp = Number(localStorage.getItem(STORAGE.accessExp) || 0);
    const refreshExp = Number(localStorage.getItem(STORAGE.refreshExp) || 0);
    if (access && accessExp > Date.now() + 30_000) return true;
    if (refresh && refreshExp > Date.now() + 30_000) return true;
    return false;
}

async function refreshTokens() {
    if (refreshing) return refreshing;
    const refreshToken = getRefresh();
    if (!refreshToken) throw new Error('no_refresh');
    refreshing = (async () => {
        const res = await fetch(apiBase() + '/auth/refresh', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refreshToken }),
        });
        if (!res.ok) throw new Error('refresh_failed');
        const data = await res.json();
        setTokens(data);
        return data;
    })();
    try {
        return await refreshing;
    } finally {
        refreshing = null;
    }
}

export class ApiError extends Error {
    constructor(status, code, message, details) {
        super(message || code);
        this.status = status;
        this.code = code;
        this.details = details || {};
    }
}

async function rawFetch(path, opts = {}, withAuth = true) {
    const headers = new Headers(opts.headers || {});
    if (withAuth) {
        const t = getAccess();
        if (t) headers.set('Authorization', 'Bearer ' + t);
    }
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
        opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(apiBase() + path, { ...opts, headers });
    return res;
}

export async function request(path, opts = {}, retry = true) {
    let res;
    try {
        res = await rawFetch(path, opts, true);
    } catch (e) {
        throw new ApiError(0, 'network_error', '网络错误，请检查连接');
    }

    if (res.status === 401 && retry && getRefresh()) {
        try {
            await refreshTokens();
            return await request(path, opts, false);
        } catch {
            clearTokens();
            if (!location.pathname.endsWith('login.html')) {
                location.href = './login.html';
            }
            throw new ApiError(401, 'unauthorized', '请重新登录');
        }
    }

    let data = null;
    const text = await res.text();
    if (text) { try { data = JSON.parse(text); } catch { /* ignore */ } }

    if (!res.ok) {
        const err = data?.error || { code: 'http_' + res.status, message: text };
        throw new ApiError(res.status, err.code, err.message, err.details);
    }
    return data;
}

export const api = {
    register(username, password) {
        return rawFetch('/auth/register', { method: 'POST', body: { username, password } }, false)
            .then(async res => {
                const data = await res.json();
                if (!res.ok) throw new ApiError(res.status, data?.error?.code, data?.error?.message);
                await wipeLocalCache();
                setTokens(data);
                return data;
            });
    },
    login(username, password) {
        return rawFetch('/auth/login', { method: 'POST', body: { username, password } }, false)
            .then(async res => {
                const data = await res.json();
                if (!res.ok) throw new ApiError(res.status, data?.error?.code, data?.error?.message);
                await wipeLocalCache();
                setTokens(data);
                return data;
            });
    },
    async logout() {
        const refreshToken = getRefresh();
        try {
            await rawFetch('/auth/logout', { method: 'POST', body: { refreshToken } }, false);
        } catch { /* network failure shouldn't block local cleanup */ }
        try { await wipeLocalCache(); } catch { /* ignore */ }
        clearTokens();
    },
    me()                       { return request('/auth/me'); },

    changePassword(oldPassword, newPassword) {
        return request('/auth/password', { method: 'PATCH', body: { oldPassword, newPassword } });
    },
    listSessions()             { return request('/auth/sessions'); },
    revokeSession(jti)         { return request('/auth/sessions/' + encodeURIComponent(jti), { method: 'DELETE' }); },
    revokeAllSessions()        { return request('/auth/sessions', { method: 'DELETE' }); },
    loginHistory(limit = 50)   { return request('/auth/login-history?limit=' + Number(limit)); },

    listTasks(query = {})      {
        const qs = new URLSearchParams(query).toString();
        return request('/tasks' + (qs ? '?' + qs : ''));
    },
    createTask(task)           { return request('/tasks', { method: 'POST', body: task }); },
    updateTask(id, patch)      { return request('/tasks/' + encodeURIComponent(id), { method: 'PATCH', body: patch }); },
    deleteTask(id)             { return request('/tasks/' + encodeURIComponent(id), { method: 'DELETE' }); },
    restoreTask(id)            { return request('/tasks/' + encodeURIComponent(id) + '/restore', { method: 'POST' }); },

    listNotes(query = {})      {
        const qs = new URLSearchParams(query).toString();
        return request('/notes' + (qs ? '?' + qs : ''));
    },
    createNote(note)           { return request('/notes', { method: 'POST', body: note }); },
    updateNote(id, patch)      { return request('/notes/' + encodeURIComponent(id), { method: 'PATCH', body: patch }); },
    deleteNote(id)             { return request('/notes/' + encodeURIComponent(id), { method: 'DELETE' }); },
    restoreNote(id)            { return request('/notes/' + encodeURIComponent(id) + '/restore', { method: 'POST' }); },

    syncPull(since)            { return request('/sync/pull?since=' + Number(since || 0)); },
    syncPush(payload)          { return request('/sync/push', { method: 'POST', body: payload }); },
};

export { clearTokens, apiBase, wipeLocalCache };

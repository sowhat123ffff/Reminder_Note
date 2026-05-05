const STORAGE_KEY = 'rn:theme';

export function getTheme() {
    return localStorage.getItem(STORAGE_KEY) || 'system';
}

export function applyTheme(theme = getTheme()) {
    const root = document.documentElement;
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const dark = theme === 'dark' || (theme === 'system' && prefersDark);
    root.classList.toggle('dark', dark);
    const meta = document.querySelector('meta[name="theme-color"][media$="dark)"]');
    if (meta) meta.setAttribute('content', dark ? '#0a0a0b' : '#fafaf9');
}

export function setTheme(theme) {
    localStorage.setItem(STORAGE_KEY, theme);
    applyTheme(theme);
}

export function cycleTheme() {
    const order = ['light', 'dark', 'system'];
    const current = getTheme();
    const next = order[(order.indexOf(current) + 1) % order.length];
    setTheme(next);
    return next;
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (getTheme() === 'system') applyTheme('system');
});

applyTheme();

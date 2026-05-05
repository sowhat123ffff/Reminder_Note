/** Resolve paths relative to the web app root (/ or .../public/). */
export function publicBase() {
    const pathname = window.location.pathname;
    const idx = pathname.indexOf('/public/');
    if (idx >= 0) {
        return pathname.slice(0, idx + 8);
    }
    const lastSlash = pathname.lastIndexOf('/');
    return lastSlash > 0 ? pathname.slice(0, lastSlash + 1) : '/';
}

export function apiBaseFromPage() {
    const b = publicBase();
    return (b.endsWith('/') ? b : b + '/') + 'api';
}

export function assetUrl(path) {
    const clean = path.replace(/^\//, '');
    let base = publicBase();
    if (!base.endsWith('/')) base += '/';
    return base + clean;
}

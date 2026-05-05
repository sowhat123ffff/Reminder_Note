import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({
    gfm: true,
    breaks: true,
});

export function renderMarkdown(src = '') {
    const html = marked.parse(src);
    return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
}

export function stripMarkdown(src = '') {
    return src
        .replace(/```[\s\S]*?```/g, '')
        .replace(/`[^`]*`/g, '')
        .replace(/!?\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/[#>*_~`-]+/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

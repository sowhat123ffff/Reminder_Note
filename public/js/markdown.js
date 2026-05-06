import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({
    gfm: true,
    breaks: true,
});

DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href')) {
        const href = node.getAttribute('href') || '';
        if (/^https?:\/\//i.test(href)) {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer');
        }
    }
});

export function renderMarkdown(src = '') {
    const html = marked.parse(src);
    return DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target', 'rel'],
    });
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

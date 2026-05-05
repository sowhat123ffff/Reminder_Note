/**
 * Natural-language date/time parser wrapper.
 * Combines chrono-node + simple Chinese pattern matching for things like
 * "明天下午3点开会", "下周五", "今晚8点".
 */
import * as chrono from 'chrono-node';

const CN_REL = [
    { re: /^今天\s*/, days: 0 },
    { re: /^明天\s*/, days: 1 },
    { re: /^后天\s*/, days: 2 },
    { re: /^大后天\s*/, days: 3 },
];

const CN_TIME = /(?:(早上|上午|中午|下午|晚上|凌晨)\s*)?(\d{1,2})\s*[点时](?:(\d{1,2})\s*分?)?/;

function dropPrefix(text, regex) {
    return text.replace(regex, '').trim();
}

function applyTime(date, period, hh, mm) {
    let h = hh;
    if (period === '下午' || period === '晚上') {
        if (h < 12) h += 12;
    } else if (period === '中午') {
        h = 12;
    } else if (period === '凌晨') {
        if (h === 12) h = 0;
    } else if (!period && h < 6) {
        h += 12;
    }
    date.setHours(h, mm || 0, 0, 0);
    return date;
}

export function parse(text, ref = new Date()) {
    if (!text || typeof text !== 'string') return null;
    let title = text.trim();
    let due = null;

    for (const r of CN_REL) {
        if (r.re.test(title)) {
            const d = new Date(ref);
            d.setDate(d.getDate() + r.days);
            d.setHours(9, 0, 0, 0);
            title = dropPrefix(title, r.re);
            due = d;
            break;
        }
    }

    const tm = title.match(CN_TIME);
    if (tm) {
        const period = tm[1] || '';
        const hh = parseInt(tm[2], 10);
        const mm = tm[3] ? parseInt(tm[3], 10) : 0;
        const base = due || new Date(ref);
        applyTime(base, period, hh, mm);
        title = title.replace(tm[0], '').trim();
        due = base;
    }

    if (!due) {
        const results = chrono.parse(title, ref, { forwardDate: true });
        if (results.length) {
            const r = results[0];
            due = r.date();
            title = (title.slice(0, r.index) + title.slice(r.index + r.text.length)).trim();
        }
    }

    title = title.replace(/^[、，,。\.\s]+/, '').replace(/[、，,。\.\s]+$/, '');
    return {
        title: title || text.trim(),
        due_at: due ? due.getTime() : null,
    };
}

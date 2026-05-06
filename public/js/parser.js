/**
 * Natural-language date/time parser wrapper.
 * Combines chrono-node + simple Chinese pattern matching for things like
 * "明天下午3点开会", "下周五", "今晚8点".
 */
import * as chrono from 'chrono-node';

const CN_REL = [
    { re: /大后天/, days: 3 },
    { re: /后天/,   days: 2 },
    { re: /明天|明日/,   days: 1 },
    { re: /今天|今日/, days: 0 },
];

// "今晚/明晚/今早" 等指定时段的关键词（包含日期偏移 + 时段提示）
const CN_DAYTIME = [
    { re: /今晚|今夜/, days: 0, period: '晚上' },
    { re: /明晚/,       days: 1, period: '晚上' },
    { re: /后晚/,       days: 2, period: '晚上' },
    { re: /今早|今天早上/, days: 0, period: '早上' },
    { re: /明早|明天早上/, days: 1, period: '早上' },
];

const CN_TIME = /(?:(早上|上午|中午|下午|晚上|凌晨)\s*)?(\d{1,2})\s*[点时](?:(\d{1,2})\s*分?|(半))?/;

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
    let periodHint = '';

    for (const r of CN_DAYTIME) {
        const m = title.match(r.re);
        if (m) {
            const d = new Date(ref);
            d.setDate(d.getDate() + r.days);
            d.setHours(r.period === '晚上' ? 20 : 8, 0, 0, 0);
            title = (title.slice(0, m.index) + title.slice(m.index + m[0].length)).trim();
            due = d;
            periodHint = r.period;
            break;
        }
    }

    if (!due) {
        for (const r of CN_REL) {
            const m = title.match(r.re);
            if (m) {
                const d = new Date(ref);
                d.setDate(d.getDate() + r.days);
                d.setHours(9, 0, 0, 0);
                title = (title.slice(0, m.index) + title.slice(m.index + m[0].length)).trim();
                due = d;
                break;
            }
        }
    }

    const tm = title.match(CN_TIME);
    if (tm) {
        const period = tm[1] || periodHint;
        const hh = parseInt(tm[2], 10);
        const mm = tm[4] === '半' ? 30 : (tm[3] ? parseInt(tm[3], 10) : 0);
        const base = due || new Date(ref);
        applyTime(base, period, hh, mm);
        title = (title.slice(0, tm.index) + title.slice(tm.index + tm[0].length)).trim();
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

    title = title.replace(/^[、，,。\.\s]+/, '').replace(/[、，,。\.\s]+$/, '').replace(/\s{2,}/g, ' ');
    return {
        title: title || text.trim(),
        due_at: due ? due.getTime() : null,
    };
}

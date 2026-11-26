/**
 * Pure helpers for markdown formatting in a <textarea>.
 * Each returns { value, start, end } — new text + new selection.
 */

/** Wrap selection: **bold**, _italic_, `code`, ~~strike~~ (toggle if already wrapped). */
export function wrapSelection(value, start, end, before, after = before, placeholder = '') {
    const selected = value.slice(start, end);

    // Toggle off: selection already surrounded by markers
    if (value.slice(start - before.length, start) === before && value.slice(end, end + after.length) === after) {
        const v = value.slice(0, start - before.length) + selected + value.slice(end + after.length);
        return { value: v, start: start - before.length, end: end - before.length };
    }

    const inner = selected || placeholder;
    const v = value.slice(0, start) + before + inner + after + value.slice(end);
    const s = start + before.length;
    return { value: v, start: s, end: s + inner.length };
}

/** Prefix every selected line: "> " quote, "- " list, "1. " numbered (toggle). */
export function prefixLines(value, start, end, prefix, { numbered = false } = {}) {
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    let lineEnd = value.indexOf('\n', end);
    if (lineEnd === -1) lineEnd = value.length;

    const lines = value.slice(lineStart, lineEnd).split('\n');
    const re = numbered ? /^\d+\.\s/ : new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const allPrefixed = lines.every(l => re.test(l));

    const newLines = lines.map((l, i) => allPrefixed
        ? l.replace(re, '')
        : (numbered ? `${i + 1}. ` : prefix) + l);
    const block = newLines.join('\n');
    const v = value.slice(0, lineStart) + block + value.slice(lineEnd);
    return { value: v, start: lineStart, end: lineStart + block.length };
}

/** Fenced code block around selection (on its own lines). */
export function codeBlock(value, start, end, lang = '') {
    const selected = value.slice(start, end) || '';
    const needNlBefore = start > 0 && value[start - 1] !== '\n';
    const needNlAfter = end < value.length && value[end] !== '\n';
    const head = (needNlBefore ? '\n' : '') + '```' + lang + '\n';
    const tail = '\n```' + (needNlAfter ? '\n' : '');
    const v = value.slice(0, start) + head + selected + tail + value.slice(end);
    const s = start + head.length;
    return { value: v, start: s, end: s + selected.length };
}

/** [text](url) */
export function link(value, start, end, urlPlaceholder = 'https://') {
    const text = value.slice(start, end) || 'link';
    const insert = `[${text}](${urlPlaceholder})`;
    const v = value.slice(0, start) + insert + value.slice(end);
    const urlStart = start + text.length + 3;
    return { value: v, start: urlStart, end: urlStart + urlPlaceholder.length };
}

/** Insert plain text at cursor (replaces selection). */
export function insertText(value, start, end, text) {
    const v = value.slice(0, start) + text + value.slice(end);
    const pos = start + text.length;
    return { value: v, start: pos, end: pos };
}

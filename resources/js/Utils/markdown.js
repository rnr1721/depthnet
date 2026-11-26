/**
 * Markdown rendering for chat messages and the composer preview.
 *
 * Key points:
 *  - Raw HTML from the model is ESCAPED by the renderer (not pre-escaped in the
 *    source). Pre-escaping `<` to `&lt;` before marked is what broke code
 *    blocks: marked escapes code once more and `&lt;` showed up literally.
 *  - Fenced code gets a header with language + copy button (data-md-copy).
 *  - Own Marked instance and own DOMPurify instance: global settings of other
 *    pages are not touched.
 *
 * Requires marked >= 5 (the `Marked` class). Works with both renderer APIs:
 * old positional args (<= v12) and token objects (v13+).
 */
import { Marked } from 'marked';
import createDOMPurify from 'dompurify';

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const md = new Marked({ gfm: true, breaks: true });

md.use({
    renderer: {
        // Raw HTML (block or inline) is shown as text, never executed/rendered.
        html(tokenOrHtml, blockArg) {
            const isToken = typeof tokenOrHtml === 'object' && tokenOrHtml !== null;
            const text = isToken ? (tokenOrHtml.text ?? tokenOrHtml.raw ?? '') : String(tokenOrHtml ?? '');
            const block = isToken ? !!tokenOrHtml.block : !!blockArg;
            const escaped = escapeHtml(text);
            return block ? `<p>${escaped.replace(/\n+$/, '').replace(/\n/g, '<br>')}</p>\n` : escaped;
        },

        code(tokenOrCode, infoArg) {
            const isToken = typeof tokenOrCode === 'object' && tokenOrCode !== null;
            const text = (isToken ? tokenOrCode.text : tokenOrCode) ?? '';
            const info = (isToken ? tokenOrCode.lang : infoArg) || '';
            const lang = String(info).trim().split(/\s+/)[0] || '';
            const langClass = lang ? ` class="language-${escapeHtml(lang)}"` : '';
            return `<div class="md-code">`
                + `<div class="md-code-head"><span class="md-code-lang">${escapeHtml(lang || 'text')}</span>`
                + `<button type="button" class="md-code-copy" data-md-copy="1">Copy</button></div>`
                + `<pre><code${langClass}>${escapeHtml(String(text).replace(/\n$/, ''))}</code></pre>`
                + `</div>\n`;
        },
    },
});

// Separate sanitizer instance so hooks don't leak to the rest of the app.
const purify = typeof window !== 'undefined' ? createDOMPurify(window) : null;
if (purify) {
    purify.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName === 'A' && node.getAttribute('href')) {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer nofollow');
        }
    });
}

export function sanitizeHtml(html) {
    if (!purify) return '';
    return purify.sanitize(html, { ADD_ATTR: ['target', 'data-md-copy'] });
}

/** Markdown -> HTML string, NOT sanitized (caller may inject placeholders first). */
export function renderMarkdownRaw(text) {
    return md.parse(String(text ?? ''));
}

/** Markdown -> sanitized HTML. */
export function renderMarkdown(text) {
    return sanitizeHtml(renderMarkdownRaw(text));
}

// ---------------------------------------------------------------------------
// Code-aware text transforms
// ---------------------------------------------------------------------------

// Fenced block: ``` or ~~~ (3+), until matching fence or end of text.
const FENCE_RE = /(^|\n)[ \t]*(`{3,}|~{3,})[^\n]*[\s\S]*?(?:\n[ \t]*\2[`~]*[ \t]*(?=\n|$)|$)/g;
// Inline code span.
const INLINE_RE = /(`+)(?!`)[\s\S]*?[^`]\1(?!`)|(`)[^`\n]\2/g;

function mapOutside(text, re, fn) {
    let out = '';
    let last = 0;
    for (const m of text.matchAll(re)) {
        out += fn(text.slice(last, m.index)) + m[0];
        last = m.index + m[0].length;
    }
    return out + fn(text.slice(last));
}

/**
 * Apply `fn` only to the parts of markdown text that are NOT code
 * (fenced blocks and inline spans stay untouched).
 */
export function mapOutsideCode(text, fn) {
    return mapOutside(String(text ?? ''), FENCE_RE, (part) => mapOutside(part, INLINE_RE, fn));
}

/** Click handler for copy buttons inside rendered markdown (event delegation). */
export async function handleMarkdownClick(event, copyLabel = 'Copy', doneLabel = '✓') {
    const btn = event.target?.closest?.('[data-md-copy]');
    if (!btn) return false;
    event.preventDefault();
    const code = btn.closest('.md-code')?.querySelector('code')?.textContent ?? '';
    await copyText(code);
    btn.textContent = doneLabel;
    setTimeout(() => { btn.textContent = copyLabel; }, 1200);
    return true;
}

export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        try {
            const el = document.createElement('textarea');
            el.value = text;
            el.setAttribute('readonly', '');
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            return true;
        } catch {
            return false;
        }
    }
}

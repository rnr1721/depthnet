import express from 'express';
import { chromium } from 'playwright';

const app = express();
app.use(express.json());

const PORT = process.env.BROWSER_SERVICE_PORT || 3001;
const SESSION_TTL = parseInt(process.env.SESSION_TTL || '3600') * 1000; // ms
const MAX_SESSIONS = parseInt(process.env.MAX_SESSIONS || '10');
const SNAPSHOT_TEXT_LIMIT = parseInt(process.env.SNAPSHOT_TEXT_LIMIT || '3000');
const SNAPSHOT_LINKS_LIMIT = parseInt(process.env.SNAPSHOT_LINKS_LIMIT || '30');

// ── Session storage ──────────────────────────────────────────────────────────

/**
 * @typedef {Object} Session
 * @property {import('playwright').Browser} browser
 * @property {import('playwright').BrowserContext} context
 * @property {import('playwright').Page} page    // currently active page (may switch on popups)
 * @property {number} lastUsed
 */

/**
 * @type {Map<string, Session>}
 */
const sessions = new Map();

/**
 * In-flight session creation locks. Prevents a race where two concurrent
 * requests for the same sessionId both miss the cache and each spin up a
 * browser (leaking one). Keyed by sessionId → Promise<Session>.
 *
 * @type {Map<string, Promise<Session>>}
 */
const creating = new Map();

/**
 * Whether a session is alive and usable.
 *
 * @param {Session} session
 * @returns {boolean}
 */
function isSessionAlive(session) {
    return session
        && session.browser.isConnected()
        && session.page
        && !session.page.isClosed();
}

/**
 * Get or create a browser session for given sessionId.
 *
 * @param {string} sessionId
 * @returns {Promise<import('playwright').Page>}
 */
async function getSession(sessionId) {
    // Live session in cache → reuse
    if (sessions.has(sessionId)) {
        const session = sessions.get(sessionId);
        if (isSessionAlive(session)) {
            session.lastUsed = Date.now();
            return session.page;
        }
        // Dead session (crashed/closed page) → tear down and recreate
        console.log(`[session] stale session detected, recreating: ${sessionId}`);
        await closeSession(sessionId);
    }

    // Creation already in flight for this id → await the same promise
    if (creating.has(sessionId)) {
        const session = await creating.get(sessionId);
        session.lastUsed = Date.now();
        return session.page;
    }

    const promise = createSession(sessionId);
    creating.set(sessionId, promise);
    try {
        const session = await promise;
        return session.page;
    } finally {
        creating.delete(sessionId);
    }
}

/**
 * Actually launch a browser and build a session.
 *
 * @param {string} sessionId
 * @returns {Promise<Session>}
 */
async function createSession(sessionId) {
    if (sessions.size >= MAX_SESSIONS) {
        await evictOldestSession();
    }

    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        viewport: { width: 1280, height: 720 },
    });

    const page = await context.newPage();

    const session = { browser, context, page, lastUsed: Date.now() };
    sessions.set(sessionId, session);

    // Track popups / new tabs (OAuth "Login with..." flows open a new Page).
    // We switch the active page to the newest one so the agent doesn't lose it.
    context.on('page', (newPage) => {
        console.log(`[session] new tab opened in ${sessionId}, switching active page`);
        session.page = newPage;
        // If the popup closes, fall back to the last remaining page.
        newPage.on('close', () => {
            const pages = context.pages();
            if (pages.length > 0) {
                session.page = pages[pages.length - 1];
            }
        });
    });

    console.log(`[session] created: ${sessionId} (total: ${sessions.size})`);

    return session;
}

/**
 * Close and remove a session.
 *
 * @param {string} sessionId
 */
async function closeSession(sessionId) {
    const session = sessions.get(sessionId);
    if (!session) return;

    try {
        await session.browser.close();
    } catch (_) { }

    sessions.delete(sessionId);
    console.log(`[session] closed: ${sessionId} (total: ${sessions.size})`);
}

/**
 * Evict the session that was used least recently.
 */
async function evictOldestSession() {
    let oldest = null;
    let oldestTime = Infinity;

    for (const [id, session] of sessions.entries()) {
        if (session.lastUsed < oldestTime) {
            oldestTime = session.lastUsed;
            oldest = id;
        }
    }

    if (oldest) {
        console.log(`[session] evicting oldest: ${oldest}`);
        await closeSession(oldest);
    }
}

// TTL cleanup — runs every minute
setInterval(async () => {
    const now = Date.now();
    for (const [id, session] of sessions.entries()) {
        if (now - session.lastUsed > SESSION_TTL) {
            console.log(`[session] TTL expired: ${id}`);
            await closeSession(id);
        }
    }
}, 60_000);

// ── Snapshot builder ─────────────────────────────────────────────────────────

/**
 * Build a structured snapshot of the current page state.
 *
 * Each interactive element (link, input, button) is tagged in the live DOM
 * with a `data-dn-ref="N"` attribute and exposed to the agent under that
 * number. The agent can then act by number ([browser click]3[/browser]) or
 * by raw selector/text — the resolver handles both. Tagging in the DOM means
 * the mapping survives reflows within a snapshot and never desyncs from a
 * separately-stored index.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<object>}
 */
async function buildSnapshot(page) {
    return await page.evaluate(
        ({ textLimit, linksLimit }) => {
            // Clear any refs from a previous snapshot so numbers don't accumulate
            document.querySelectorAll('[data-dn-ref]').forEach(el => el.removeAttribute('data-dn-ref'));

            // Sequential, gap-free numbering. We DON'T assign during collection
            // (filters would punch holes in the sequence — [1][2][3] then [6]).
            // Instead each item carries its element; we number them at the very
            // end, after all filtering, so the agent sees a clean 1..N list.
            let refCounter = 0;
            const assignRef = (el) => {
                const ref = String(++refCounter);
                el.setAttribute('data-dn-ref', ref);
                return ref;
            };

            const isVisible = (el) => {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) return false;
                const style = window.getComputedStyle(el);
                return style.visibility !== 'hidden' && style.display !== 'none';
            };

            // A modal/overlay is open when a large fixed-position element covers
            // the centre of the screen. We surface this to the agent as a flag so
            // it knows it's in a dialog (act inside it, or close it) — we do NOT
            // try to hide the elements underneath. The browser is a universal tool;
            // what's reachable is the agent's judgement, not ours to second-guess.
            const detectModal = () => {
                const centre = document.elementFromPoint(
                    window.innerWidth / 2,
                    window.innerHeight / 2
                );
                let node = centre;
                while (node && node !== document.body) {
                    const style = window.getComputedStyle(node);
                    if (style.position === 'fixed') {
                        const rect = node.getBoundingClientRect();
                        const coversMost = rect.width >= window.innerWidth * 0.6
                            && rect.height >= window.innerHeight * 0.6;
                        if (coversMost) {
                            return true;
                        }
                    }
                    node = node.parentElement;
                }
                return false;
            };

            const modalOpen = detectModal();

            // ── Text ────────────────────────────────────────────────────────
            // Remove noise nodes before collecting text
            const noiseSelectors = ['nav', 'footer', 'aside', 'header', 'script', 'style', 'noscript'];
            const cloned = document.body.cloneNode(true);
            noiseSelectors.forEach(sel => {
                cloned.querySelectorAll(sel).forEach(el => el.remove());
            });
            const rawText = (cloned.innerText || cloned.textContent || '')
                .replace(/\s{3,}/g, '\n\n')
                .trim();
            const text = rawText.slice(0, textLimit);
            const textTruncated = rawText.length > textLimit;

            // ── Inputs ──────────────────────────────────────────────────────
            // Collected first so login fields get low, stable numbers.
            const inputEls = Array.from(document.querySelectorAll('input, textarea, select'))
                .filter(el => {
                    const type = el.getAttribute('type') || '';
                    return !['hidden', 'submit', 'button', 'reset', 'image'].includes(type)
                        && isVisible(el);
                })
                .slice(0, 20);

            // ── Buttons ─────────────────────────────────────────────────────
            const buttonEls = Array.from(document.querySelectorAll(
                'button, input[type=submit], input[type=button], [role=button]'
            ))
                .filter(isVisible)
                .map(el => ({
                    el,
                    text: (el.innerText || el.textContent || el.getAttribute('value') || el.getAttribute('aria-label') || '')
                        .trim().slice(0, 60),
                }))
                .filter(b => b.text.length > 0)
                .slice(0, 25);

            // ── Links ───────────────────────────────────────────────────────
            const linkEls = Array.from(document.querySelectorAll('a[href]'))
                .filter(a => {
                    const href = a.getAttribute('href');
                    const label = (a.innerText || a.textContent || '').trim();
                    return label.length > 0
                        && href
                        && !href.startsWith('#')
                        && !href.startsWith('javascript:')
                        && isVisible(a);
                })
                .slice(0, linksLimit);

            // Now assign gap-free refs in display order: inputs → buttons → links.
            const inputs = inputEls.map(el => ({
                ref: assignRef(el),
                type: el.tagName.toLowerCase() === 'textarea'
                    ? 'textarea'
                    : el.tagName.toLowerCase() === 'select'
                        ? 'select'
                        : (el.getAttribute('type') || 'text'),
                name: el.getAttribute('name') || el.getAttribute('id') || el.getAttribute('aria-label') || '',
                placeholder: el.getAttribute('placeholder') || '',
                value: (el.value || '').slice(0, 40),
            }));

            const buttons = buttonEls.map(b => ({
                ref: assignRef(b.el),
                text: b.text,
            }));

            const links = linkEls.map(a => {
                const href = a.getAttribute('href');
                let abs;
                try {
                    abs = href.startsWith('http') ? href : new URL(href, location.origin).href;
                } catch (_) {
                    abs = href;
                }
                return {
                    ref: assignRef(a),
                    text: (a.innerText || a.textContent || '').trim().slice(0, 80),
                    url: abs,
                };
            });

            return {
                text,
                textTruncated,
                modalOpen,
                links,
                inputs,
                buttons,
                scrollY: Math.round(window.scrollY),
                scrollHeight: Math.round(document.documentElement.scrollHeight),
                viewportHeight: Math.round(window.innerHeight),
            };
        },
        { textLimit: SNAPSHOT_TEXT_LIMIT, linksLimit: SNAPSHOT_LINKS_LIMIT }
    );
}

/**
 * Build a snapshot payload bundled with page title/url.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<object>}
 */
async function snapshotWithMeta(page) {
    const title = await page.title();
    const url = page.url();
    const snapshot = await buildSnapshot(page);
    return { title, url, ...snapshot };
}

// ── Selector resolution (numbered handle OR raw selector/text) ────────────────

/**
 * Resolve the agent's target argument into a Playwright Locator.
 *
 * Rules:
 *   - pure integer ("3")        → [data-dn-ref="3"]   (numbered handle)
 *   - "text=..."                → Playwright text engine
 *   - anything else             → treated as a CSS selector, with a
 *                                 visible-text fallback if the CSS match fails
 *
 * @param {import('playwright').Page} page
 * @param {string} target
 * @returns {{ locator: import('playwright').Locator, kind: string }}
 */
function resolveTarget(page, target) {
    const t = String(target).trim();

    if (/^\d+$/.test(t)) {
        return { locator: page.locator(`[data-dn-ref="${t}"]`), kind: 'ref' };
    }

    if (t.startsWith('text=')) {
        return { locator: page.getByText(t.slice(5), { exact: false }).first(), kind: 'text' };
    }

    return { locator: page.locator(t).first(), kind: 'css' };
}

// ── Action handlers ──────────────────────────────────────────────────────────

/**
 * Each handler returns a plain object. Mutating actions (click, press, back,
 * type+submit) attach a fresh snapshot so the agent sees the consequence of
 * the action in the same cycle instead of having to call snapshot separately.
 *
 * @type {Record<string, (page: import('playwright').Page, args: object, sessionId: string) => Promise<object>>}
 */
const actions = {

    /**
     * Open a URL and return a full snapshot.
     */
    async open(page, { url, timeout = 30000 }) {
        if (!url) throw new Error('url is required');

        await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
        // Let JS-heavy pages settle; networkidle is best-effort (don't hang forever).
        await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => { });

        return await snapshotWithMeta(page);
    },

    /**
     * Return a snapshot of the current page without navigation.
     */
    async snapshot(page) {
        return await snapshotWithMeta(page);
    },

    /**
     * Click an element by numbered handle, CSS selector, or visible text.
     * Returns a fresh snapshot of the resulting page state.
     */
    async click(page, { selector, timeout = 10000 }) {
        if (!selector) throw new Error('selector is required');

        const { locator, kind } = resolveTarget(page, selector);

        try {
            await locator.click({ timeout });
        } catch (err) {
            const msg = String(err && err.message || '');

            // Element found but something is on top of it (modal/overlay/sticky
            // bar). Don't dump Playwright's 20-line log at the agent — give it a
            // short, actionable reason and a fresh snapshot so it can see the
            // dialog and decide to act inside it or close it.
            if (msg.includes('intercepts pointer events')) {
                const snap = await snapshotWithMeta(page);
                return {
                    clickBlocked: selector,
                    reason: 'Another element (likely a modal dialog or overlay) is on top, '
                        + 'so this element can\'t be clicked right now. '
                        + (snap.modalOpen
                            ? 'A dialog is open — act on the elements inside it, or close it first.'
                            : 'Close the overlay or scroll, then try again.'),
                    ...snap,
                };
            }

            // CSS/ref miss → last-ditch visible-text match
            if (kind === 'css') {
                await page.getByText(selector, { exact: false }).first().click({ timeout });
            } else {
                throw err;
            }
        }

        await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => { });
        await page.waitForTimeout(400);

        return { clicked: selector, ...(await snapshotWithMeta(page)) };
    },

    /**
     * Type text into an input field (by handle/selector/text).
     *
     * Uses real key events (pressSequentially) rather than a bulk value set,
     * so React/Vue-controlled inputs and live-validating login forms actually
     * register the change. Optionally submits with Enter and returns a
     * snapshot of the result.
     */
    async type(page, { selector, text, delay = 25, timeout = 10000, submit = false }) {
        if (!selector) throw new Error('selector is required');
        if (text === undefined) throw new Error('text is required');

        const { locator } = resolveTarget(page, selector);

        await locator.click({ timeout });
        await locator.fill('');                          // clear existing value
        await locator.pressSequentially(text, { delay }); // emit keydown/input/keyup

        if (submit) {
            await page.keyboard.press('Enter');
            await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => { });
            await page.waitForTimeout(400);
            return { typed: text, into: selector, submitted: true, ...(await snapshotWithMeta(page)) };
        }

        return { typed: text, into: selector, submitted: false };
    },

    /**
     * Press a keyboard key (e.g. "Enter", "Tab", "Escape").
     * Returns a fresh snapshot since a keypress may change page state.
     */
    async press(page, { key }) {
        if (!key) throw new Error('key is required');

        await page.keyboard.press(key);
        await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => { });
        await page.waitForTimeout(300);

        return { pressed: key, ...(await snapshotWithMeta(page)) };
    },

    /**
     * Wait for a selector to appear.
     */
    async wait(page, { selector, timeout = 15000 }) {
        if (!selector) throw new Error('selector is required');
        const { locator } = resolveTarget(page, selector);
        await locator.waitFor({ timeout });
        return { appeared: selector };
    },

    /**
     * Scroll the page down by a given amount of pixels.
     * Returns a snapshot so the agent sees newly revealed content.
     */
    async scroll(page, { pixels = 500 }) {
        await page.evaluate((px) => window.scrollBy(0, px), pixels);
        await page.waitForTimeout(250);
        return { scrolled: pixels, ...(await snapshotWithMeta(page)) };
    },

    /**
     * Go back in browser history and return a snapshot.
     */
    async back(page, { timeout = 10000 }) {
        await page.goBack({ timeout, waitUntil: 'domcontentloaded' }).catch(() => { });
        await page.waitForTimeout(300);
        return { navigated: 'back', ...(await snapshotWithMeta(page)) };
    },

    /**
     * Close this session (frees browser resources).
     */
    async close(_page, _args, sessionId) {
        await closeSession(sessionId);
        return { closed: true };
    },

    /**
     * Health check — used by Laravel to verify the service is up.
     */
    async ping() {
        return { pong: true };
    },
};

// Actions that don't need a live browser page
const PAGELESS_ACTIONS = new Set(['ping', 'close']);

// ── HTTP API ─────────────────────────────────────────────────────────────────

/**
 * POST /action
 *
 * Body: { sessionId: string, action: string, args?: object }
 */
app.post('/action', async (req, res) => {
    const { sessionId, action, args = {} } = req.body;

    if (!sessionId) return res.status(400).json({ ok: false, error: 'sessionId is required' });
    if (!action) return res.status(400).json({ ok: false, error: 'action is required' });

    const handler = actions[action];
    if (!handler) return res.status(400).json({ ok: false, error: `Unknown action: ${action}` });

    console.log(`[action] ${sessionId} → ${action}`, Object.keys(args).length ? args : '');

    try {
        const page = PAGELESS_ACTIONS.has(action) ? null : await getSession(sessionId);
        const result = await handler(page, args, sessionId);
        return res.json({ ok: true, ...result });
    } catch (err) {
        console.error(`[error] ${sessionId}/${action}:`, err.message);
        return res.json({ ok: false, error: err.message });
    }
});

/**
 * GET /health
 */
app.get('/health', (_req, res) => {
    res.json({ ok: true, sessions: sessions.size });
});

// ── Start ────────────────────────────────────────────────────────────────────

app.listen(PORT, () => {
    console.log(`[browser-service] listening on port ${PORT}`);
    console.log(`[browser-service] max sessions: ${MAX_SESSIONS}, TTL: ${SESSION_TTL / 1000}s`);
});

// Graceful shutdown
process.on('SIGTERM', async () => {
    console.log('[browser-service] shutting down...');
    for (const id of sessions.keys()) await closeSession(id);
    process.exit(0);
});
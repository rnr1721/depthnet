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
 * @type {Map<string, {browser: import('playwright').Browser, page: import('playwright').Page, lastUsed: number}>}
 */
const sessions = new Map();

/**
 * Get or create a browser session for given sessionId.
 *
 * @param {string} sessionId
 * @returns {Promise<import('playwright').Page>}
 */
async function getSession(sessionId) {
    if (sessions.has(sessionId)) {
        const session = sessions.get(sessionId);
        session.lastUsed = Date.now();
        return session.page;
    }

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

    sessions.set(sessionId, { browser, page, lastUsed: Date.now() });

    console.log(`[session] created: ${sessionId} (total: ${sessions.size})`);

    return page;
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
 * Returns a plain object ready to be JSON-serialised and sent to the agent.
 *
 * @param {import('playwright').Page} page
 * @returns {Promise<object>}
 */
async function buildSnapshot(page) {
    return await page.evaluate(
        ({ textLimit, linksLimit }) => {
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

            // ── Links ───────────────────────────────────────────────────────
            const allLinks = Array.from(document.querySelectorAll('a[href]'));
            const links = allLinks
                .filter(a => {
                    const href = a.getAttribute('href');
                    const label = (a.innerText || a.textContent || '').trim();
                    return label.length > 0
                        && href
                        && !href.startsWith('#')
                        && !href.startsWith('javascript:');
                })
                .slice(0, linksLimit)
                .map((a, i) => {
                    const href = a.getAttribute('href');
                    const abs = href.startsWith('http')
                        ? href
                        : new URL(href, location.origin).href;
                    return {
                        text: (a.innerText || a.textContent || '').trim().slice(0, 80),
                        url: abs,
                        selector: `a:nth-of-type(${i + 1})`,
                    };
                });

            // ── Inputs ──────────────────────────────────────────────────────
            const inputs = Array.from(document.querySelectorAll('input, textarea, select'))
                .filter(el => {
                    const type = el.getAttribute('type') || '';
                    return !['hidden', 'submit', 'button', 'reset', 'image', 'checkbox', 'radio'].includes(type);
                })
                .slice(0, 10)
                .map(el => ({
                    type: el.tagName.toLowerCase() === 'textarea'
                        ? 'textarea'
                        : (el.getAttribute('type') || 'text'),
                    name: el.getAttribute('name') || el.getAttribute('id') || '',
                    placeholder: el.getAttribute('placeholder') || '',
                    selector: el.getAttribute('name')
                        ? `[name="${el.getAttribute('name')}"]`
                        : el.getAttribute('id')
                            ? `#${el.getAttribute('id')}`
                            : el.tagName.toLowerCase(),
                }));

            // ── Buttons ─────────────────────────────────────────────────────
            const buttons = Array.from(document.querySelectorAll('button, input[type=submit], input[type=button]'))
                .slice(0, 10)
                .map(el => ({
                    text: (el.innerText || el.textContent || el.getAttribute('value') || '').trim().slice(0, 60),
                    selector: el.getAttribute('id')
                        ? `#${el.getAttribute('id')}`
                        : el.getAttribute('name')
                            ? `[name="${el.getAttribute('name')}"]`
                            : 'button',
                }))
                .filter(b => b.text.length > 0);

            return { text, links, inputs, buttons };
        },
        { textLimit: SNAPSHOT_TEXT_LIMIT, linksLimit: SNAPSHOT_LINKS_LIMIT }
    );
}

// ── Action handlers ──────────────────────────────────────────────────────────

/**
 * @type {Record<string, (page: import('playwright').Page, args: object) => Promise<object>>}
 */
const actions = {

    /**
     * Open a URL and return a full snapshot.
     */
    async open(page, { url, timeout = 30000 }) {
        if (!url) throw new Error('url is required');

        await page.goto(url, { waitUntil: 'domcontentloaded', timeout });

        // Give JS-heavy pages a moment to settle
        await page.waitForTimeout(500);

        const title = await page.title();
        const finalUrl = page.url();
        const snapshot = await buildSnapshot(page);

        return { title, url: finalUrl, ...snapshot };
    },

    /**
     * Return a snapshot of the current page without navigation.
     */
    async snapshot(page) {
        const title = await page.title();
        const finalUrl = page.url();
        const snapshot = await buildSnapshot(page);

        return { title, url: finalUrl, ...snapshot };
    },

    /**
     * Click an element by CSS selector or visible text.
     */
    async click(page, { selector, timeout = 10000 }) {
        if (!selector) throw new Error('selector is required');

        // Try CSS selector first, then fall back to text match
        try {
            await page.click(selector, { timeout });
        } catch (_) {
            await page.getByText(selector, { exact: false }).first().click({ timeout });
        }

        await page.waitForTimeout(300);

        return { clicked: selector };
    },

    /**
     * Type text into an input field.
     */
    async type(page, { selector, text, delay = 30, timeout = 10000 }) {
        if (!selector) throw new Error('selector is required');
        if (text === undefined) throw new Error('text is required');

        await page.click(selector, { timeout });
        await page.fill(selector, text);

        return { typed: text, into: selector };
    },

    /**
     * Press a keyboard key (e.g. "Enter", "Tab", "Escape").
     */
    async press(page, { key }) {
        if (!key) throw new Error('key is required');
        await page.keyboard.press(key);
        await page.waitForTimeout(300);
        return { pressed: key };
    },

    /**
     * Wait for a selector to appear.
     */
    async wait(page, { selector, timeout = 15000 }) {
        if (!selector) throw new Error('selector is required');
        await page.waitForSelector(selector, { timeout });
        return { appeared: selector };
    },

    /**
     * Scroll the page down by a given amount of pixels.
     */
    async scroll(page, { pixels = 500 }) {
        await page.evaluate((px) => window.scrollBy(0, px), pixels);
        await page.waitForTimeout(200);
        return { scrolled: pixels };
    },

    /**
     * Go back in browser history.
     */
    async back(page, { timeout = 10000 }) {
        await page.goBack({ timeout });
        await page.waitForTimeout(300);
        return { navigated: 'back', url: page.url() };
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
        const page = action === 'ping' || action === 'close'
            ? null
            : await getSession(sessionId);

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
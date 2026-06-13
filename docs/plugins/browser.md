# Browser Plugin

The Browser plugin gives the agent a real, persistent web browser backed by [Playwright](https://playwright.dev/). The agent can open pages, click buttons, fill in forms, scroll, search the web, and read structured page snapshots — including on JavaScript-heavy sites and SPAs that plain HTTP requests can't handle.

What makes this different from a simple web fetch is **session persistence**: the browser session survives across thinking cycles. The agent can open a page in one cycle, reason about it, then come back and interact with it in the next — logged in, with the same browser state intact.

Each preset gets its own isolated browser session.

## Prerequisites

The Browser plugin requires the **browser-service** Docker container to be running. It is included in the Docker Compose setup under the `browser` profile. Start it alongside DepthNet:

```bash
make browser-enable
make restart
```

To disable it:

```bash
make browser-disable
make restart
```

## Setup

Enable the **Browser** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Browser Service URL** | URL of the Playwright service. Default: `http://browser-service:3001`. |
| **Enable direct search method** | Off by default. When off, the `search` command is hidden and the agent opens search engines as normal pages instead. Direct search-by-URL often trips captchas and confuses the agent, so leaving this off is usually more reliable. |
| **Request Timeout (seconds)** | How long to wait for a browser action to complete (10–120). Default: `60`. |
| **Allowed Domains** | Comma-separated whitelist. If set, only these domains can be visited. Leave empty to allow all. |
| **Blocked Domains** | Comma-separated blacklist of domains the agent is not allowed to open. |
| **Search Engine** | Which engine the `search` command uses (Google, Bing, DuckDuckGo, Brave). Only relevant when direct search is enabled. |

## How the agent sees a page: numbered snapshots

Instead of raw HTML, the browser returns a **structured, numbered snapshot**. Every interactive element — input, button, link — is given a number in `[brackets]`, and the agent acts on elements **by that number**. This is the core idea that makes the browser reliable for a model: it works with small numbers, not fragile CSS selectors it has to guess.

```
📄 Login - Example
🔗 https://example.com/login

── Content ──
Welcome back! Sign in to your account.

── Inputs ──
  [1] email (Enter your email)
  [2] password (Password)

── Buttons ──
  [3] Sign in

── Links ──
  [4] Forgot password?  →  https://example.com/forgot

── Scroll: 85%, more below ↓ ──
```

The agent then clicks `[3]` by writing `[browser click]3[/browser]`, types into `[1]` with `[browser type]1 | me@example.com[/browser]`, and so on.

Snapshots also include:

- **Current values** of input fields (so the agent can see what's already entered)
- A **scroll position** hint (`Scroll: 85%, more below ↓`) so the agent knows whether there's more content below
- A **dialog/modal warning** when a modal is open, so the agent knows to act inside it or close it first

## Automatic snapshots after actions

After any action that changes the page — `click`, `press`, `back`, `scroll`, and `type` with submit — the browser **automatically returns a fresh snapshot** of the resulting page. The agent doesn't need a separate `snapshot` call to see what happened: it acts and sees the consequence in the same cycle. This keeps interactions tight and reduces wasted cycles.

## Commands

Elements are referenced by their **number** from the snapshot (preferred). A CSS selector or `text=...` is also accepted where it makes sense.

| Command | Description |
|---|---|
| `[browser open]https://example.com[/browser]` | Open a URL |
| `[browser snapshot][/browser]` | Get a structured snapshot of the current page |
| `[browser click]3[/browser]` | Click element number 3 from the snapshot |
| `[browser type]2 | hello world[/browser]` | Type text into input number 2 (`number \| text`) |
| `[browser type]2 | mypassword | submit[/browser]` | Type, then press Enter to submit (`number \| text \| submit`) |
| `[browser press]Enter[/browser]` | Press a keyboard key |
| `[browser scroll]500[/browser]` | Scroll down by N pixels (negative to scroll up) |
| `[browser back][/browser]` | Navigate back |
| `[browser close][/browser]` | Close the browser session |
| `[browser search]query[/browser]` | Search the web (only when direct search is enabled) |

The `type` command also accepts a legacy JSON form — `{"selector":"...","text":"...","submit":true}` — for backward compatibility, but the `number | text` pipe form is simpler and preferred.

## A typical login flow

```
[browser open]https://example.com/login[/browser]
→ snapshot shows: [1] email, [2] password, [3] Sign in

[browser type]1 | me@example.com[/browser]
[browser type]2 | my-password | submit[/browser]
→ auto-snapshot shows the page after login
```

## How agents use it

- **Research tasks**: open multiple pages across cycles, extract and compare information
- **Form automation**: navigate to a page, fill in fields by number, submit, check the result
- **Monitoring**: open a page periodically and check for changes
- **Web search**: open a search engine as a page, type a query, follow result links to dig deeper
- **Authenticated sessions**: log in once, then interact with pages behind the login across subsequent cycles
- **Content management**: combined with DepthNet's memory layers and RAG, an agent can hold context about a site across visits — navigate an admin panel, recall what it did last time, and work as a persistent operator rather than a stateless one

## Notes

- The browser session is tied to the preset and persists until explicitly closed with `[browser close]`, evicted after inactivity (TTL), or the service restarts.
- The agent works with **element numbers**, which are assigned fresh on each snapshot. After the page changes, take a new snapshot before acting on numbers.
- Typing uses real keystroke events, so React/Vue-controlled inputs and live-validating login forms register the input correctly.
- New tabs/popups (e.g. OAuth "Sign in with…" flows) are followed automatically — the session switches to the newest tab.
- For simple page reads without JavaScript requirements, a plain HTTP request via the Sandbox plugin's shell commands may be faster and lighter.
- Domain allow/block lists are evaluated per request — the agent receives an error if it tries to open a blocked or non-whitelisted domain. Access control is the operator's responsibility: giving an agent a browser means letting it act on the web as a person would, so scope its domains and account access deliberately.
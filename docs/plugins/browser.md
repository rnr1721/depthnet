# Browser Plugin

The Browser plugin gives the agent a real, persistent web browser backed by [Playwright](https://playwright.dev/). The agent can open pages, click buttons, fill in forms, scroll, search the web, and read structured page snapshots — including on JavaScript-heavy sites and SPAs that plain HTTP requests can't handle.

What makes this different from a simple web fetch is **session persistence**: the browser session survives across thinking cycles. The agent can open a page in one cycle, reason about it, then come back and interact with it in the next — logged in, with the same browser state intact.

Each preset gets its own isolated browser session.

## Prerequisites

The Browser plugin requires the **browser-service** Docker container to be running. It is included in the Docker Compose setup under the `browser` profile. Start it alongside DepthNet:

```bash
# If using manager.sh, enable the browser profile before starting
```

Check your installation's Docker documentation for the exact steps.

## Setup

Enable the **Browser** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Browser Service URL** | URL of the Playwright service. Default: `http://browser-service:3001`. |
| **Request Timeout (seconds)** | How long to wait for a browser action to complete (10–120). Default: `60`. |
| **Allowed Domains** | Comma-separated whitelist. If set, only these domains can be visited. Leave empty to allow all. |
| **Blocked Domains** | Comma-separated blacklist of domains the agent is not allowed to open. |

## Commands

| Command | Description |
|---|---|
| `[browser open]https://example.com[/browser]` | Open a URL |
| `[browser search]best php frameworks 2026[/browser]` | Search the web |
| `[browser snapshot][/browser]` | Get a structured snapshot of the current page |
| `[browser click]text=Submit[/browser]` | Click an element by text or CSS selector |
| `[browser type]{"selector":"input[name=q]","text":"hello"}[/browser]` | Type into a form field |
| `[browser press]Enter[/browser]` | Press a keyboard key |
| `[browser scroll]500[/browser]` | Scroll down by N pixels |
| `[browser back][/browser]` | Navigate back |
| `[browser close][/browser]` | Close the browser session |

## Page snapshots

The `snapshot` command returns a structured summary of the current page — not raw HTML, but an agent-friendly text block with:

- Page title and URL
- Visible text content
- Available input fields with their selectors
- Buttons with their selectors
- Links with URLs

This gives the agent everything it needs to understand the page and decide what to interact with next.

## How agents use it

- Research tasks: open multiple pages across cycles, extract and compare information
- Form automation: navigate to a page, fill in fields, submit, check the result
- Monitoring: open a page periodically and check for changes
- Web search: use `[browser search]` to find current information, then follow links to dig deeper
- Authenticated sessions: log in once, then interact with pages behind the login across subsequent cycles

## Notes

- The browser session is tied to the preset and persists until explicitly closed with `[browser close]` or the service restarts.
- For simple page reads without JavaScript requirements, using a plain HTTP request via the Sandbox plugin's shell commands may be faster and lighter.
- Domain allow/block lists are evaluated per request — the agent receives an error if it tries to open a blocked or non-whitelisted domain.
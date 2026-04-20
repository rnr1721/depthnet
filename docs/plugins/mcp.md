# MCP Plugin

The MCP plugin connects the agent to external [Model Context Protocol](https://modelcontextprotocol.io/) servers, giving it access to tools hosted outside of DepthNet — GitHub, databases, APIs, or any other MCP-compatible service.

Each MCP server exposes a set of named tools. The agent can discover what tools are available and call them directly from its thinking cycle.

## Setup

MCP servers are configured per-preset. Go to the preset's **MCP Servers** tab in the UI and add server connections there. Each server needs a URL, a name, and a unique key (short identifier the agent uses to reference it).

Then enable the **MCP** plugin in the preset's plugin settings.

| Setting | Description |
|---|---|
| **Allow agent to connect servers** | When enabled, the agent can connect and disconnect MCP servers on its own using `[mcp connect]` and `[mcp disconnect]`. Disabled by default — servers are normally configured by the operator. |
| **Connection whitelist** | If set, the agent can only connect to domains listed here (one per line). Leave empty to allow any domain when agent-connect is enabled. |
| **Tools cache TTL (minutes)** | How long to cache the tools list fetched from each server (1–1440). Default: `60`. |

## Commands

**Discovering servers and tools:**

| Command | Description |
|---|---|
| `[mcp list][/mcp]` | List all connected servers and their available tools |
| `[mcp tools]server_key[/mcp]` | Fetch and list tools from a specific server (also refreshes the cache) |

**Calling tools:**

| Command | Description |
|---|---|
| `[mcp server_key]tool_name[/mcp]` | Call a tool with no arguments |
| `[mcp server_key]tool_name: {"key": "value"}[/mcp]` | Call a tool with JSON arguments |

The `server_key` is the short identifier configured for the server. The `tool_name` is exactly as listed by `[mcp tools]`.

**Agent-managed connections** (only when `allow_agent_connect` is enabled):

| Command | Description |
|---|---|
| `[mcp connect]{"url":"https://...","name":"Label","server_key":"key"}[/mcp]` | Connect a new MCP server |
| `[mcp disconnect]server_key[/mcp]` | Disconnect a server |

## Example

Given a GitHub MCP server connected with key `github`:

```
[mcp tools]github[/mcp]
```
→ Lists available tools like `list_repos`, `create_issue`, `get_file`, etc.

```
[mcp github]list_repos: {"owner": "rnr1721"}[/mcp]
```
→ Calls the `list_repos` tool with the given arguments and returns the result.

## How agents use it

MCP dramatically extends what an agent can do without any custom plugin code. Common use cases:

- Connecting to a GitHub MCP server to read issues, create PRs, or browse code
- Connecting to a database MCP server to run queries
- Connecting to any internal API exposed as an MCP server
- Using multiple MCP servers in the same preset for different services

The agent can use `[mcp list]` to orient itself at the start of a task, then call specific tools as needed across multiple cycles.

## Notes

- Tool lists are cached per server to avoid redundant network calls. Use `[mcp tools]server_key` to force a refresh and update the cache.
- Server health status is tracked automatically — a failed tool call marks the server as unhealthy until it succeeds again.
- Servers added by the agent via `[mcp connect]` are flagged as agent-added, which makes them easy to identify and review in the UI.
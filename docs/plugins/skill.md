# Skill Plugin

The Skill plugin gives the agent a persistent, structured knowledge base. Knowledge is organised into named **skills**, each containing any number of **items** — individual pieces of information. Items are indexed semantically, so the agent can search across all skills by meaning rather than exact keywords.

Think of it as the agent's personal wiki: stable, reusable knowledge that it builds up over time and can retrieve when relevant.

On top of the knowledge base, the plugin provides **lazy tool loading**: a skill can carry a set of tools that stay hidden from the agent until the skill is loaded — keeping the agent's tool list lean until the relevant capability is actually needed.

## How it differs from other memory types

| | Skill | Vector Memory | Journal | Workspace |
|---|---|---|---|---|
| **Best for** | Stable, reusable knowledge | Facts and insights | What happened | Active task state |
| **Structure** | Named skills with numbered items | Flat entries | Chronological entries | Key-value pairs |
| **Search** | TF-IDF semantic search | TF-IDF or embedding | TF-IDF | By key |
| **Persists** | ✓ | ✓ | ✓ | ✓ |

## Lazy tool loading

Each skill can have **tools** attached to it — the names of plugins that become available only while the skill is loaded. This lets you group related capabilities behind a skill and keep them out of the agent's tool list until needed.

For example, a "Code" skill might carry `terminal`, `code`, and `projectmap`. While the skill is not loaded, those three tools are hidden from the agent's tool schema; the agent only sees them (and can call them) after it loads the skill.

**Key principle — hiding is not blocking.** A hidden tool is only removed from what the agent is *shown*; it is never blocked from *executing*. This keeps the agent's attention focused without ever trapping it away from a capability it needs.

How it behaves:

- **Attaching tools activates the mechanism.** A preset with no skill-tools behaves exactly as before — nothing is hidden. As soon as any skill declares tools, those tools are gated behind loading.
- **Loading is explicit.** The agent brings a skill into context with a `load` command (see below). It is never loaded implicitly.
- **Tools ride along with knowledge.** Loading a skill injects its full content into the agent's context *and* reveals its tools. A skill with no tools is perfectly valid — loading it simply brings its knowledge into context.
- **Shared tools.** The same tool can be attached to several skills; it becomes visible as soon as *any* owning skill is loaded.
- **Auto-unload (hysteresis).** A loaded skill whose tools go unused for several cycles is automatically unloaded, keeping the tool list lean. Skills with no tools are never auto-unloaded — the agent unloads those itself when done.
- **Unknown tool names are ignored.** If a skill lists a tool that no longer exists (renamed or removed plugin), it is silently skipped — nothing breaks. This also means you can list a tool name before its plugin exists (e.g. a future custom tool).

## Setup

Enable the **Skill** plugin in your preset settings. Available options:

| Setting | Description |
|---|---|
| **Navigation console only** | When on, the agent can only load/unload/list skills — it cannot create or edit their content. Use this on presets where the Knowledge plugin manages skill content, so the agent has a single, unambiguous way to write knowledge. Off by default. |
| **Skill language** | Optionally force a language for all skill entries. The agent will receive an instruction to write in that language. |
| **Search results limit** | Maximum number of items returned per semantic search (1–20). Default: `5`. |

Attaching **tools** to a skill is done in the Skills manager (Admin → Skills): open or create a skill and enter a comma-separated list of plugin names, e.g. `terminal, code, projectmap`. Recognised names are highlighted; an unrecognised name is kept but marked inactive.

## Placeholder

Add this to the preset's system prompt to give the agent a permanent inventory of its skills:

```
[[skills]]
```

This injects a compact list of all skills, each showing its **load status** (loaded / not loaded) and, for skills with tools, which tools they carry. Skills that are not loaded include a hint on how to load them. This is the bridge that makes lazy loading work: the agent always knows which capabilities exist — even while their tools are hidden — and how to bring them in.

## Commands

Skills and items are addressed by number. The agent assigns numbers automatically when creating them. Loading commands also accept a skill's **title** (case-insensitive) instead of its number.

**Loading (navigation console — always available):**

| Command | Description |
|---|---|
| `[skill list][/skill]` | List all skills, showing which are loaded |
| `[skill load]1[/skill]` | Load skill #1 into context (reveals its tools, if any) |
| `[skill load]Code[/skill]` | Load a skill by title instead of number |
| `[skill unload]1[/skill]` | Unload skill #1 (re-hides its tools) |

When the preset has exactly one skill, `load` with no argument loads it; likewise `unload` with no argument unloads the only loaded skill.

**Managing skills (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill]PostgreSQL[/skill]` | Create an empty skill named "PostgreSQL" |
| `[skill]PostgreSQL \| Use EXPLAIN ANALYZE to inspect query plans[/skill]` | Create a skill and add the first item in one step |
| `[skill delete]1[/skill]` | Delete entire skill #1 |
| `[skill show]1[/skill]` | Show skill #1 with all its items |

**Managing items (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill add]1 \| item content[/skill]` | Add an item to skill #1 |
| `[skill update]1.2 \| new content[/skill]` | Update item 2 of skill 1 |
| `[skill delete]1.2[/skill]` | Delete item 2 of skill 1 |

**Searching (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill search]how to speed up slow queries[/skill]` | Find semantically relevant items across all skills |

> In `tool_calls` mode the same operations are exposed as a single `skill` tool with a `method` (`list`, `load`, `unload`, and — outside console mode — `execute`, `add`, `update`, `delete`, `show`, `search`) and a `content` argument. The tag examples above map directly onto that.

## How agents use it

Skills are best suited for knowledge the agent wants to *reuse* — not just recall once. Typical patterns:

- An agent working with a specific technology builds a "PostgreSQL" skill and adds tips as it discovers them
- A personal assistant agent maintains a "User preferences" skill and updates it when it learns something new
- A research agent accumulates findings into topic-based skills and searches them before tackling a related problem

With lazy tool loading, skills also become a way to organise **capabilities**, not just knowledge:

- A "Code" skill carries `terminal`, `code`, and `projectmap`; the agent loads it only when it actually needs to work with code, and its tool list stays lean the rest of the time
- A "Web" skill carries `browser`; loaded only when the agent needs to browse

The key difference from vector memory is structure: skills group related items together under a meaningful name, making the knowledge base easier to navigate and maintain over time — and, with tools attached, a way to bring whole sets of capabilities in and out of focus on demand.

## Relationship with the Knowledge plugin

The Knowledge plugin can also write and read skill *content* (as one of its unified memory types). It does **not** load or unload skills — that is always done through this plugin's navigation console. The two complement each other: Knowledge is the content channel, the Skill console is the load channel. When Knowledge is enabled, turn on **Navigation console only** here so the agent has a single, unambiguous way to write skill content (Knowledge) and a single way to load it (the console).
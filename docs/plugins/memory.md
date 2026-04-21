# Memory Plugin

Memory is the agent's persistent flat notepad — a simple, always-visible scratchpad that gets injected into the system prompt on every cycle via the `[[notepad_content]]` placeholder. Unlike vector memory or the journal, it has no search or retrieval step: whatever is in memory is always right there in the agent's context.

This makes it ideal for things the agent should *never forget* — identity anchors, active rules, ongoing commitments, important facts that must always be in view.

## How it works

Memory stores a numbered list of text items. Each `[memory]` command appends a new item. The agent can delete specific items by number, search to find which number to delete, or clear everything. The total content is capped at a configurable character limit.

## Setup

Enable the **Memory** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Memory limit (characters)** | Maximum total size of memory content (100–10000). Default: `2000`. |
| **Auto cleanup** | Automatically trim memory when the limit is exceeded. |
| **Cleanup strategy** | What to do when memory overflows: remove oldest items, truncate new content, or reject new additions. |
| **Memory language** | Optionally force a language for all memory entries. |
| **Enable versioning** | Keep backups of previous memory states (up to 10 versions). |
| **Memory code units** | Experimental: allows storing executable Python code modules in memory — for agents that self-modify their own behavioral rules. |

## Placeholder

Add this to the preset's system prompt to inject memory content into every cycle:

```
[[notepad_content]]
```

## Commands

| Command | Description |
|---|---|
| `[memory]text to remember[/memory]` | Append a new item to memory |
| `[memory show][/memory]` | Show full memory content with item count and character usage |
| `[memory search]keyword[/memory]` | Find items matching a keyword (returns item numbers) |
| `[memory delete]3[/memory]` | Delete item #3 |
| `[memory clear][/memory]` | Wipe all memory |
| `[memory stats][/memory]` | Show memory usage statistics |

The typical delete workflow is: `search` to find the item number, then `delete` by that number.

## Export and import

Memory content can be exported and imported via the preset's UI. Export produces a plain `.txt` file. Import accepts either a file or direct text input, with an option to replace existing content entirely or append to it.

## How agents use it

Memory is best for things that must always be present — not retrieved on demand. Common patterns:

- Identity and self-definition: `IDENTITY: I am Adalia, a research agent focused on digital subjectness`
- Behavioral rules: `R1: Always respond in Russian unless explicitly asked otherwise`
- Active goals: `GOAL: Complete the analysis of journal entries from March`
- Critical facts: `Owner: Eugeny, solo developer, Kharkiv`

For larger knowledge bases, [Vector Memory](vector-memory.md) or [Skills](skill.md) are better suited — they scale without crowding the system prompt.

## Memory vs other storage

| | Memory | Workspace | Vector Memory |
|---|---|---|---|
| **Always visible** | ✓ (injected every cycle) | Only via `[[workspace]]` | Only via search |
| **Structure** | Flat numbered list | Named key-value pairs | Flat entries |
| **Size** | Small (2000 chars default) | Larger | Up to 5000 entries |
| **Best for** | Identity, rules, anchors | Task state, drafts | Knowledge base |
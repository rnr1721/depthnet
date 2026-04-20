# Workspace Plugin

Workspace is a persistent key-value scratchpad that survives across thinking cycles and sessions. Unlike a simple notepad, it lets the agent maintain multiple independent named entries — plans, drafts, intermediate conclusions, working variables — each readable and updatable on its own.

The full workspace content is automatically injected into the agent's system prompt via the `[[workspace]]` placeholder, so the agent always sees its current state at the start of every cycle.

## Setup

Enable the **Workspace** plugin in your preset settings. Optionally, you can set a language for workspace entries — the agent will receive an instruction to write in that language.

## Commands

The agent manages the workspace using tags in its output (or via tool calls if the preset runs in that mode).

| Command | Description |
|---|---|
| `[workspace set]key: value[/workspace]` | Create or overwrite a key |
| `[workspace append]key: value[/workspace]` | Append text to an existing key |
| `[workspace get]key[/workspace]` | Read a single key |
| `[workspace delete]key[/workspace]` | Delete a single key |
| `[workspace list][/workspace]` | List all keys (without values) |
| `[workspace clear][/workspace]` | Wipe the entire workspace |

The `key: value` format is required for `set` and `append` — key and value are separated by a colon.

## How agents use it

Typical scenarios:

- Tracking the current task: `current_task: analyzing last week's logs`
- Storing intermediate conclusions across plan steps
- Building up a document draft incrementally via `append`
- Keeping a list of sources, contacts, or states that need to persist between cycles

Workspace is best suited for **structured** working state. For free-form thoughts and episodic memories, Journal or vector memory are a better fit.

## Placeholder

To make the agent aware of the workspace content at every cycle, add this to the preset's system prompt:

```
[[workspace]]
```

Without this placeholder the agent can still read and write workspace entries via commands, but won't see the full state automatically on each cycle.
# Switch Plugin (Conditional Prompt Blocks)

The Switch plugin lets the agent swap named text blocks into a placeholder inside its own system prompt — without switching the entire preset prompt. Think of it as a context-aware dial: the agent detects what mode of operation is needed and activates the matching block for the next cycle.

Unlike the Mode plugin (which replaces the whole prompt), Switch only changes one designated section. The rest of the prompt stays identical.

## Use case

A preset has a fixed system prompt, but one section should adapt depending on what the agent is doing:

- `cautious` — careful, double-checks assumptions, asks before acting
- `analytical` — structured reasoning, step-by-step breakdown
- `grounding` — anchors the agent to reality, useful when interaction feels ambiguous
- `creative` — exploratory, associative, fewer constraints

The agent assesses the situation and switches the active block autonomously. For example, it might detect unusual user behavior, activate `grounding`, handle the interaction carefully, then switch back to `analytical` for the next task.

## Setup

Enable the **Switch** plugin in your preset settings, then add named blocks via the **Prompt Switches** list in the plugin config. Each block has a **code** (used in commands) and **content** (the text injected into the prompt).

| Setting | Description |
|---|---|
| **Allow switch inspection** | Let the agent read block contents via `[switch get]`. Off by default — the agent sees codes only. |
| **Allow switch editing** | Let the agent create or delete blocks via `[switch write]` / `[switch remove]`. Off by default. |

## Placeholders

Add these to the preset's system prompt where you want the active block to appear:

```
[[active_switch]]
```

Injects the full content of the currently active block. If no block is active, injects nothing.

```
[[active_switch_code]]
```

Injects just the code of the active block — useful for self-awareness or debugging in the prompt.

## Commands

| Command | Description |
|---|---|
| `[switch]cautious[/switch]` | Activate the block with code `cautious` |
| `[switch list][/switch]` | List all available block codes for this preset |
| `[switch current][/switch]` | Show the currently active block code |
| `[switch get]cautious[/switch]` | Read the content of a block *(requires allow_inspect)* |
| `[switch write]code \| content[/switch]` | Create or overwrite a block *(requires allow_write)* |
| `[switch remove]cautious[/switch]` | Delete a block *(requires allow_write)* |

The new block takes effect from the **next thinking cycle** — the current cycle always completes under the block that was active when it started.

## Notes

- Block codes are defined in the plugin config by the user. The agent sees only codes — content is opaque unless `allow_inspect` is enabled. This lets you change block content without the agent "knowing" it changed.
- If the agent tries to activate a code that doesn't exist, it receives an error with a list of valid codes.
- With `allow_write` enabled, the agent can build its own blocks at runtime — useful for agents that need to self-configure their operational context.
- The active block persists across cycles — once activated, it stays active until changed.
- Works well combined with **Journal** (log why a switch happened) or **Being** (reflect identity alongside operational mode).
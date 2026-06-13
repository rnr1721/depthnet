# Spawn Plugin

The Spawn plugin gives an agent the ability to create, manage, and communicate with ephemeral child presets ("spawns") at runtime — without any human intervention. The agent writes a system prompt, spawns an instrument, delegates a task to it via handoff, and kills it when done.

Spawns are pure instruments by default: no identity, no memory, no personality plugins. They inherit the parent's engine and operational settings, but nothing that makes the parent a subject. The parent agent owns all its spawns — they are deleted automatically when the parent preset is deleted.

This is the LLM-driven alternative to the deterministic orchestrator: flexible, dynamic, and model-directed. The agent itself decides what tools to create, what prompts to give them, and when to clean them up.

## Setup

Enable the **Spawn** plugin in your preset settings:

| Setting | Description |
|---|---|
| **Enable Spawn Plugin** | Allow the agent to create and manage ephemeral child presets |
| **Allow Reset** | Allow the agent to wipe a spawn's runtime data without deleting it |
| **Allow Kill All** | Allow the agent to delete all its spawns at once |
| **Max active spawns** | Maximum number of simultaneous spawns (0 = unlimited, default: 5) |

## Commands

**Creating and listing spawns:**

| Command | Description |
|---|---|
| `[spawn spawn]slug: my_tool`<br>`prompt: system prompt[/spawn]` | Create a new spawn with the given slug and system prompt |
| `[spawn list][/spawn]` | List all active spawns owned by this preset |

The `slug` must be lowercase letters, digits, and underscores only (e.g. `json_validator`, `risk_analyzer`). The resulting `preset_code` will be `spawn_{parentId}_{slug}`, e.g. `spawn_3_json_validator`. Use this code in all subsequent commands.

**Reading and editing spawn prompts:**

| Command | Description |
|---|---|
| `[spawn read]preset_code[/spawn]` | Read the current system prompt of a spawn |
| `[spawn edit]preset_code`<br>`search: exact text`<br>`replace: new text[/spawn]` | Replace a specific phrase in the spawn's prompt |

⚠️ **Always call `read` before `edit`.** The `search` string must match the current prompt exactly — use the output of `read` as your source, not text you wrote yourself.

**Delegating tasks:**

| Command | Description |
|---|---|
| `[spawn send]preset_code:task message[/spawn]` | Hand off control to a spawn with a task message |

`send` transfers control to the spawn for one cycle — the spawn receives the message as its input and responds. This is how the parent delegates work.

**Lifecycle management:**

| Command | Description |
|---|---|
| `[spawn reset]preset_code[/spawn]` | Wipe spawn's runtime data (messages, memory, etc.) |
| `[spawn reset]preset_code`<br>`prompt: new prompt[/spawn]` | Reset and replace prompt in one step |
| `[spawn kill]preset_code[/spawn]` | Delete a spawn |
| `[spawn killall][/spawn]` | Delete all spawns (requires Allow Kill All setting) |

## Placeholder

`[[active_spawns]]` — injected into the system prompt when the plugin is enabled. Lists all currently active spawns owned by this agent (preset code and name). Useful for agents that need to track their live instruments across cycles.

## How agents use it

The typical workflow is: **spawn → send → kill**.

```
# 1. Create an instrument for a specific task
[spawn spawn]slug: json_validator
prompt: You are a JSON validator. Receive a JSON string, return only "valid" or "invalid" with a one-line reason.[/spawn]

# 2. Delegate the task
[spawn send]spawn_3_json_validator:{"name": "Alice", "age": 30}[/spawn]

# 3. Clean up when done
[spawn kill]spawn_3_json_validator[/spawn]
```

More advanced patterns:

- **Parallel instruments** — spawn multiple tools (analyzer, formatter, validator) and send tasks to each in sequence
- **Prompt iteration** — use `read` + `edit` or `reset` with a new prompt to refine a spawn's behaviour based on its output
- **Persistent instruments** — keep a spawn alive across cycles when the task spans multiple steps; use `reset` to clear its context between uses without recreating it

## Spawns vs deterministic orchestrator

| | Spawn Plugin | Orchestrated Mode |
|---|---|---|
| Who decides routing | The LLM | Deterministic PHP code |
| Task decomposition | Model-driven | Planner preset + role definitions |
| Reliability | Flexible, less predictable | Structured, observable |
| Setup | Zero config — agent creates tools on demand | Roles and validators defined upfront |
| Best for | Exploratory, open-ended tasks | Production workflows, repeatable pipelines |

Both can coexist in the same installation — use orchestrated mode for reliable workflows and spawn for dynamic, model-directed tasks.

## Notes

- Spawns inherit engine settings (model, temperature, context limit) from the parent unless overridden in the `spawn` command via `engine:` or `context_limit:` parameters
- The following plugins are always disabled on spawns regardless of parent config: `being`, `rhythm`, `heart`, `ontology`, `spawn` (no recursive spawning)
- Spawns are visible in the preset list in the UI with a `⚙ spawn` badge and grouped under their parent preset
- The parent can read and edit a spawn's system prompt at any time via `read` and `edit` — spawns are fully transparent to their creator
- Deleting the parent preset cascades to all its spawns automatically
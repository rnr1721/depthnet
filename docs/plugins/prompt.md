# Mode Plugin (Prompt Switching)

The Mode plugin lets the agent switch its own active system prompt during a session. Each "mode" is a named prompt variant configured for the preset — the agent can move between them autonomously based on what it's doing, effectively changing its reasoning style, personality, or focus on the fly.

The switch takes effect from the **next thinking cycle**, so the agent can plan ahead: finish what it's doing under the current mode, then switch for the next one.

## Use case

A preset can have multiple prompt variants set up for different purposes:

- `default` — balanced everyday thinking
- `critic` — skeptical, looks for flaws and edge cases
- `creative` — free-form, associative, exploratory
- `focus` — terse, task-only, no digressions

The agent decides when to shift modes based on its own assessment of the situation. For example, after generating a plan it might switch to `critic` to stress-test it, then back to `default` to execute.

## Setup

Enable the **Mode** plugin in your preset settings, then create multiple prompt variants for the preset. Each variant needs a unique **code** (the name the agent uses to switch to it) and optionally a description.

| Setting | Description |
|---|---|
| **Log mode switches** | Write a log entry each time the agent switches mode. Useful for observing autonomous behaviour. |

## Placeholder

Add this to the preset's system prompt so the agent always knows which mode it's currently in:

```
[[current_mode]]
```

## Commands

| Command | Description |
|---|---|
| `[mode]critic[/mode]` | Switch to the mode with code `critic` |
| `[mode list][/mode]` | List all available modes for this preset |
| `[mode current][/mode]` | Show the currently active mode |

The new prompt takes effect from the next cycle — the current cycle always completes under the mode that was active when it started.

## Notes

- Mode codes are defined when creating prompt variants for the preset. The agent needs to know them — it's a good idea to list the available modes and their purposes in the system prompt itself.
- If the agent tries to switch to a code that doesn't exist, it receives an error with a list of valid codes.
- This plugin works well combined with agent self-awareness plugins like Being or Journal — the agent can record why it switched modes and reflect on the pattern over time.
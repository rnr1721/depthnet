# Agent Plugin

The Agent plugin gives the agent control over its own lifecycle — it can pause and resume its thinking cycles, check its current status, and request additional thinking steps. These are the tools the agent uses to manage itself.

For sending messages to the user or delegating to another preset, see the [Speak plugin](speak.md).

## Setup

Enable the **Agent** plugin in your preset settings and configure which capabilities to allow:

| Setting | Description |
|---|---|
| **Allow pause** | The agent can pause its own thinking cycles. |
| **Allow resume** | The agent can resume its own thinking cycles. |
| **Allow turn** | The agent can request one additional thinking step. |
| **Require reason** | When enabled, the agent must provide a reason when pausing or resuming. |
| **Log actions** | Write a log entry for each lifecycle action. |

## Commands

| Command | Description |
|---|---|
| `[agent pause][/agent]` | Pause the agent's thinking loop |
| `[agent pause]reason here[/agent]` | Pause with an explicit reason |
| `[agent resume][/agent]` | Resume the thinking loop |
| `[agent status][/agent]` | Check whether the agent is active or paused |
| `[agent turn][/agent]` | Schedule one additional thinking step without entering a full loop |

## How agents use it

- An agent pauses itself when it determines it has nothing meaningful to do until something changes — rather than spinning uselessly
- An agent uses `[agent turn]` when it needs one more cycle to finish a task but does not want to enter a continuous loop — for example after sending a speak message while paused, or when processing a handoff response that requires a follow-up step

## Notes

- The `[[agent]]` placeholder (registered automatically when the plugin is enabled) injects the current agent status into the system prompt — useful for agents that need to be aware of whether they are in loop or single mode.
- `[agent turn]` is a no-op when the agent is already in an active loop — the next cycle is already scheduled, so dispatching another is unnecessary. The command returns an informational message in that case.
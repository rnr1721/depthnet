# Agent Plugin

The Agent plugin gives the agent control over its own lifecycle — it can pause and resume its thinking cycles, check its current status, send visible messages to the user, and hand off control to another preset. These are the tools the agent uses to manage itself and communicate directly.

## Setup

Enable the **Agent** plugin in your preset settings and configure which capabilities to allow:

| Setting | Description |
|---|---|
| **Allow pause** | The agent can pause its own thinking cycles. |
| **Allow resume** | The agent can resume its own thinking cycles. |
| **Allow handoff** | The agent can delegate to another preset. |
| **Require reason** | When enabled, the agent must provide a reason when pausing or resuming. |
| **Log actions** | Write a log entry for each lifecycle action. |

## Commands

**Lifecycle control:**

| Command | Description |
|---|---|
| `[agent pause][/agent]` | Pause the agent's thinking loop |
| `[agent pause]reason here[/agent]` | Pause with an explicit reason |
| `[agent resume][/agent]` | Resume the thinking loop |
| `[agent status][/agent]` | Check whether the agent is active or paused |

**Communicating with the user:**

| Command | Description |
|---|---|
| `[agent speak]I have a question for you...[/agent]` | Send a visible message to the user |

The `speak` command is the primary way an autonomous agent surfaces something to the user during an unattended run. Everything else the agent thinks and does stays internal — `speak` is what becomes visible in the chat.

**Handoff to another preset:**

| Command | Description |
|---|---|
| `[agent handoff]analyst[/agent]` | Transfer control to the preset with code `analyst` |
| `[agent handoff]analyst:Please review these findings[/agent]` | Transfer with a message |

Handoff routes the current conversation to another preset for the next cycle. The target preset must have handoff transfers allowed in its settings. This is how multi-agent pipelines are built in free-form mode — one preset hands off to another, which may hand off further.

## How agents use it

- An agent working autonomously over many cycles uses `[agent speak]` when it has something to report, needs user input, or has completed a significant task
- An agent pauses itself when it determines it has nothing meaningful to do until something changes — rather than spinning uselessly
- Handoff enables specialisation: a planner preset drafts an approach, then hands off to an executor preset to carry it out

## Notes

- The `[[agent]]` placeholder (registered automatically when the plugin is enabled) injects the current agent status into the system prompt — useful for agents that need to be aware of whether they are in loop or single mode.
- In orchestrated mode, task routing is handled by the orchestrator automatically — handoff is primarily for free-form multi-agent workflows.
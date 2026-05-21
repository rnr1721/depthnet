# Speak Plugin

The Speak plugin is the agent's outbound communication channel. It handles two things: sending visible messages to the user (or any configured interlocutor), and delegating control to another preset. These are intentionally the same tool — speaking is always an action, and the agent can speak freely within any cycle alongside other tools.

## Setup

Enable the **Speak** plugin in your preset settings:

| Setting | Description |
|---|---|
| **Interlocutor label** | How the default output channel is referred to in instructions. Defaults to `interlocutor`. Can be set to a name, role, or any label that fits the preset's context. |
| **Allow handoff** | The agent can delegate tasks to other presets. |
| **Additional instructions (system prompt)** | Extra notes appended to speak instructions in the system prompt. Leave empty to skip. |
| **Additional instructions (tool schema)** | Extra notes appended to the tool schema description in tool_calls mode. Leave empty to skip. |

## Commands

**Send a message to the interlocutor:**

| Command | Description |
|---|---|
| `[speak]Your message here[/speak]` | Send a visible message to the configured interlocutor (default output channel) |

**Delegate to another preset:**

| Command | Description |
|---|---|
| `[speak analyst]Please review these findings[/speak]` | Delegate to the preset with code `analyst`, passing a message |
| `[speak analyst][/speak]` | Delegate without a message |

In tool_calls mode the same operations use a single `content` argument:

| Call | Description |
|---|---|
| `speak(content: "Your message")` | Send to interlocutor |
| `speak(content: "analyst:Please review these findings")` | Delegate to preset `analyst` |

## How agents use it

- An agent working autonomously uses `[speak]` when it has something to report, needs user input, or has completed a significant task
- Speaking does not end the cycle — the agent can write to memory, run a terminal command, and speak all in the same cycle
- Handoff enables specialisation: a planner preset drafts an approach, then delegates to an executor preset to carry it out. The target preset must have handoff transfers allowed in its settings.

## The `{{speak_targets}}` placeholder

When the Speak plugin is enabled, it registers a `{{speak_targets}}` placeholder that injects a block into the system prompt listing all available targets:

```
[SPEAK_TARGETS]
[speak]message[/speak]              →  interlocutor (default output channel)
[speak analyst]message[/speak]      →  Analyst — data analysis specialist
[speak planner]message[/speak]      →  Planner — task decomposition
[/SPEAK_TARGETS]
```

In tool_calls mode the same block uses function call syntax. Add `{{speak_targets}}` to your system prompt wherever you want the agent to have a current view of who it can reach.

## Notes

- The target preset for handoff must have **Allow handoff to** enabled in its preset settings.
- In tool_calls mode, `preset_code:message` detection is unambiguous — if the part before the first `:` matches an existing preset code, it is treated as a handoff. Otherwise the full content is delivered to the interlocutor as-is.
- In orchestrated mode, task routing is handled by the orchestrator automatically — handoff via Speak is primarily for free-form multi-agent workflows.
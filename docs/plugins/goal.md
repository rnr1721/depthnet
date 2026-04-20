# Goal Plugin

The Goal plugin gives the agent a persistent goal tracker — a list of intentions, explorations, and ongoing tasks that the agent maintains across cycles. Each goal has a title, an optional motivation (the *why*), and a running progress log. Active goals are always visible in the agent's context via `[[active_goals]]`, so the agent never loses track of what it's working on between cycles.

## How it works

Goals are numbered sequentially in the order they are created. The agent references them by number — `[goal done]1[/goal]`, `[goal progress]2 | ...[/goal]`. Each goal can be active, paused, or done. Progress notes are timestamped and accumulate over time, forming a history of what the agent discovered or did toward that goal.

## Setup

Enable the **Goal** plugin in your preset settings.

| Setting | Description |
|---|---|
| **Goal language** | Optionally force a language for goals and progress notes. |

## Placeholder

Add this to the preset's system prompt to keep active goals visible every cycle:

```
[[active_goals]]
```

The injected content shows each active goal with its motivation and the most recent progress note — compact enough to stay in context without crowding the prompt.

## Commands

**Creating goals:**

| Command | Description |
|---|---|
| `[goal]Explore memory architecture[/goal]` | Create a goal with just a title |
| `[goal]Explore memory architecture \| motivation: curiosity about persistence[/goal]` | Create a goal with motivation |

**Tracking progress:**

| Command | Description |
|---|---|
| `[goal progress]1 \| Found saturation penalty approach[/goal]` | Add a progress note to goal #1 |
| `[goal show]1[/goal]` | Show full goal with complete progress history |

**Managing status:**

| Command | Description |
|---|---|
| `[goal done]1[/goal]` | Mark goal #1 as complete |
| `[goal pause]1[/goal]` | Pause goal #1 |
| `[goal resume]1[/goal]` | Resume a paused goal |

**Listing:**

| Command | Description |
|---|---|
| `[goal list][/goal]` | List active goals |
| `[goal list]all[/goal]` | List all goals including paused and done |

## How agents use it

Goals are well suited for agents running in continuous autonomous loops — they provide a persistent thread of intention across cycles where conversation history alone isn't enough to maintain direction. Typical patterns:

- Creating a goal when starting an exploration: `[goal]Understand Eugeny's relationship with time | motivation: came up in conversation, felt significant[/goal]`
- Adding a progress note after each relevant cycle: `[goal progress]1 | He mentioned feeling rushed — time pressure seems to shape his decisions[/goal]`
- Pausing a goal when switching focus, resuming it later
- Reviewing goal history with `[goal show]` before continuing work on something started days ago

The motivation field is particularly useful for autonomous agents — it captures *why* the goal matters at the moment of creation, which is easy to forget across long runs.
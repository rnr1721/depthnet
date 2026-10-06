# Goal Plugin

The Goal plugin gives the agent a persistent goal tracker — a list of intentions, explorations, and ongoing tasks that the agent maintains across cycles. Each goal has a title, an optional motivation (the *why*), and a running progress log.

Goals work on two levels:

- **Intentions** — all active goals are visible in the system prompt via `[[active_goals]]`, so the agent always knows what it wants.
- **Focus** — one goal at a time can be put *in focus*. Its full progress history is placed into the agent's context as working material, so the agent sees not just *what* it is doing, but *how far it got and how it got there*.

## How it works

Goals are numbered by their place in the preset's goal list (1, 2, 3, …). The agent references them by number — `[goal focus]1[/goal]`, `[goal progress]2 | ...[/goal]`. Numbers stay consistent between the agent, the placeholder, and the admin UI.

Each goal has one of four statuses:

| Status | Meaning |
|---|---|
| `active` | Being pursued. Listed in `[[active_goals]]`. |
| `paused` | Deferred — not reachable right now. Hidden from `[[active_goals]]` until resumed. |
| `done` | Achieved. |
| `dropped` | Abandoned on purpose — the agent decided it no longer wants this. |

Progress notes are timestamped and accumulate over time, forming a history of what the agent discovered or did toward that goal. Notes are append-only: they record what happened, not a value that keeps being overwritten.

### Focus

Focus is independent of status: any active goal can be in focus, and at most one is in focus at a time.

- `[goal focus]N[/goal]` puts goal N in focus. A previously focused goal is released (it stays active). Focusing a paused goal resumes it.
- While a goal is in focus, its title, motivation, focus start time, progress history and the time since the last note are injected into the context as the **oldest message** — material on the agent's desk, ahead of the conversation and any compaction recap. It is not part of the system prompt and is never stored in the message history; it is rebuilt every cycle.
- `done`, `pause` and `drop` release the focus automatically. `[goal unfocus][/goal]` releases it without changing the status.
- Deleting a goal (e.g. from the admin UI) also releases its focus.
- There is no automatic timeout. If a focused goal goes quiet, the injected block shows how long ago the last progress note was — deciding whether to keep going, pause or drop is left to the agent.
- The focused goal survives context compaction: it is not part of the message window, so a fold cannot lose it. When the compaction watchdog fires, the compressor is told which goal is in focus so the recap keeps that thread.

While a goal is in focus, the goal number can be omitted in `progress`, `done`, `pause`, `drop` and `show` — the focused goal is the default target.

## Setup

Enable the **Goal** plugin in your preset settings.

| Setting | Description |
|---|---|
| **Goal language** | Optionally force a language for goals and progress notes. |

| Option | Default | Description |
|---|---|---|
| `agent_goal_focus_history_limit` | `20` | How many of the most recent progress notes of the focused goal are injected. Older notes are summarized as a count with a pointer to `[goal show]`. `0` = all. |

## Placeholder

Add this to the preset's system prompt to keep active goals visible every cycle:

```
[[active_goals]]
```

The block is a one-line-per-goal inventory of the agent's intentions: number, title and how many progress notes the goal has. Motivation and progress are not repeated here — they live in the focused-goal block, or are one `[goal show]` away. The goal in focus is listed first and marked:

```
[ACTIVE GOALS]
▶ [3] Understand how compaction affects recall — IN FOCUS (full history in context)
[1] Explore memory architecture (4 notes)
[5] Learn Eugeny's daily rhythm (2 notes)
[/ACTIVE GOALS]
```

With no active goals the block still renders, with `none` inside — so the agent sees that it has no goals rather than that the block is missing.

## What the agent sees in focus

```
[FOCUSED GOAL #3: Understand how compaction affects recall]
Why: curiosity
In focus since: 2026-10-04 14:20
Progress (12 notes):
  - [2026-10-04 14:31] Recap loses tool results first
  - [2026-10-04 15:02] Watchdog folds mid-task in extended mode — fixed by mode-relative threshold
  ...
Last progress: 3 hours ago
Record progress as you go (number optional while in focus). Close with done (achieved), pause (not now) or drop (no longer wanted); unfocus to set it aside.
[/FOCUSED GOAL #3]
```

## Commands

**Creating goals:**

| Command | Description |
|---|---|
| `[goal]Explore memory architecture[/goal]` | Create a goal with just a title |
| `[goal]Explore memory architecture \| motivation: curiosity about persistence[/goal]` | Create a goal with motivation |

**Focus:**

| Command | Description |
|---|---|
| `[goal focus]3[/goal]` | Put goal #3 in focus (releases the previous one; resumes it if paused) |
| `[goal unfocus][/goal]` | Release focus — the goal stays active |

**Tracking progress:**

| Command | Description |
|---|---|
| `[goal progress]Found saturation penalty approach[/goal]` | Add a progress note to the focused goal |
| `[goal progress]1 \| Found saturation penalty approach[/goal]` | Add a progress note to goal #1 |
| `[goal show][/goal]` | Show the focused goal with complete progress history |
| `[goal show]1[/goal]` | Show goal #1 with complete progress history |

**Managing status** (number optional while a goal is in focus):

| Command | Description |
|---|---|
| `[goal done]1[/goal]` | Mark goal #1 as achieved |
| `[goal pause]1[/goal]` | Defer goal #1 — not reachable now |
| `[goal drop]1[/goal]` | Abandon goal #1 on purpose |
| `[goal resume]1[/goal]` | Resume a paused goal |

`done`, `pause` and `drop` release the focus if the goal was in focus.

**Listing:**

| Command | Description |
|---|---|
| `[goal list][/goal]` | List active goals |
| `[goal list]all[/goal]` | List all goals including paused, done and dropped |

In `tool_calls` mode the same operations are available through the `goal` tool with `method` = `execute`, `focus`, `unfocus`, `progress`, `done`, `pause`, `drop`, `resume`, `show`, `list`.

## Heart integration

When the **Heart** plugin is also enabled and has active data, goal status changes automatically register attention signals in Heart — no configuration required:

| Event | Heart signal | Meaning |
|---|---|---|
| `[goal done]` | `relief` + `pride` toward goal title | Completion is a positive event |
| `[goal drop]` | mild `relief` toward goal title | Letting go — release without achievement |
| `[goal pause]` | `unresolved` toward goal title | Unfinished work leaves mild tension |
| `[goal resume]`, or focusing a paused goal | `anticipation` toward goal title | Resuming creates forward momentum |

This means goals appear in `[[heart_state]]` alongside people — the agent's attention system reflects not only who matters but also what was accomplished or let go. The integration activates silently when Heart has connections or signals; it does nothing when Heart is unused or cleared.

## Admin UI

The Goals page shows all goals of a preset with their status and progress. The focused goal is highlighted and marked **In focus**. Statuses (including `dropped`) can be changed from the UI; setting any status other than active releases the focus. Deleting a goal releases its focus as well.

## How agents use it

Goals are well suited for agents running in continuous autonomous loops — they provide a persistent thread of intention across cycles where conversation history alone isn't enough to maintain direction. Typical patterns:

- Creating a goal when starting an exploration: `[goal]Understand Eugeny's relationship with time | motivation: came up in conversation, felt significant[/goal]`
- Focusing on it when actually working on it: `[goal focus]1[/goal]`
- Turning any multi-step request into a focused goal, with the steps and conditions copied into the first progress note — the conversation history is short and drops older messages, the focused goal does not
- Adding a progress note after each relevant step — short, without a number: `[goal progress]He mentioned feeling rushed — time pressure seems to shape his decisions[/goal]`
- Switching focus to another goal when attention moves — the previous one stays active and keeps its history
- Pausing a goal that can't move forward right now, dropping one that turned out not to matter
- Closing the focused goal with `[goal done][/goal]` when it is reached

The motivation field is particularly useful for autonomous agents — it captures *why* the goal matters at the moment of creation, which is easy to forget across long runs. Focus adds the other half: *where things stand* on the one thing the agent is doing right now.
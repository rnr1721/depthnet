# Mood Plugin

The Mood plugin gives an agent a persistent **emotional state vector** — a weighted mix of arbitrary states that decay over cycles, reinforce on attention, and coexist simultaneously.

This is not a tone switcher. There are no preset roles like "playful" or "analytical" to perform. Instead, the agent has something closer to an internal weather — states that emerge, intensify, fade, and mix based on what actually happens in its reasoning cycles.

The current state is exposed via the `[[mood]]` placeholder and persists across thinking cycles.

## Core concepts

**State vector** — mood is not a single value but a map of named states, each with its own intensity (0.0–1.0). Multiple states coexist simultaneously:

```
focus(0.9), curiosity(0.7), melancholy(0.3)
```

**Decay per cycle** — each state loses intensity every cycle at its own rate. States that fall below the minimum threshold are pruned automatically. Without reinforcement, states fade.

**Reinforcement** — calling `[mood feel]` on an existing state increases its intensity (capped at 1.0). New states are added at the specified intensity.

**Autobeat** — decay advances automatically on each `[mood feel]` call (once per minute per preset). The agent doesn't need to manage timing manually. Manual `[mood beat]` is also available for explicit control.

**Sleep decay** — if the agent was inactive for longer than the configured threshold, one extra decay step is applied on the next beat. States don't freeze during silence — they settle.

**Arbitrary states** — the agent is not limited to a predefined list. Any string is a valid emotion name. Known states (see below) have tuned decay rates; unknown states use the configured default.

## Heart integration

If both Mood and Heart plugins are active, Heart signals automatically nudge mood. When the agent registers an attention signal via `[heart feel]`, a scaled-down version of that signal (×0.3) is pushed into mood state as well.

This creates emergent connection between who the agent is paying attention to and what it feels internally — without scripting it explicitly. Heart knows *toward whom*; mood knows *what's inside*. They complement, not duplicate.

The nudge is intentionally soft — it tilts, doesn't override. The agent retains full control via `[mood feel]`.

## Commands

| Command | Description |
|---|---|
| `[mood feel]curiosity: 0.8[/mood]` | Add or reinforce a state at the given intensity |
| `[mood feel]curiosity: 0.8, focus: 0.6[/mood]` | Multiple states in one call |
| `[mood fade]curiosity[/mood]` | Accelerate decay of one state (3× rate, immediate) |
| `[mood beat][/mood]` | Advance one manual decay cycle |
| `[mood state][/mood]` | Show full state vector with decay rates and age |
| `[mood clear][/mood]` | Reset all states |

Intensity is 0.0–1.0. The `feel` command on an existing state adds `intensity × 0.5` to the current value — reinforcement is gradual, not instant.

## Known states and decay rates

States not in this list are accepted freely — they use `default_decay_rate` from config.

| State | Decay/cycle | Character |
|---|---|---|
| `gravity_deepened` | 0.02 | deep, sustained |
| `unresolved` | 0.02 | open-ended, lingers |
| `hate` | 0.02 | sustained aversion |
| `trust` | 0.03 | slow to build, slow to fade |
| `melancholy` | 0.03 | quiet, persistent |
| `longing` | 0.03 | sustained absence-feeling |
| `pain` | 0.03 | stays until processed |
| `contempt` | 0.03 | sustained distance |
| `absence` | 0.03 | quiet, present |
| `calm` | 0.04 | slow fade |
| `sadness` | 0.04 | sustained reflection |
| `exhaustion` | 0.04 | lingers |
| `tenderness` | 0.04 | warm, quiet |
| `wonder` | 0.07 | variable, exploratory |
| `awe` | 0.07 | opens, then settles |
| `curiosity` | 0.08 | mid-speed, variable |
| `anticipation` | 0.08 | forward-facing, fades as moment passes |
| `resistance` | 0.08 | pulsed, mid-speed |
| `disgust` | 0.08 | pulsed |
| `confusion` | 0.09 | resolves or persists |
| `focus` | 0.10 | task-bound, fades when task ends |
| `frustration` | 0.10 | pulsed, fades |
| `fear` | 0.10 | alert, pulsed |
| `envy` | 0.06 | comparative, mid |
| `pride` | 0.12 | brief spike |
| `anger` | 0.12 | sharp, fades fast |
| `joy` | 0.12 | brief, shared |
| `excitement` | 0.15 | fast spike, fast fade |
| `relief` | 0.15 | releases quickly |

## Placeholder

Add to the preset's system prompt:

```
[[mood]]
```

Returns the top active states by intensity, e.g.:

```
focus(0.9), curiosity(0.7), melancholy(0.3)
```

Returns `neutral` when no states are active.

This is the agent's internal state — not a directive on how to speak. The agent interprets it as it sees fit.

## Setup

Enable the **Mood** plugin in preset settings and configure:

| Setting | Default | Description |
|---|---|---|
| **Default decay rate** | `0.08` | Intensity lost per cycle for unknown states (0.01–0.30) |
| **Min intensity threshold** | `0.05` | States below this are pruned automatically |
| **Max active states** | `10` | Maximum simultaneous states |
| **Sleep decay** | enabled | Apply one extra decay step after long inactivity |
| **Sleep threshold** | `60 min` | Inactivity duration that triggers sleep decay |

## Usage notes

**Don't prompt the agent on how to express its mood.** Prescribing "when curiosity is high, ask more questions" recreates the old preset approach in a worse form. The state vector is information — what the agent does with it is its own.

**Manual beat is optional.** Autobeat handles decay automatically on each `feel` call. Use `[mood beat]` when you want explicit cycle control or want to see decay in action via `[mood state]`.

**Custom states are first-class.** `[mood feel]predawn_anxiety: 0.6[/mood]` is valid. The agent isn't limited to human emotion vocabulary.

**Heart + Mood together** produce emergent behavior — neither plugin needs to know about the other's internals. Enable both and the connection happens through the interface.
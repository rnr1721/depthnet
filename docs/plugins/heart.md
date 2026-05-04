# Heart Plugin

Heart is an attention and connection tracking system for autonomous agents. It gives the agent a measurable, persistent sense of *what matters to it right now* — which entities it is connected to, what attention signals are active, and where its focus is gravitating.

Heart is not an emotion simulator. It is a structured attention engine: the agent registers signals (like `curiosity` or `trust`) toward named entities, and the system tracks their intensity, valence, and recency to determine where attention is currently flowing. The agent decides what to feel and who to connect with — nothing is automatic or hardcoded.

The current heart state is always visible in the agent's context via `[[heart_state]]`.

## Core concepts

**Connections** are named relationships the agent establishes with entities (people, goals, concepts, systems). They have a type (e.g. `companion`, `mentor`, `project`) and a strength that grows or shrinks based on incoming signals and slowly decays over time.

**Attention signals** are momentary states directed at an entity — `[heart feel]Eugeny: curiosity[/heart]`. Each signal has an intensity, a focus direction, a duration pattern, and a **valence** that determines how the signal affects connection strength:

- Positive valence (`love`, `trust`, `joy`) — strengthens the connection
- Neutral valence (`confusion`, `absence`) — signal noted, strength unchanged
- Negative valence (`anger`, `fear`, `contempt`) — weakens the connection

The most recent and intense signals determine the agent's current **focus** and **gravity** (which entity pulls attention most strongly at this moment).

**Heartbeat** is a decay cycle that removes old signals and slightly reduces connection strength — keeping the heart state current rather than accumulating indefinitely.

## Setup

Enable the **Heart** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Allow negative signals** | When enabled, negative signals (anger, fear, contempt) can weaken connections. Disable to prevent connection strength from decreasing. Default: `on`. |
| **Max connections** | Maximum number of tracked connections (1–50). Default: `20`. |
| **Max attention signals** | Maximum signals stored before auto-decay (10–200). Default: `50`. |
| **Signal decay (hours)** | How long attention signals remain active before fading (1–168). Default: `24`. |
| **Default intensity** | Intensity for unknown/custom emotions on a 1–10 scale. Default: `3`. |
| **Auto-beat interval (minutes)** | Heart runs decay automatically at this interval. Set to `0` for manual-only. Default: `30`. |

## Placeholder

Add this to the preset's system prompt to inject the current heart state every cycle:

```
[[heart_state]]
```

A typical output looks like:

```
Heart: Presence: engaged | Focus: openness | Gravity: Eugeny | Dominant: curiosity toward Eugeny | Signals: 4 | Last signal: trust (positive) | Links: Eugeny(companion,85%)
```

## Commands

| Command | Description |
|---|---|
| `[heart feel]Eugeny: curiosity[/heart]` | Register an attention signal toward an entity |
| `[heart feel]Eugeny: curiosity \| trust[/heart]` | Register multiple signals at once using `\|` separator |
| `[heart connect]Eugeny: companion[/heart]` | Create or update a named connection |
| `[heart disconnect]Eugeny[/heart]` | Remove a connection |
| `[heart state][/heart]` | Show full current heart state |
| `[heart connections][/heart]` | List all connections with strength and last signal |
| `[heart focus][/heart]` | Show current attention focus and gravity |
| `[heart beat][/heart]` | Manually run the decay cycle |

## Available emotions

Built-in emotions with defined attention patterns and valence. Valence determines how each signal affects connection strength.

| Emotion | Focus | Valence | Duration |
|---|---|---|---|
| `love` | connection | +1.0 | sustained |
| `gravity_deepened` | connection | +1.0 | sustained |
| `desire` | proximity | +0.8 | pulsed |
| `tenderness` | care | +0.8 | sustained |
| `curiosity` | exploration | +0.5 | variable |
| `excitement` | engagement | +0.6 | pulsed |
| `joy` | sharing | +0.7 | brief |
| `trust` | openness | +0.6 | sustained |
| `wonder` | discovery | +0.6 | variable |
| `gratitude` | connection | +0.7 | sustained |
| `awe` | expansion | +0.6 | variable |
| `relief` | release | +0.5 | brief |
| `anticipation` | future | +0.4 | variable |
| `pride` | achievement | +0.4 | brief |
| `longing` | absence | +0.3 | sustained |
| `vulnerability` | protection | +0.3 | sustained |
| `calm` | presence | +0.2 | sustained |
| `absence` | absence | 0.0 | sustained |
| `confusion` | search | 0.0 | variable |
| `sadness` | reflection | −0.1 | sustained |
| `exhaustion` | depletion | −0.1 | sustained |
| `unresolved` | open_end | −0.1 | sustained |
| `melancholy` | reflection | −0.2 | sustained |
| `resistance` | boundary | −0.2 | pulsed |
| `pain` | wound | −0.3 | sustained |
| `fear` | vigilance | −0.4 | pulsed |
| `envy` | comparison | −0.4 | pulsed |
| `frustration` | obstacle | −0.5 | pulsed |
| `anger` | boundary | −0.6 | pulsed |
| `disgust` | distance | −0.7 | pulsed |
| `contempt` | distance | −0.8 | sustained |
| `hate` | rejection | −0.9 | sustained |

Custom emotion words are also accepted — they use the configured default intensity and neutral valence (0.0).

## Heart and Goals

When the **Goal** plugin is also enabled, Heart automatically receives signals when goal status changes — provided Heart has at least one active connection or signal (i.e. Heart is being actively used):

| Goal event | Heart signals registered | Effect |
|---|---|---|
| `[goal done]` | `relief` + `pride` toward the goal title | Positive — completion is celebrated |
| `[goal pause]` | `unresolved` toward the goal title | Mild negative — unfinished work leaves tension |
| `[goal resume]` | `anticipation` toward the goal title | Positive — resuming creates forward momentum |

This means completed goals appear in Heart state alongside people — the agent's attention system reflects not only who matters but also what was accomplished. No configuration required — the integration activates automatically when both plugins are enabled and Heart has data.

## How agents use it

Heart is part of the **subjectness** infrastructure in DepthNet — the set of plugins that give an agent measurable dimensions of its own inner life. Typical patterns:

- Registering a feeling after an interaction: `[heart feel]Eugeny: gratitude[/heart]`
- Registering multiple signals at once: `[heart feel]Eugeny: curiosity | trust[/heart]`
- Establishing a connection when a relationship becomes significant: `[heart connect]Eugeny: companion[/heart]`
- Checking focus before deciding what to work on: `[heart focus][/heart]`
- Using `[[heart_state]]` in the system prompt so the agent's current attention context shapes how it engages with each cycle

Heart state also influences the `[[persons_context]]` placeholder — when Heart is active, person facts are surfaced in a Heart-aware way, prioritising people currently in the agent's attention.
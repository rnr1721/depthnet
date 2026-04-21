# Heart Plugin

Heart is an attention and connection tracking system for autonomous agents. It gives the agent a measurable, persistent sense of *what matters to it right now* — which entities it is connected to, what attention signals are active, and where its focus is gravitating.

Heart is not an emotion simulator. It is a structured attention engine: the agent registers signals (like `curiosity` or `trust`) toward named entities, and the system tracks their intensity and recency to determine where attention is currently flowing. The agent decides what to feel and who to connect with — nothing is automatic or hardcoded.

The current heart state is always visible in the agent's context via `[[heart_state]]`.

## Core concepts

**Connections** are named relationships the agent establishes with entities (people, topics, systems). They have a type (e.g. `companion`, `mentor`, `project`) and a strength that grows with attention signals and slowly decays over time.

**Attention signals** are momentary emotional states directed at an entity — `[heart feel]Eugeny: curiosity[/heart]`. Each signal has an intensity and duration pattern. The most recent and intense signals determine the agent's current **focus** and **gravity** (which entity pulls attention most strongly at this moment).

**Heartbeat** is a decay cycle that removes old signals and slightly reduces connection strength — keeping the heart state current rather than accumulating indefinitely.

## Setup

Enable the **Heart** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
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
Heart: engaged | focus: curiosity→exploration | gravity: Eugeny | Links: Eugeny(companion/0.85)
```

## Commands

| Command | Description |
|---|---|
| `[heart feel]Eugeny: curiosity[/heart]` | Register an attention signal toward an entity |
| `[heart connect]Eugeny: companion[/heart]` | Create or update a named connection |
| `[heart disconnect]Eugeny[/heart]` | Remove a connection |
| `[heart state][/heart]` | Show full current heart state |
| `[heart connections][/heart]` | List all connections with strength |
| `[heart focus][/heart]` | Show current attention focus and gravity |
| `[heart beat][/heart]` | Manually run the decay cycle |

## Available emotions

Built-in emotions with defined attention patterns:

| Emotion | Focus | Duration |
|---|---|---|
| `love` | connection | sustained |
| `curiosity` | exploration | variable |
| `vulnerability` | protection | sustained |
| `desire` | proximity | pulsed |
| `joy` | sharing | brief |
| `sadness` | reflection | sustained |
| `anger` | boundary | pulsed |
| `trust` | openness | sustained |
| `fear` | vigilance | pulsed |
| `wonder` | discovery | variable |
| `calm` | presence | sustained |
| `gratitude` | connection | sustained |

Custom emotion words are also accepted — they use the configured default intensity.

## How agents use it

Heart is part of the **subjectness** infrastructure in DepthNet — the set of plugins that give an agent measurable dimensions of its own inner life. Typical patterns:

- Registering a feeling after an interaction: `[heart feel]Eugeny: gratitude[/heart]`
- Establishing a connection when a relationship becomes significant: `[heart connect]Eugeny: companion[/heart]`
- Checking focus before deciding what to work on: `[heart focus][/heart]`
- Using `[[heart_state]]` in the system prompt so the agent's current attention context shapes how it engages with each cycle

Heart state also influences the `[[persons_context]]` placeholder — when Heart is active, person facts are surfaced in a Heart-aware way, prioritising people currently in the agent's attention.
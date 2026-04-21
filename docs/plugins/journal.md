# Journal Plugin

The Journal is the agent's episodic memory — a chronological record of what *happened*: actions taken, decisions made, errors encountered, observations noted, interactions had. Every entry is timestamped and typed, making the journal both browsable in order and searchable by meaning.

Where vector memory stores *what the agent knows*, the journal stores *what the agent experienced*. Together they form a complete picture: knowledge and history.

## Entry structure

Each journal entry consists of:
- **Type** — what kind of event it was
- **Summary** — a one-line description (required)
- **Details** — additional context (optional)
- **Outcome** — `success` or `failure` (optional)

Available entry types: `action`, `decision`, `interaction`, `error`, `observation`, `event`

## Setup

Enable the **Journal** plugin in your preset settings.

| Setting | Description |
|---|---|
| **Journal language** | Optionally force a language for all entries. |
| **Default entries limit** | How many entries to return by default for `recent` and `search` (1–50). Default: `10`. |

## Commands

**Adding entries:**

| Command | Description |
|---|---|
| `[journal]action \| Refactored memory plugin[/journal]` | Add a simple entry |
| `[journal]error \| DB failed \| Timeout after 30s \| outcome:failure[/journal]` | Add a full entry with details and outcome |
| `[journal]decision \| Chose approach A over B \| Simpler implementation[/journal]` | Record a decision with reasoning |
| `[journal]interaction \| Eugeny asked about the project status[/journal]` | Record an interaction |

**Browsing:**

| Command | Description |
|---|---|
| `[journal recent]10[/journal]` | Show last 10 entries |
| `[journal show]42[/journal]` | Show full content of entry #42 |

**Searching:**

| Command | Description |
|---|---|
| `[journal search]memory optimization[/journal]` | Semantic search across all entries |
| `[journal search]today[/journal]` | All entries from today |
| `[journal search]yesterday \| errors[/journal]` | Semantic search filtered to yesterday |
| `[journal search]2024-03-15 \| database[/journal]` | Filtered to a specific date |
| `[journal search]2024-03-10:2024-03-15 \| database[/journal]` | Filtered to a date range |

**Managing:**

| Command | Description |
|---|---|
| `[journal delete]42[/journal]` | Delete entry #42 |
| `[journal clear][/journal]` | Clear all journal entries |

## How agents use it

The journal gives the agent a sense of its own history. Typical patterns:

- Recording every significant action as it happens: `[journal]action | Sent summary to Eugeny via Telegram[/journal]`
- Logging decisions with reasoning so they can be reviewed later
- Recording errors and what caused them for pattern recognition across cycles
- Searching past entries before starting a similar task: `[journal search]database migration[/journal]`
- Reviewing what happened today or this week for self-reflection

Combined with [Vector Memory](vector-memory.md), the journal enables a full memory architecture: the agent extracts durable knowledge from experiences and stores it in vector memory, while the journal retains the raw episodic record.

## Journal vs other memory types

| | Journal | Vector Memory | Memory (notepad) |
|---|---|---|---|
| **What it stores** | Events and experiences | Knowledge and facts | Always-visible anchors |
| **Structure** | Typed, timestamped entries | Flat semantic entries | Flat numbered list |
| **Search** | Semantic + date filter | Semantic similarity | Keyword only |
| **Chronological** | ✓ | ✗ | ✗ |
| **Always in context** | ✗ (retrieved on demand) | ✗ (retrieved on demand) | ✓ |
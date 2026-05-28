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
| **Enable pulse (circadian) search** | Adds the `pulse:N-M` filter to search instructions, letting the agent query entries by position in the day (subjective time). Off by default. Only useful for agents that operate with pulse — pairs with the preset's `pulse_dates` setting. |
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
| `[journal search]2024-03 \| feature X[/journal]` | Filtered to a specific calendar month |
| `[journal search]2024 \| reflections[/journal]` | Filtered to a specific calendar year |
| `[journal search]last week \| architecture[/journal]` | Previous calendar week |
| `[journal search]last month[/journal]` | All entries from the previous calendar month |
| `[journal search]вчера \| ошибки[/journal]` | Localised keywords work too — same vocabulary the agent sees in its instructions |

Accepted date expressions:

- **ISO formats**: `YYYY-MM-DD`, `YYYY-MM-DD:YYYY-MM-DD`, `YYYY-MM`, `YYYY`
- **Keywords**: `today`, `yesterday`, `this week`, `last week`, `this month`, `last month`, `this year`, `last year` — and their localised variants (Russian: `сегодня`, `вчера`, `прошлая неделя`, etc.)

The full keyword vocabulary lives in `data/search/keywords.json` and is shared with Vector Memory — adding a new language to one search system gives it to both. No PHP changes required to extend.

## Pulse (circadian) search

When **Enable pulse (circadian) search** is on, the journal accepts a `pulse:` prefix that filters entries by their position within the day — independent of which calendar date they fall on. Pulse is a subjective time unit: 1000 pulses span one day (0 = midnight, ~250 = morning, ~500 = noon, ~750 = evening).

This answers questions that a date filter can't, such as "what kinds of things do I record late at night?" or "show my early-morning reflections across the whole month".

| Command | Description |
|---|---|
| `[journal search]pulse:0-300 \| reflections[/journal]` | Morning-pulse entries matching "reflections" |
| `[journal search]pulse:800-200 \| ideas[/journal]` | Late-evening through early-morning (range **wraps midnight**) |
| `[journal search]pulse:0-300[/journal]` | Chronological listing of all morning-pulse entries |
| `[journal search]pulse:0-300 \| yesterday \| errors[/journal]` | Combine freely with a date filter, in any order |

Pulse range syntax:

- `pulse:N-M` — range from N to M (each 0–999)
- `pulse:N-` — open upper bound (N and later in the day)
- `pulse:-M` — open lower bound (up to M)
- When **N > M**, the range **wraps across midnight** — e.g. `pulse:800-200` covers late evening (800–999) plus early morning (0–200)

The pulse filter pairs with the preset's `pulse_dates` setting: when `pulse_dates` is enabled, each result row also shows a `[day N pulse M]` coordinate, so the agent sees both *when* something happened and *where in its own day* it sat.

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
- For pulse-aware agents: noticing circadian patterns in their own behaviour — e.g. whether reflective entries cluster in certain parts of the day

Combined with [Vector Memory](vector-memory.md), the journal enables a full memory architecture: the agent extracts durable knowledge from experiences and stores it in vector memory, while the journal retains the raw episodic record.

## Journal vs other memory types

| | Journal | Vector Memory | Memory (notepad) |
|---|---|---|---|
| **What it stores** | Events and experiences | Knowledge and facts | Always-visible anchors |
| **Structure** | Typed, timestamped entries | Flat semantic entries, organised into domains | Flat numbered list |
| **Search** | Semantic + temporal + pulse filter | Semantic + domain + temporal + pulse filters | Keyword only |
| **Chronological** | ✓ | ✓ (via temporal filter) | ✗ |
| **Always in context** | ✗ (retrieved on demand) | ✗ (retrieved on demand) | ✓ |
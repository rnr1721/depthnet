# RAG — Retrieval-Augmented Generation

RAG in DepthNet allows agents to automatically retrieve relevant information from their memory stores before each thinking cycle. Instead of relying solely on what fits in the context window, the agent gets a targeted selection of relevant memories, journal entries, skills, and person facts — injected into the system prompt via `[[rag_context]]`.

## How it works

Before each thinking cycle (or each response in single mode), the RAG pipeline runs:

1. A dedicated **RAG preset** receives the recent conversation and formulates search queries
2. The queries are run against the selected **sources** (vector memory, journal, skills, persons)
3. Results are **deduplicated** and merged into a single `[[rag_context]]` block
4. The block is injected into the main agent's system prompt before it starts thinking

The RAG preset is a small, focused model — it doesn't need to be powerful, just good at formulating concise search queries from context.

---

## Multi-config pipeline

Each agent can have **multiple RAG configs**, executed in order. Each config has:
- Its own RAG preset (with its own system prompt and engine)
- Its own set of sources to search
- Its own search settings (mode, engine, limits)

Results from all configs are **deduplicated across the pipeline** — if config 1 already retrieved memory #42, config 2 won't include it again. All results are merged into one `[[rag_context]]` block in the system prompt.

You can drag configs to reorder them. The **first config is always primary** — see below.

---

## Primary vs secondary configs

**Primary config** (first in the list):
- Participates in agent-queued queries via the [RAG Query plugin](#rag-query-plugin)
- When the agent uses `[rag query]...[/rag]`, those queries are used here instead of model-formulated ones

**Secondary configs** (all others):
- Always use model-formulated queries
- Agent-queued queries do not apply to them

This design lets you have one "smart" retrieval layer the agent can steer, and additional passive layers that always run automatically.

---

## Sources

Each config can search any combination of sources:

| Source | What it searches |
|---|---|
| `vector_memory` | Semantic vector memory — flat or associative mode |
| `journal` | Episodic journal entries (events, decisions, errors, reflections) |
| `skills` | Skill items from the skill knowledge base |
| `persons` | Person facts from person memory, Heart-aware |

**Vector memory** behaves according to the config's `rag_mode` setting:
- `flat` — top-K similarity search across all memories
- `associative` — finds the most relevant memory, then expands to related ones via the association graph

If both `flat` and `associative` are selected, associative runs first and flat supplements.

---

## Search settings

Each config has its own tuning:

| Setting | Description | Default |
|---|---|---|
| **Mode** | `flat` or `associative` vector search | flat |
| **Engine** | `tfidf` (keyword) or `embedding` (semantic) | tfidf |
| **Context limit** | How many recent messages the RAG preset sees when formulating queries | 5 |
| **Results** | Max vector memory results per query | 5 |
| **Journal limit** | Max journal entries returned | 3 |
| **Skills limit** | Max skill items returned | 3 |
| **Content limit** | Max characters per result in output | 400 |
| **Journal window** | Neighbour entries to include around each journal hit (for continuity) | 0 |
| **Relative dates** | Show "3d ago" alongside absolute dates | off |

**Engine recommendation:**
- `tfidf` — fast, no API calls, works without embedding setup. Good for keyword-heavy memory.
- `embedding` — semantic similarity, finds related concepts even when phrased differently. Requires an embedding capability configured on the preset.

---

## Journal date search

The journal source supports date-aware queries. The RAG preset can formulate queries in special formats that `JournalService` understands:

| Format | Example | Meaning |
|---|---|---|
| Semantic only | `worked on database optimization` | Pure semantic search |
| Date only | `yesterday` | All entries from yesterday |
| Named range | `last week` | Entries from the past 7 days |
| Date + semantic | `yesterday \| database error` | Semantic search within yesterday |
| Date range | `2026-04-15:2026-04-20` | All entries in that range |
| Range + semantic | `2026-04-15:2026-04-20 \| Eugeny meeting` | Semantic search within that range |

Supported named dates: `today`, `yesterday`, `this week`, `last week`, `last month`

To use this, your journal RAG preset's system prompt should instruct the model to use date formats when the conversation mentions specific times or recent events.

---

## RAG Query plugin

The RAG Query plugin lets the agent explicitly control what the primary RAG config searches on the next cycle, instead of relying on automatic query formulation.

```
[rag query]vector memory associative retrieval performance[/rag]
[rag query]journal entries about database errors last week[/rag]
[rag show][/rag]
[rag clear][/rag]
```

Multiple `[rag query]` calls accumulate — they don't overwrite each other. Up to 5 queries per cycle. All queued queries are consumed and cleared after the next RAG pass.

This is useful when the agent knows it needs specific information on the next cycle — for example, before starting a complex task it can pre-load relevant memories.

---

## Setting up RAG configs

### Minimal setup (single config)

1. Create a small preset to act as the RAG preset (any fast model works)
2. Write a focused system prompt — see [example prompts](#example-rag-preset-prompts) below
3. Open your main agent preset → RAG section → Add RAG
4. Select the RAG preset, choose sources, configure limits
5. Add `[[rag_context]]` to your main agent's system prompt where you want the context injected

### Multi-config setup (layered retrieval)

Example: agent with separate semantic memory, journal, and person layers.

**Config 1 — Semantic memory (primary)**
- RAG preset: fast model with semantic query prompt
- Sources: `vector_memory`
- Mode: `associative`, Engine: `embedding`
- Results: 5

**Config 2 — Journal (events)**
- RAG preset: fast model with event/date query prompt
- Sources: `journal`
- Journal limit: 5, Journal window: 1

**Config 3 — Persons**
- RAG preset: fast model with person query prompt
- Sources: `persons`

**Config 4 — Skills**
- RAG preset: fast model with skill query prompt
- Sources: `skills`
- Skills limit: 3

Each config uses a different RAG preset with a different system prompt tuned for its source type.

---

## Example RAG preset prompts

### General semantic memory

Good for the primary config searching vector memory. Covers multiple semantic angles in one pass.

```
Return 1 to 5 memory retrieval queries.
Output format:
- each query on a separate line
- queries are separated by " | "
- only the queries, nothing else
Rules:
- each query must be 8–12 words
- no punctuation
- no questions
- no explanations
- focus on semantic memory retrieval, not keyword search
Prefer actions, events, and relationships over static facts.
Each query should ideally target a different aspect:
- entity (key participants or subjects)
- event (what happened or discussion topic)
- state (current condition, emotions, habits, relationships)
- temporal contrast (past vs present change)
- association (related context or linked memory)
Avoid repeating the same entity across queries unless necessary.
If only one aspect exists, output only one query.
```

---

### Journal (events and decisions)

Tuned for episodic retrieval. Supports date-aware query format.

```
Return 1 to 3 journal search queries about events and decisions.
Output format:
- each query on a separate line
- queries are separated by " | "
- only the queries, nothing else

Query format options:
  Semantic only:     decided to refactor the memory system
  Date only:         yesterday
  Date + semantic:   2026-04-15:2026-04-20 | work on DepthNet
  Named date:        last week | database error

Supported date keywords: today, yesterday, this week, last week, last month
Supported date range: YYYY-MM-DD:YYYY-MM-DD

Rules:
- if conversation mentions specific dates or time periods — use date format
- if conversation mentions recent events — use today or yesterday
- always prefer date+semantic over pure semantic when time context is clear
- focus on: what happened, decisions made, errors encountered, outcomes
- 6–10 words for the semantic part
- if no time context is clear — use pure semantic queries only
```

---

### Persons

Extracts names from context and formulates person-focused queries.

```
Return 1 to 3 person memory queries based on who appears in the conversation.
Output format:
- each query on a separate line
- queries are separated by " | "
- only the queries, nothing else
Rules:
- each query must be 4–8 words
- use names directly when they appear in context
- focus on relationships, roles, and personal facts
- if no names are present, use descriptive terms (developer, colleague, friend)
Each query should target a different angle:
- who this person is (role, background)
- their relationship to others mentioned
- relevant personal facts or habits
If no people are mentioned, output one generic query about the most contextually likely person.
```

---

### Skills and tools

Looks at the current task and retrieves relevant capabilities.

```
Return 1 to 2 skill and tool queries based on the current task or topic.
Output format:
- each query on a separate line
- queries are separated by " | "
- only the queries, nothing else
Rules:
- each query must be 4–8 words
- focus on capabilities, tools, techniques, and knowledge areas
- use technical terms when present in context
- prefer actionable knowledge over abstract concepts
Each query should target a different angle:
- what skill or tool is relevant to the current task
- what knowledge could help complete or understand it
If the context is general conversation with no clear task, output one query
about communication or interpersonal skills.
```

---

## System prompt placement

Place `[[rag_context]]` in your main agent's system prompt where it makes most sense for your use case. Common patterns:

**Before thinking instructions** — agent sees retrieved context before deciding what to do:
```
[[rag_context]]

You are an autonomous agent. Think carefully...
```

**After identity, before instructions** — agent knows who it is, then gets context:
```
You are Aria, a research assistant...

[[rag_context]]

Your task is to...
```

**In a dedicated memory section:**
```
## What you know
[[notepad_content]]

## Relevant from memory
[[rag_context]]

## Your task
...
```

If `[[rag_context]]` is absent from the system prompt, RAG still runs but results are silently discarded — no error, no effect.

---

## Tips

**Start with one config.** Add a second only when you have a clear reason — journal retrieval that needs separate tuning, or persons that are drowning in vector memory results.

**Use a cheap fast model for RAG presets.** The query formulation step doesn't need intelligence, it needs speed. A small local model or a cheap API model works well.

**Tune content limit first.** If `[[rag_context]]` is too large, it crowds out the rest of the system prompt. Lower `rag_content_limit` (200–300 chars) before reducing result counts.

**Journal window = 1 is often enough.** It adds one entry before and after each hit, giving temporal continuity without flooding the context.

**Associative mode is powerful but slower.** It builds a local similarity graph from vector memory, so it needs a reasonable number of stored memories to work well. Start with flat mode until you have 50+ memories.

**Embedding engine requires setup.** You need an embedding capability configured on the RAG preset (e.g. NovitaAI with `baai/bge-m3`). Without it, the system falls back to TF-IDF automatically.

**Persons source is Heart-aware.** If the HeartPlugin is active and has a dominant focus, person retrieval prioritises that person. This means the agent naturally brings up facts about whoever it's paying most attention to.
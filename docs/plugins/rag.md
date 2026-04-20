# RAG Query Plugin

By default, RAG (Retrieval-Augmented Generation) works automatically — before each thinking cycle, the system formulates a search query on its own and injects the most relevant results into the agent's context via `[[rag_context]]`. The RAG Query plugin lets the agent **take control of this process** by explicitly specifying what to search for in the next cycle.

This is useful when the agent knows exactly what it needs to look up and doesn't want to rely on automatic query formulation — for example, when planning a multi-step task and wanting to pre-fetch specific information before the next cycle begins.

## How it works

When the agent issues a `[rag query]` command, the query is saved and held until the next thinking cycle. At that point, RAG uses the queued queries directly instead of formulating its own. After the cycle completes, the queue is cleared automatically.

Multiple queries can be queued in a single cycle — each `[rag query]` call adds to the list rather than replacing it, so the agent can request several different search angles at once (up to 5 per cycle).

If no queries were queued, RAG falls back to automatic formulation as usual — the plugin changes nothing when not actively used.

## Setup

Enable the **RAG Query** plugin in your preset settings. The preset must also have RAG configured — this plugin only controls how the RAG query is formulated, it does not set up RAG itself.

| Setting | Description |
|---|---|
| **RAG Query Language** | Optionally force a language for all queued queries. Should match the language of your memory data. |

## Commands

| Command | Description |
|---|---|
| `[rag query]your search query here[/rag]` | Queue a RAG query for the next cycle |
| `[rag show][/rag]` | Show all currently pending queries |
| `[rag clear][/rag]` | Clear all pending queries (automatic formulation will be used instead) |

Queries are limited to 200 characters each, and up to 5 can be queued per cycle.

## How agents use it

A typical pattern is planning ahead — the agent finishes one step of a task, realises it will need specific information in the next cycle, and queues a targeted query rather than leaving it to chance:

```
I'll need details about the user's previous preferences before continuing.
[rag query]user preferences and past decisions[/rag]
```

On the next cycle, that query runs against the RAG sources and the results arrive in `[[rag_context]]` — ready to use without any extra search step.

Multiple angles can be queued together:

```
[rag query]error handling patterns in PHP[/rag]
[rag query]Laravel exception handling best practices[/rag]
```

Both queries will run, and their combined results will be merged into `[[rag_context]]` for the next cycle.
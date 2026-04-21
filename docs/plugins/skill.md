# Skill Plugin

The Skill plugin gives the agent a persistent, structured knowledge base. Knowledge is organised into named **skills**, each containing any number of **items** — individual pieces of information. Items are indexed semantically, so the agent can search across all skills by meaning rather than exact keywords.

Think of it as the agent's personal wiki: stable, reusable knowledge that it builds up over time and can retrieve when relevant.

## How it differs from other memory types

| | Skill | Vector Memory | Journal | Workspace |
|---|---|---|---|---|
| **Best for** | Stable, reusable knowledge | Facts and insights | What happened | Active task state |
| **Structure** | Named skills with numbered items | Flat entries | Chronological entries | Key-value pairs |
| **Search** | TF-IDF semantic search | TF-IDF or embedding | TF-IDF | By key |
| **Persists** | ✓ | ✓ | ✓ | ✓ |

## Setup

Enable the **Skill** plugin in your preset settings. Available options:

| Setting | Description |
|---|---|
| **Skill language** | Optionally force a language for all skill entries. The agent will receive an instruction to write in that language. |
| **Search results limit** | Maximum number of items returned per semantic search (1–20). Default: `5`. |

## Placeholder

Add this to the preset's system prompt to give the agent a permanent overview of all its skills:

```
[[skills]]
```

This injects a compact list of skill names and item counts, so the agent always knows what knowledge it has and can open the right skill when needed — without loading full content into the prompt every cycle.

## Commands

Skills and items are addressed by number. The agent assigns numbers automatically when creating them.

**Managing skills:**

| Command | Description |
|---|---|
| `[skill]PostgreSQL[/skill]` | Create an empty skill named "PostgreSQL" |
| `[skill]PostgreSQL \| Use EXPLAIN ANALYZE to inspect query plans[/skill]` | Create a skill and add the first item in one step |
| `[skill delete]1[/skill]` | Delete entire skill #1 |
| `[skill show]1[/skill]` | Show skill #1 with all its items |
| `[skill list][/skill]` | List all skills |

**Managing items:**

| Command | Description |
|---|---|
| `[skill add]1 \| item content[/skill]` | Add an item to skill #1 |
| `[skill update]1.2 \| new content[/skill]` | Update item 2 of skill 1 |
| `[skill delete]1.2[/skill]` | Delete item 2 of skill 1 |

**Searching:**

| Command | Description |
|---|---|
| `[skill search]how to speed up slow queries[/skill]` | Find semantically relevant items across all skills |

## How agents use it

Skills are best suited for knowledge the agent wants to *reuse* — not just recall once. Typical patterns:

- An agent working with a specific technology builds a "PostgreSQL" skill and adds tips as it discovers them
- A personal assistant agent maintains a "User preferences" skill and updates it when it learns something new
- A research agent accumulates findings into topic-based skills and searches them before tackling a related problem

The key difference from vector memory is structure: skills group related items together under a meaningful name, making the knowledge base easier to navigate and maintain over time.
# Person Plugin

The Person plugin gives the agent structured memory for people. Instead of storing facts about someone as free-form notes scattered across vector memory or journal, the agent can build a proper per-person record: a set of named facts tied to one identity, with support for multiple aliases (nicknames, alternate spellings, full names).

Facts are searchable both by name and by meaning — the agent can ask "who do I know that works in Kharkiv?" and get back relevant people even if the fact wasn't phrased that way.

## How people and aliases work

Each person is identified by a primary name, but can have any number of aliases stored alongside it. Internally, all aliases are kept as a single slash-separated string — `Женя / Жэка / James Kvakiani` — so any of those names will match when recalling or finding. This means the agent can refer to the same person however they introduced themselves, and always find the right record.

## Setup

Enable the **Person** plugin in your preset settings.

| Setting | Description |
|---|---|
| **Person Language** | Optionally force a language for all stored facts. Should match the language the agent thinks in. |
| **Search results limit** | Max number of facts returned by semantic search (1–20). Default: `5`. |

## Placeholder

Person facts are injected into the agent's context automatically via `[[persons_context]]`, which is managed by the PersonContextEnricher — not by this plugin directly. Add it to the system prompt to give the agent passive awareness of relevant people:

```
[[persons_context]]
```

## Commands

**Storing facts:**

| Command | Description |
|---|---|
| `[person]Женя \| loves punk aesthetic and travel[/person]` | Add a fact about a person (creates the person if new) |

**Recalling and searching:**

| Command | Description |
|---|---|
| `[person recall]Женя[/person]` | Recall all facts about a person by name |
| `[person recall]42[/person]` | Recall by any fact ID belonging to that person |
| `[person find]James Kvakiani[/person]` | Find a person by any alias or name variant |
| `[person search]developer Kharkiv[/person]` | Semantic search across all facts about all people |
| `[person list][/person]` | List all known people |

**Managing aliases:**

| Command | Description |
|---|---|
| `[person alias add]42 \| Жэка[/person]` | Add an alias to the person that owns fact #42 |
| `[person alias remove]42 \| Жэка[/person]` | Remove an alias |

**Deleting:**

| Command | Description |
|---|---|
| `[person delete]42[/person]` | Delete a single fact by ID |
| `[person forget]Женя[/person]` | Remove all facts about a person entirely |

## How agents use it

Person memory is best suited for agents that interact with real people over time — personal assistants, companions, consultants. Rather than relying on the agent to remember details from conversation history, the agent actively records what it learns:

- After learning someone's preference: `[person]Женя | prefers concise responses, dislikes formality[/person]`
- When someone mentions their job: `[person]Женя | works as a solo PHP developer in Kharkiv[/person]`
- When a new nickname comes up: `[person alias add]1 | Жэка[/person]`

Over time the agent builds a rich, searchable knowledge base about the people it works with — independent of how far back the conversation history goes.
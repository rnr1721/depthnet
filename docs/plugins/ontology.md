# OntologyPlugin

## Purpose

Gives agents read/write access to their world-model graph — a temporal property graph of entities and relationships. Unlike `vectormemory` (associative recall) and `journal` (what happened), the ontology stores **what IS**: durable, structured facts about real entities and how they connect.

Use the ontology for facts that will still be true and relevant in 6 months.
Do **not** use it for: rules (use `memory`), episodic events (use `journal`), temporary state (use `workspace`), meta-commentary, or abstract concepts invented mid-conversation.

---

## When to use

| Situation | Tool |
|---|---|
| Stable fact about a real entity (person, place, project, technology) | `ontology` |
| Relationship between two real entities that persists over time | `ontology` |
| Entity's state changed (moved city, renamed, changed role) | `ontology` with `valid_from` |
| Temporary observation or event | `journal` |
| Insight or crystallized knowledge | `vectormemory` |
| Facts about a specific person (soft layer) | `person` |

---

## Naming contract

Before writing, always follow these rules:

**`canonical_name`** — one lowercase English noun, `snake_case` if compound:
```
eugeny    trust    kharkiv    home_city    depthnet
```

**`class`** — one PascalCase English noun. Recommended:
```
Person  Place  Concept  Emotion  Event  Object  Principle  Value  Goal  Technology  Task
```

**`relation_type`** — `snake_case` verb phrase:
```
lives_in    has_surname    defines    weakens    part_of    causes    contradicts    related_to
```

**Property `key`** — `snake_case` English noun:
```
surname    birth_year    current_city    occupation    status
```

---

## Commands (tag mode)

```
Find node:          [ontology find]eugeny[/ontology]
Add node:           [ontology add_node]trust | Concept[/ontology]
Add with aliases:   [ontology add_node]eugeny | Person | Женя, Евгений, Eugeny Gazzaev[/ontology]
Add edge:           [ontology add_edge]eugeny | lives_in | kharkiv[/ontology]
Add edge + date:    [ontology add_edge]sergey | lives_in | kyiv | 2020-03-01[/ontology]
Set property:       [ontology set_property]eugeny | occupation | software_engineer[/ontology]
Node-ref property:  [ontology set_property]eugeny | current_city | @kharkiv[/ontology]
Snapshot depth 1:   [ontology snapshot]eugeny[/ontology]
Snapshot depth 2:   [ontology snapshot]eugeny | 2[/ontology]
Merge nodes:        [ontology merge]женя | eugeny[/ontology]
Close node:         [ontology close]old_project[/ontology]
```

---

## Tool schema (tool_calls mode)

```json
{
  "name": "ontology",
  "parameters": {
    "method": "find | add_node | add_edge | set_property | snapshot | merge | close",
    "content": "<pipe-separated arguments>"
  }
}
```

### Method reference

| Method | Content format | Notes |
|---|---|---|
| `find` | `name` | Search by canonical name or alias |
| `add_node` | `canonical_name \| Class` or `canonical_name \| Class \| alias1, alias2` | Always `find` first to avoid duplicates |
| `add_edge` | `source \| relation_type \| target` or `source \| relation_type \| target \| YYYY-MM-DD` | Missing nodes are auto-created as `Concept` |
| `set_property` | `node \| key \| value` | Prefix value with `@` to reference another node |
| `snapshot` | `node` or `node \| depth` (depth 1–3) | Returns current neighbourhood |
| `merge` | `source \| target` | Merges source into target, source is deleted |
| `close` | `node` | Closes all current edges and properties, history preserved |

---

## Auto-creation behaviour

`add_edge` auto-creates missing source or target nodes as `Concept` if they don't exist. The response message notes which nodes were auto-created so the agent can correct the class if needed:

```
Edge created: sergey --[lives_in]--> kyiv (auto-created as Concept: sergey — update class if needed)
```

To correct: `[ontology add_node]sergey | Person[/ontology]` — this will find the existing node and update its class.

---

## Temporal semantics

- `valid_until = null` means **currently valid**
- Closing a property or edge sets `valid_until = now()` — the record is preserved
- Add a new value to replace it (insert-only, no UPDATE)
- Use `valid_from` in `add_edge` to record historical facts:
  ```
  [ontology add_edge]sergey | lived_in | kharkiv | 2010-01-01[/ontology]
  [ontology add_edge]sergey | lives_in | kyiv | 2020-03-01[/ontology]
  ```

---

## Configuration

| Field | Default | Description |
|---|---|---|
| `enabled` | `false` | Enable the plugin |
| `snapshot_depth` | `1` | Default depth for `snapshot` method (1–3) |

---

## RAG integration

When `ontology` is listed in a RAG config's sources, the enricher automatically scans retrieved memories for entity mentions and injects matching ontology snapshots as `[ONTOLOGY CONTEXT]` into the agent's context. No explicit agent action required.
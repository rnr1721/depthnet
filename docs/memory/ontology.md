# Ontology — World-Model Graph

## Overview

The ontology layer is a temporal property graph that stores structured, durable facts about entities and their relationships. Unlike vector memory (associative recall of episodes) and the journal (what happened), the ontology answers **"what IS"** — facts about people, places, concepts, technologies, and how they relate to each other over time.

Key design principles:
- **Insert-only history** — edges and properties are never overwritten; closing them sets `valid_until = now()`, preserving the full timeline
- **Temporal** — every relationship knows when it became true and when it stopped being true
- **Universal** — not tied to any specific agent; any preset can maintain its own ontology
- **No enums** — `class`, `relation_type`, and property `key` are free strings, so the schema grows without migrations

---

## Architecture

### Database tables

| Table | Purpose |
|---|---|
| `ontology_nodes` | Named entities (Person, Place, Concept, etc.) |
| `ontology_node_properties` | Temporal key-value properties on nodes |
| `ontology_edges` | Directed, typed, temporal relationships between nodes |

All tables are scoped by `preset_id`.

### Layer structure

```
OntologyPlugin          — thin agent-facing command layer
OntologyService         — write operations, business logic
OntologyQueryService    — read operations for the admin UI
OntologyNode            — Eloquent model
OntologyNodeProperty    — Eloquent model
OntologyEdge            — Eloquent model
```

---

## Naming contract

Agents must follow these conventions when writing to the ontology:

| Field | Rule | Example |
|---|---|---|
| `canonical_name` | One lowercase English noun, `snake_case` if compound | `eugeny`, `home_city`, `trust` |
| `class` | PascalCase English noun | `Person`, `Place`, `Concept`, `Emotion`, `Event`, `Object`, `Principle`, `Value`, `Goal`, `Technology` |
| `relation_type` | `snake_case` verb phrase | `lives_in`, `has_surname`, `defines`, `weakens`, `part_of`, `causes` |
| property `key` | `snake_case` English noun | `surname`, `birth_year`, `current_city`, `status` |

---

## OntologyService — write operations

All write methods are preset-scoped and return `['success' => bool, 'message' => string]`.

### `findNode(AiPreset $preset, string $name): ?OntologyNode`
Finds a node by canonical name or alias. Returns `null` if not found.
Uses a three-level lookup: indexed canonical name → JSON aliases → negative cache.

### `addNode(AiPreset $preset, array $params): array`
Creates a node or returns an existing one if a name/alias match is found.
```php
$params = ['name' => 'eugeny', 'class' => 'Person', 'aliases' => ['Женя', 'Евгений']];
```
Uses `lockForUpdate` inside a transaction to prevent race conditions.

### `addEdge(AiPreset $preset, array $params): array`
Creates a directed edge between two nodes. If either node does not exist, it is auto-created as `Concept` (noted in the response message).
If an identical current edge already exists, its weight is incremented instead.
```php
$params = ['source' => 'eugeny', 'relation' => 'lives_in', 'target' => 'kharkiv', 'valid_from' => '2010-01-01'];
```
Self-loops are rejected.

### `setProperty(AiPreset $preset, array $params): array`
Sets a temporal property on a node. Closes the previous value (insert-only).
Value can be a scalar string or a node reference (prefix with `@`):
```php
// Scalar
$params = ['node' => 'eugeny', 'key' => 'occupation', 'value' => 'software_engineer'];

// Node reference
$params = ['node' => 'eugeny', 'key' => 'current_city', 'value' => '@kharkiv'];
```

### `getSnapshot(AiPreset $preset, array $params): array`
Returns a text snapshot of a node's neighbourhood for injection into agent context.
```php
$params = ['node' => 'eugeny', 'depth' => 2]; // depth 1–3
```

### `mergeNodes(AiPreset $preset, array $params): array`
Merges source into target. All edges and properties are re-pointed; duplicate current edges have weights summed; source node is deleted; source canonical name becomes a target alias.

### `closeNode(AiPreset $preset, string $name): array`
Closes all current edges and properties for a node. History is preserved.

### `findMentionedNodes(AiPreset $preset, string $text): array`
Scans text for entity mentions using word-boundary matching against canonical names and aliases. Used by the RAG enricher to auto-attach ontology context to retrieved memories.

### `clear(AiPreset $preset): array`
Deletes all ontology data for a preset (nodes, edges, properties). Called by the global preset clear routine.

---

## RAG Integration

When `ontology` is added to a RAG config's `sources` array, the enricher:
1. Collects all text from retrieved vector memories and journal entries
2. Scans for entity mentions using `findMentionedNodes()`
3. Fetches a depth-1 snapshot for each matched node
4. Appends the results as a `[ONTOLOGY CONTEXT]` block in the RAG context

This gives agents structured, current facts about entities that appear in their retrieved memories — without having to query the ontology explicitly every cycle.

To enable: add `'ontology'` to the `sources` JSON array in `preset_rag_configs`.

---

## OntologyQueryService — admin UI

Used only by `OntologyController`. Not part of the agent-facing API.

### `listForAdmin(AiPreset $preset, string $search, string $filterClass, int $perPage): array`
Returns paginated nodes with their edges and properties, plus stats and available classes for the filter dropdown.

### `stats(AiPreset $preset): array`
Returns `total_nodes`, `total_edges`, and a `by_class` breakdown.

### `formatNode(OntologyNode $node, int $presetId): array`
Formats a single node with current edges and properties for the Vue component.

---

## Admin UI

Route prefix: `admin/ontology`

| Route | Method | Description |
|---|---|---|
| `admin.ontology.index` | GET | Node list with search, class filter, pagination |
| `admin.ontology.node.update` | PUT | Edit node class and aliases |
| `admin.ontology.node.destroy` | DELETE | Delete node (cascades to edges and properties) |
| `admin.ontology.edge.destroy` | DELETE | Delete a specific edge |
| `admin.ontology.clear` | POST | Clear all ontology data for preset |

Vue component: `resources/js/Pages/Admin/Ontology/Index.vue`
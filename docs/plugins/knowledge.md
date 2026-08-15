# Knowledge Plugin

The Knowledge plugin is a single door to all of the agent's memory. Instead of enabling — and reasoning about — five separate memory tools, the agent uses one. It says *what kind* of thing it wants to remember, and the system decides where that piece of knowledge belongs.

Knowledge is **not a sixth memory store**. It's a thin, deterministic facade over the five that already exist — [Journal](journal.md), [Vector Memory](vector-memory.md), [Ontology](ontology.md), [Person](person.md), and [Skill](skill.md). Under the hood, each piece of knowledge still lives in one of those five. Knowledge just removes the need for the agent to know which one, or to carry five instruction blocks in its prompt.

## How it works

Every operation is expressed as one small structured object with an operation (`op`) and, for writes, a **nature** — the *kind* of knowledge involved:

| Nature | What it is | Where it lives |
|---|---|---|
| `episode` | Something that happened — an action, a decision, an error, an observation | Journal |
| `person` | A fact about a named person | Person |
| `relation` | A durable entity, a fact about one, or a link between two | Ontology |
| `skill` | Reusable know-how you'll want to apply again | Skill |
| `note` | A crystallised insight, recalled later by meaning | Vector Memory |

The agent never names a store — it names the *nature*. A deterministic router (plain code, no extra model call) maps each nature to exactly one home. This is the whole idea: **one nature, one home, on write.** Nothing is guessed, and the same fact never gets scattered into several stores.

**Reading works the opposite way.** A `recall` has no nature attached by default — it fans out across all five subsystems in parallel, searches each, and merges the results into one lean answer. Recall goes straight to each store's own search, independently of RAG (which may be turned off entirely). RAG is a passive, pre-assembled retrieval layer; knowledge is active and driven by the agent on demand.

## A note on syntax

The examples below use the **tag syntax** (`[knowledge]...[/knowledge]`) for readability, the same way the other memory docs do. In practice, DepthNet presets default to **native tool calls** — the model invokes `knowledge` through the provider's function-calling mechanism and sends the same object as structured JSON (`{"op": ..., "nature": ..., "args": {...}}`). The tag form is an optional per-preset mode; the tool, the operations, and everything in this document are identical either way. Whether the model writes a tag or emits a tool call, it's producing the same `op` / `nature` / `args`.

## Setup

Enable the **Knowledge** plugin in your preset settings. When you turn it on, you can disable the five individual memory plugins and let the agent drive all of memory through this one tool — see [Relationship to the five memory plugins](#relationship-to-the-five-memory-plugins) below.

| Setting | Description |
|---|---|
| **Knowledge language** | Optionally force a language for all knowledge entries. The agent receives an instruction to write in that language. |
| **Input mode** | `Direct` — the model produces the routing intent itself (cheapest, fully deterministic, no extra model call). `Formulator` — the model speaks plainly in natural language and a separate formulator preset translates that intent for it (one extra cheap model call; lowest load on the main model). See [Input modes](#input-modes). Default: `Direct`. |
| **Note search mode** | How semantic-note recall behaves: `Flat` (top-K most similar) or `Associative` (starts from the best match and traverses related notes). Applies to the `note` nature, which lives in vector memory. Default: `Flat`. |
| **Note similarity engine** | `TF-IDF` (no API needed) or `Embedding` (true semantic similarity; requires an embedding capability on the preset). Default: `TF-IDF`. |
| **Recall results per source** | How many items each subsystem contributes to a single recall (1–10). Keep it small — recall may be called several times per cycle and should stay lean. Default: `3`. |

> **Formulator mode also needs a formulator preset.** Like the compressor, cycle-prompt, and voice presets, the formulator preset is chosen on the preset itself (in the preset modal), not in this plugin's config. If formulator mode is on but no usable formulator preset is set, the plugin fails safe to Direct mode and says so in its reply.

## Operations

There are four operations: **remember**, **recall**, **relate**, and **forget**. In every case the main text goes in `content`; other fields are optional refinements.

### remember — store a piece of knowledge

The shape is `{"op": "remember", "nature": <kind>, "args": {"content": "...", ...}}`. The `nature` sits at the top level, beside `op` — never inside `args`.

| Nature | Example |
|---|---|
| `episode` | `[knowledge]{"op":"remember","nature":"episode","args":{"content":"refactored the memory router; tests passed","type":"action","outcome":"success"}}[/knowledge]` |
| `person` | `[knowledge]{"op":"remember","nature":"person","args":{"person":"Женя","content":"loves punk aesthetic"}}[/knowledge]` |
| `relation` | `[knowledge]{"op":"remember","nature":"relation","args":{"content":"eugeny","class":"Person","key":"current_city","value":"kharkiv"}}[/knowledge]` |
| `skill` | `[knowledge]{"op":"remember","nature":"skill","args":{"content":"Use EXPLAIN ANALYZE to inspect query plans","title":"PostgreSQL"}}[/knowledge]` |
| `note` | `[knowledge]{"op":"remember","nature":"note","args":{"content":"Eugeny prefers concise responses"}}[/knowledge]` |

Optional `args` per nature:

- **episode** — `type` (`action` / `observation` / `decision` / `error`), `outcome` (`success` / `failure`), `details`. All optional; type defaults to `observation`.
- **person** — `person` (the name — required so the fact attaches to someone).
- **relation** — `class` (e.g. `Person`, `Concept`), `aliases`, and a single `key` + `value` property in the same breath.
- **skill** — `title` (the skill name; derived from the content's first line if omitted).
- **note** — `domain` (overrides the default vector-memory domain for this record).

### recall — search everything by meaning

`{"op": "recall", "args": {"query": "..."}}`. Recall searches all five sources and merges the results. Call it several times in a turn if that helps.

| Query | Meaning |
|---|---|
| `[knowledge]{"op":"recall","args":{"query":"database optimization"}}[/knowledge]` | Search every source for anything about database optimization |
| `[knowledge]{"op":"recall","args":{"query":"time:yesterday \| domain:work \| optimization"}}[/knowledge]` | The query carries the same `time:` / `domain:` / `pulse:` filters the underlying stores understand |
| `[knowledge]{"op":"recall","nature":"episode","args":{"query":"the argument we had"}}[/knowledge]` | Narrow the fan-out to specific kinds — put `nature` at the top level |

**Narrowing recall.** By default recall hits every source. If you already know where to look, name one or more natures at the top level (as a single value or a `episode|note` pipe list) and only those sources are queried — cheaper and quieter. Omitting `nature` keeps the wide search. This is optional narrowing, not routing: recall is never forced to a single home.

Each result is tagged with the source it came from, and the header states which sources were searched — so "nothing found" is never ambiguous about scope.

> **Two things worth knowing about recall results.** For **episodes**, the first hit is marked with a ★ — that's the strongest semantic match. A few recent entries may be blended into the tail regardless of the query, so trust the head and treat the tail as possibly recency-based. For **relations**, recall matches by the **entity name appearing in your query**, not by meaning — so search for the *name* of the thing, not a description of it.

### relate — link two named things

`{"op": "relate", "args": {"source": ..., "relation": ..., "target": ...}}`. This is a graph edge between two entities, and it always lands in the ontology — no nature needed.

```
[knowledge]{"op":"relate","args":{"source":"eugeny","relation":"lives_in","target":"kharkiv"}}[/knowledge]
```

Use `relate` when you're connecting two entities. Use `remember` with `nature: relation` when you're recording a single entity or a property of one. An optional `valid_from` date marks when the relation started holding.

### forget — remove a note

```
[knowledge]{"op":"forget","nature":"note","args":{"content":"the outdated fact to drop"}}[/knowledge]
```

Forgetting currently works for **notes only** (semantic memory) — by `content` (deletes the best match) or by `id`. Removing a person, relation, skill, or episode isn't supported through knowledge yet; use that subsystem's own tool for now. Ask to forget another kind and the tool tells you so plainly, rather than failing silently.

## Input modes

The two input modes change *how the operation is produced*, never where it ends up — both converge on the same deterministic router.

- **Direct** — the model emits the structured operation itself. The tool schema teaches it the shape. No extra model call, fully deterministic. A good default for capable models and for instrumental agents that just need a dependable remember/recall tool.
- **Formulator** — the model just says, in plain language, what it wants ("*remember that Женя loves travel*", "*recall what I know about database optimization from last week*"). A small formulator preset translates that into the operation. The model never has to learn any structure — the lowest cognitive load on the main model, at the cost of one extra cheap model call per write.

Pick Direct when you want zero overhead and the model is comfortable with structure. Pick Formulator when you'd rather keep the main model's attention entirely on its actual work and let a cheap helper handle the shape of memory.

## Relationship to the five memory plugins

Knowledge doesn't replace anything and isn't mandatory.

- The five memory plugins — Journal, Vector Memory, Ontology, Person, Skill — keep working exactly as they always have, and each can still be enabled and used on its own.
- Turn Knowledge **on** when you want one interface to all of memory. You can then turn the five individual tools **off**, since Knowledge drives them for you — one tool in the prompt instead of five.
- Turn Knowledge **off** and the five behave exactly as before. Nothing was migrated, nothing was locked away.

Because Knowledge writes into the same underlying stores, memory written through it is fully visible to the individual plugins and vice versa — it's the same data, reached through a different door.

## How agents use it

Knowledge suits agents that would otherwise juggle several memory tools at once — both simple instrumental agents and rich autonomous ones. Typical patterns:

- Recording what just happened as an episode, then crystallising any durable lesson from it as a note — two natures, one tool, no decision about *where*.
- Building up facts about people and the relationships between them as they come up in conversation, without switching tools.
- Recalling broadly before a task ("*what do I know about X?*"), then narrowing to specific natures once the agent knows which kind of memory it needs.
- Accumulating reusable know-how as skills over time, retrievable by meaning alongside everything else in a single recall.

The point is fewer moving parts in the agent's head: it reasons about the *nature* of what it knows, and the system takes care of the rest.
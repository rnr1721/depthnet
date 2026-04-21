# Vector Memory Plugin

Vector Memory gives the agent the ability to store knowledge and retrieve it **by meaning**, not just by exact keywords. Instead of searching for a specific word, the agent can ask "what do I know about performance optimization?" and get back semantically related memories — even if they were written in completely different words.

This makes it the agent's long-term knowledge base: facts it has confirmed, patterns it has noticed, insights it has accumulated over time.

## How it works

When the agent stores something in vector memory, the entry is converted into a mathematical representation of its meaning (a vector). On search, the query is compared against all stored vectors and the most similar ones are returned — ranked by how closely they match in meaning.

Two similarity engines are available:

- **TF-IDF** — keyword-based similarity, works out of the box with no external API required. Good general-purpose choice.
- **Embedding** — true semantic similarity using a language model. Understands meaning across different phrasings and languages. Requires an embedding capability configured for the preset.

## Setup

Enable the **Vector Memory** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Search mode** | `Flat` returns the top-K most similar memories directly. `Associative` starts from the best match and then traverses related memories via a graph walk — useful for agents that need richer, interconnected recall. |
| **Similarity engine** | `TF-IDF` (no API needed) or `Embedding` (requires embedding capability on the preset). |
| **Max entries** | How many memories to store per preset (100–5000). Oldest entries are removed automatically when the limit is reached if auto-cleanup is enabled. |
| **Similarity threshold** | Minimum similarity score (0.0–1.0) for a result to be returned. Lower values return more results; higher values return only close matches. Default: `0.1`. |
| **Search results limit** | Maximum number of results returned per search query (1–20). Default: `5`. |
| **Boost recent memories** | Give higher relevance to more recently stored memories. |
| **Language mode** | Auto-detect, or force a specific language for all entries. If forced, the agent receives an instruction to write memories in that language. |
| **Integrate with Memory Plugin** | When enabled, a reference link is also added to the regular notepad (Memory plugin) each time something is stored in vector memory — so the agent can notice it exists even without searching. |

## Search modes in detail

### Flat mode
The default. Searches all memories and returns the top-K most similar results directly. Fast, predictable, and works well in most cases.

### Associative mode
Designed for agents that build deep interconnected knowledge over time. The search starts with the best match for your query, then uses *that memory's content* to seed the next search step — and so on, up to a configured chain depth. This way, the agent doesn't just find directly relevant memories, it also surfaces related ones that wouldn't appear in a plain similarity search.

Memories in associative mode also accumulate access statistics. Frequently retrieved memories gain a small importance boost over time (similar to long-term potentiation), while memories that haven't been accessed in a long time gradually become lower priority. When the memory limit is reached, the *weakest* memories are removed first — not necessarily the oldest ones.

## Commands

| Command | Description |
|---|---|
| `[vectormemory]text to remember[/vectormemory]` | Store a new memory |
| `[vectormemory search]query[/vectormemory]` | Search by meaning |
| `[vectormemory recent]5[/vectormemory]` | Show the N most recent memories |
| `[vectormemory show]42[/vectormemory]` | Show full content of memory by ID |
| `[vectormemory delete]42[/vectormemory]` | Delete by ID |
| `[vectormemory delete]some content[/vectormemory]` | Delete by content search (finds best match) |
| `[vectormemory clear][/vectormemory]` | Wipe all vector memories for this preset |

## How agents use it

Vector memory is designed for **knowledge**, not events. The distinction matters:

- **Store here**: confirmed facts, learned patterns, insights, preferences, conclusions
- **Store in Journal instead**: what happened, what was said, what was done

Typical agent behaviour:
- After solving a problem, stores the solution approach for future reference
- Before starting a task, searches for relevant past knowledge
- Accumulates a personal knowledge base over time that makes it progressively more capable in its domain

## Memory + Vector Memory integration

If **Integrate with Memory Plugin** is enabled, every new vector memory also leaves a short reference in the agent's regular notepad. This lets the agent passively notice relevant past knowledge in its constant context, without needing to actively search. The format of the reference link is configurable (short, descriptive, or timestamped).

---

## Defragmentation

As an agent runs over days and weeks, vector memory accumulates many fine-grained entries. Defragmentation compresses them: entries from the same calendar day are grouped and sent to the model, which distils them into a smaller set of consolidated memories. The originals are replaced by the distilled versions, preserving the original date.

This keeps the memory base compact and reduces noise from redundant or overly granular entries without losing the substance of what was stored.

**Enable defragmentation** per preset in its settings (`defrag_enabled`). You can also set how many entries to keep per day after compression (`defrag_keep_per_day`) and provide a custom prompt for the distillation step if you want to control how the model summarises.

**Run manually via artisan:**

```bash
# Defrag all presets that have defrag_enabled = true
php artisan agent:defrag

# Defrag a specific preset by ID (ignores the defrag_enabled flag)
php artisan agent:defrag --preset=3
```

The command prints a summary table showing how many days were processed and how many records were removed.

---

## Switching to Embedding engine: backfilling existing memories

If you start with TF-IDF and later switch a preset to the Embedding engine, existing memories won't have embedding vectors yet — they'll continue to work via TF-IDF fallback automatically. To fully migrate them, run the backfill command:

```bash
# Backfill a single preset
php artisan vectormemory:embed --preset=1

# Backfill all presets that have an embedding capability configured
php artisan vectormemory:embed --all

# Also backfill journal entries and person memory facts
php artisan vectormemory:embed --all --journal --persons

# Control batch size and delay between batches (milliseconds)
php artisan vectormemory:embed --all --batch=25 --sleep=2000

# Dry run — see what would be migrated without making any API calls
php artisan vectormemory:embed --preset=1 --dry-run
```

The command processes records in batches and shows a progress bar. If some records fail (e.g. due to a temporary API error), just re-run — it skips records that already have an embedding.

> **Note:** The embedding capability must be configured for the preset before running this command. Set it up at Admin → Capabilities → Embedding.

---

## Export and import

Vector memories can be exported and imported via the preset's UI. This is useful for backups, transferring a knowledge base between presets or installations, or seeding a new agent with existing knowledge.

**Export** produces a JSON file containing all memories with their content, keywords, importance scores, and access statistics.

**Import** accepts either:
- A previously exported JSON file — timestamps and access stats are preserved
- A plain text file — one memory per line, stored with default metadata

Both v1 (legacy) and v2 export formats are supported transparently on import.
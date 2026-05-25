# Vector Memory Plugin

Vector Memory gives the agent the ability to store knowledge and retrieve it **by meaning**, not just by exact keywords. Instead of searching for a specific word, the agent can ask "what do I know about performance optimization?" and get back semantically related memories — even if they were written in completely different words.

This makes it the agent's long-term knowledge base: facts it has confirmed, patterns it has noticed, insights it has accumulated over time.

## How it works

When the agent stores something in vector memory, the entry is converted into a mathematical representation of its meaning (a vector). On search, the query is compared against all stored vectors and the most similar ones are returned — ranked by how closely they match in meaning.

Two similarity engines are available:

- **TF-IDF** — keyword-based similarity, works out of the box with no external API required. Good general-purpose choice.
- **Embedding** — true semantic similarity using a language model. Understands meaning across different phrasings and languages. Requires an embedding capability configured for the preset.

## Domains

Memories are organized into named **domains** — agent-managed namespaces inside a single preset's memory. Think of them as folders: `work`, `relationships`, `architecture_notes`, `experiments`. The agent creates and uses them freely as it works.

A domain exists for as long as it has at least one record. There's no separate registry to maintain — write the first record into `archive` and the domain appears; delete the last record from it and the domain quietly vanishes. The default domain is `global` (configurable per preset); memories stored without specifying a domain land there.

When the agent searches, it can:

- Search across **all** domains (the default — useful when it doesn't know where a memory lives).
- Filter to **specific** domains (e.g. "search only within `work`").
- Search across **several** named domains at once.
- Combine the domain filter with a [time filter](#temporal-search) in any order.

In the admin UI, the live list of domains with record counts is shown in the action bar, and you can filter the view to a single domain at any time.

## Temporal search

Vector memory entries are timestamped, and search can be bounded to a time window. The window can be a single date, a range, an ISO month or year, or a relative keyword like `yesterday` or `last week`. Time expressions are recognised in multiple languages — the same keyword vocabulary used by Journal.

The filter is added via a `time:` prefix at the start of the query, separated from the semantic part by `|`:

| Query | Meaning |
|---|---|
| `[vectormemory search]time:yesterday \| optimization[/vectormemory]` | Optimization-related memories from yesterday |
| `[vectormemory search]time:last week \| architecture[/vectormemory]` | Architecture-related memories from the previous calendar week |
| `[vectormemory search]time:2026-03 \| database[/vectormemory]` | Anything about databases stored in March 2026 |
| `[vectormemory search]time:2025[/vectormemory]` | All memories stored throughout 2025 (chronological listing) |
| `[vectormemory search]time:2026-03-10:2026-03-15 \| feature X[/vectormemory]` | Feature X discussions in that specific window |

Accepted date expressions:

- **ISO formats**: `YYYY-MM-DD`, `YYYY-MM-DD:YYYY-MM-DD`, `YYYY-MM`, `YYYY`
- **Keywords**: `today`, `yesterday`, `this week`, `last week`, `this month`, `last month`, `this year`, `last year` — and their localised variants (Russian: `сегодня`, `вчера`, `прошлая неделя`, etc.)

The full keyword vocabulary lives in `data/search/keywords.json` and is easy to extend with additional languages — no PHP changes required.

**Temporal-only listing.** When the query is empty but a time filter is set, the search returns memories in chronological order (newest first), without semantic ranking. Useful for "what did I think about this week" style questions:

```
[vectormemory search]time:this week[/vectormemory]
```

**Composing filters.** `time:` and `domain:` can both be present in any order:

```
[vectormemory search]time:last week | domain:work | optimization[/vectormemory]
[vectormemory search]domain:work | time:last week | optimization[/vectormemory]
```

Both forms produce the same result.

**Behaviour in associative mode.** When a time filter is set, the associative chain operates entirely within the time window — the starting set is pre-filtered, and subsequent hops stay inside it. This gives "associations within a period" semantics, parallel to how the domain filter scopes the chain.

## Setup

Enable the **Vector Memory** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Search mode** | `Flat` returns the top-K most similar memories directly. `Associative` starts from the best match and then traverses related memories via a graph walk — useful for agents that need richer, interconnected recall. |
| **Similarity engine** | `TF-IDF` (no API needed) or `Embedding` (requires embedding capability on the preset). |
| **Default domain** | Domain assigned to memories stored without an explicit domain name. Defaults to `global`. |
| **Allow agent to clear ALL memories** | When enabled, the agent can wipe the entire vector memory of this preset with `[vectormemory clear][/vectormemory]`. **Off by default** — this is the most destructive action available. |
| **Allow agent to purge a domain** | When enabled, the agent can permanently delete all records of a single domain via `[vectormemory purge]name[/vectormemory]` (or the shorthand `[vectormemory clear]name[/vectormemory]`). The default domain is always protected from purge. **On by default** — less destructive than full clear. |
| **Max entries** | How many memories to store per preset (100–5000). Weakest entries are removed automatically when the limit is reached if auto-cleanup is enabled. |
| **Similarity threshold** | Minimum similarity score (0.0–1.0) for a result to be returned. Lower values return more results; higher values return only close matches. Default: `0.1`. |
| **Search results limit** | Maximum number of results returned per search query (1–20). Default: `5`. |
| **Boost recent memories** | Give higher relevance to more recently stored memories. |
| **Cross-domain bridges** (associative + embedding only) | When enabled, after the in-domain associative result is built, the top anchors are also compared against memories from *other* domains. Strong semantic links bring back up to two extra results marked as bridges — useful when knowledge in `work` connects to something stored in `architecture`, but you didn't ask there. Off by default. |
| **Language mode** | Auto-detect, or force a specific language for all entries. If forced, the agent receives an instruction to write memories in that language. |
| **Integrate with Memory Plugin** | When enabled, a reference link is also added to the regular notepad (Memory plugin) each time something is stored in vector memory — so the agent can notice it exists even without searching. |

## Search modes in detail

### Flat mode

The default. Searches all memories and returns the top-K most similar results directly. Fast, predictable, and works well in most cases.

### Associative mode

Designed for agents that build deep interconnected knowledge over time. The search starts with the best match for your query, then uses *that memory's content* to seed the next search step — and so on, up to a configured chain depth. This way, the agent doesn't just find directly relevant memories, it also surfaces related ones that wouldn't appear in a plain similarity search.

Memories in associative mode also accumulate access statistics. Frequently retrieved memories gain a small importance boost over time (similar to long-term potentiation), while memories that haven't been accessed in a long time gradually become lower priority. When the memory limit is reached, the *weakest* memories are removed first — not necessarily the oldest ones.

When the agent applies a domain filter, the associative chain stays inside the requested domains for the entire walk — giving "associations within a context" semantics. With **Cross-domain bridges** enabled (associative + embedding), the top in-domain results also become anchors for a sideways look: strong semantic links into other domains can surface up to two extra results marked as bridges, without taking slots away from the main answer.

## Commands

### Storage

| Command | Description |
|---|---|
| `[vectormemory]text to remember[/vectormemory]` | Store a new memory in the default domain |
| `[vectormemory work]text to remember[/vectormemory]` | Store a new memory in the `work` domain (creates the domain if it doesn't exist) |

Any method name that is not one of the known commands below (`search`, `recent`, `show`, `delete`, `clear`, `domains`, `purge`) is interpreted as a domain name. So `[vectormemory architecture_notes]...[/vectormemory]` writes into a domain called `architecture_notes`.

### Retrieval

| Command | Description |
|---|---|
| `[vectormemory search]query[/vectormemory]` | Search across all domains |
| `[vectormemory search]domain:work | query[/vectormemory]` | Search only within the `work` domain |
| `[vectormemory search]domain:work,relationships | query[/vectormemory]` | Search across multiple specific domains |
| `[vectormemory search]time:yesterday | query[/vectormemory]` | Search within a time window (see [Temporal search](#temporal-search)) |
| `[vectormemory search]time:last week | domain:work | query[/vectormemory]` | Time + domain filter combined |
| `[vectormemory search]time:2026-03[/vectormemory]` | Chronological listing for the window, no semantic query |
| `[vectormemory recent]5[/vectormemory]` | Show the N most recent memories |
| `[vectormemory show]42[/vectormemory]` | Show full content of memory by ID |
| `[vectormemory domains][/vectormemory]` | List all domains with their record counts |

### Maintenance

| Command | Description |
|---|---|
| `[vectormemory delete]42[/vectormemory]` | Delete by ID |
| `[vectormemory delete]some content[/vectormemory]` | Delete by content search (finds best match) |
| `[vectormemory purge]work[/vectormemory]` | Permanently delete all records of the `work` domain (gated by **Allow agent to purge a domain**) |
| `[vectormemory clear]work[/vectormemory]` | Shorthand for purge — same as above |
| `[vectormemory clear][/vectormemory]` | Wipe ALL vector memories for this preset (gated by **Allow agent to clear ALL memories**) |

The default domain is always protected from purge. To remove its contents you need to clear everything.

## How agents use it

Vector memory is designed for **knowledge**, not events. The distinction matters:

- **Store here**: confirmed facts, learned patterns, insights, preferences, conclusions
- **Store in Journal instead**: what happened, what was said, what was done

Typical agent behaviour:

- After solving a problem, stores the solution approach for future reference in an appropriate domain (e.g. `architecture`, `debugging`).
- Before starting a task, searches for relevant past knowledge — first across all domains, then narrows down if needed.
- Organizes long-term knowledge into themed domains as it grows: `relationships`, `preferences`, `tooling`, etc.
- Accumulates a personal knowledge base over time that makes it progressively more capable in its domain.

## Memory + Vector Memory integration

If **Integrate with Memory Plugin** is enabled, every new vector memory also leaves a short reference in the agent's regular notepad. This lets the agent passively notice relevant past knowledge in its constant context, without needing to actively search. The format of the reference link is configurable (short, descriptive, or timestamped).

---

## Defragmentation

As an agent runs over days and weeks, vector memory accumulates many fine-grained entries. Defragmentation compresses them: entries from the same calendar day are grouped and sent to the model, which distils them into a smaller set of consolidated memories. The originals are replaced by the distilled versions, preserving the original date.

This keeps the memory base compact and reduces noise from redundant or overly granular entries without losing the substance of what was stored.

**Scope.** Defragmentation operates only on the **default domain** (`global` by default). Other domains are agent-managed namespaces — cold accumulators that the agent curates explicitly — and are never touched. The default domain is the hot, distillable layer where unstructured kristallisations land and need periodic compression.

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

**Export** produces a JSON file (format v3) containing all memories with their content, keywords, importance scores, access statistics, and per-record domain.

**Import** accepts either:

- A previously exported JSON file — timestamps, access stats, and domains are preserved.
- A plain text file — one memory per line, stored with default metadata in the default domain.

Older export formats (v1, v2) are supported transparently on import — they simply don't carry per-record domain, so all imported records land in the default domain.

On import you can optionally set a **Target domain** which overrides per-record domain values from the source. This is useful when migrating data from another preset and you want everything funneled into a single domain regardless of how it was organized originally.
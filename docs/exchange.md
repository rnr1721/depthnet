# Preset & Agent Exchange

Portable import/export of presets and agents between DepthNet instances.

Exchange moves **configuration**, never lived state. A bundle is the recipe for
an agent or preset — its prompts, engine settings, plugin config, RAG and inner
voice wiring, behavioral definitions — with no memory, no dialogues, no vectors,
no learning history, and no secrets. You carry the design; the target instance
grows its own state from it.

---

## Table of contents

- [Why it works this way](#why-it-works-this-way)
- [Using it](#using-it)
  - [Export](#export)
  - [Import](#import)
- [What travels and what doesn't](#what-travels-and-what-doesnt)
- [Bundle format](#bundle-format)
- [Policies](#policies)
  - [Secrets](#secrets)
  - [Uniqueness: codes vs names](#uniqueness-codes-vs-names)
  - [Errors vs warnings](#errors-vs-warnings)
- [Architecture](#architecture)
- [Extending it](#extending-it)

---

## Why it works this way

Three principles shaped every decision:

**Configuration, not state.** What makes a preset *what it is* travels. What it
has *accumulated* does not. A behavioral pattern's definition is authored design
and exports; its fitness score is the result of selection on one instance and
means nothing on another, so it resets. This line runs through the whole feature.

**Secrets are entered by hand.** API keys and tokens are stripped at export and
arrive as explicit `null`. The importer never treats a missing key as an error —
it's the expected state. You enter keys through the UI after importing.

**Fail-closed on integrity.** When the graph doesn't hold together — a broken
reference, a missing planner, a code collision — the import is refused with a
full report and nothing is written. A partial import is meaningless: a bundle is
a connected graph, not a pile of rows.

---

## Using it

### Export

Export lives on the object. On a preset or agent card, use **Export** → a small
dialog opens → **Download**. You get a single JSON file.

- **Presets** offer an *Include learned skills* checkbox. Off by default: the
  base export is a clean tool. Skills are borderline (design vs. earned
  experience), so the choice is yours per-export.
- **Agents** pull their whole dependency graph automatically — the planner,
  every role preset and validator, and everything those reference. You don't
  select dependencies; the closure is computed for you.

Exporting one object often produces several presets in the file. That's the
transitive closure at work: a preset that uses an inner voice or a RAG preset
carries those presets along, or the import would land a shell with dangling
links.

### Import

Import is a dedicated page (`/admin/exchange/import`) with a two-step flow:

1. **Choose a bundle** and press *Check bundle*. This runs preflight — it
   validates and shows a report, but **writes nothing**.
2. **Review** the report. Blocking errors disable the import button; warnings
   don't. Press *Import* (or *Import anyway* when there are warnings).

The two steps are deliberate. Preflight is your chance to see what's in the
bundle and what conflicts before anything touches the database. The parsed
bundle is held client-side between the steps, so there are no server-side temp
files to manage.

After import, a reminder points you to set API keys on the new presets before
running them.

---

## What travels and what doesn't

| Area | Exported | Not exported (why) |
|---|---|---|
| Preset scalars | whitelisted fields | `id`, `created_by`, `is_default`, spawn fields (ephemeral) |
| Engine config | as-is, **minus secrets** | secret fields nulled by the engine's own `type: password` declaration |
| Prompts | code, content, description, active flag | version history (instance-local; a legit v1 is created on import) |
| Known sources, plugin data | full | ids / preset_id |
| Plugin configs | name, enabled, config_data (secrets stripped) | default_config (restored from plugin defaults) |
| Capability configs | capability, driver, config (secrets nulled) | ids |
| RAG / inner voice | full config + target preset as a `ref` | raw ids (re-expressed as refs) |
| Behavioral patterns | definitions only, `status: hypothesis` | fitness, confidence, activation counters (learned experience) |
| Contracts | definitions only, `status: hypothesis` | triggered/history (learned experience) |
| Skills | optional (opt-in), never `tfidf_vector` | corpus statistics of the source instance |
| Memory, journal, vectors, dialogues | — | lived state, out of scope entirely |

---

## Bundle format

A bundle is one JSON document. `format_version` gates compatibility — the
importer refuses a version from the future rather than guessing its shape.

```jsonc
{
  "format": "depthnet.bundle",
  "format_version": 1,
  "exported_at": "2026-07-28T18:00:00Z",
  "source": { "app_version": "...", "instance_hint": "..." },
  "options": { "include_skills": false },

  "presets": [
    {
      "ref": "uuid",                     // bundle-local anchor; see below
      "name": "...",
      "engine_name": "...",
      "engine_config": { "api_key": null, "...": "..." },
      "preset_code": "...",
      "prompts": [
        { "code": "default", "content": "...", "is_active": true }
      ],
      "cycle_prompt_preset_ref": null,   // self-links are refs, not ids
      "target_preset_ref": null,
      "known_sources": [ ... ],
      "plugin_configs": [ ... ],
      "capability_configs": [ ... ],
      "plugin_data": [ ... ],
      "behavior_patterns": [ { "status": "hypothesis", "...": "..." } ],
      "contracts":         [ { "status": "hypothesis", "...": "..." } ],
      "rag_configs":        [ { "rag_preset_ref": "uuid", "...": "..." } ],
      "inner_voice_configs":[ { "voice_preset_ref": "uuid", "...": "..." } ],
      "skills": [ ... ]                  // only when include_skills = true
    }
  ],

  "agents": [
    {
      "name": "...", "code": "...",
      "planner_preset_ref": "uuid",
      "roles": [
        { "code": "...", "preset_ref": "uuid", "validator_preset_ref": null,
          "max_attempts": 3, "auto_proceed": false }
      ]
    }
  ]
}
```

### The `ref` mechanism

The single most important design element. Every internal link inside DepthNet
holds an **autoincrement id** — `rag_preset_id`, `planner_preset_id`,
`cycle_prompt_preset_id`. On another instance those ids point at completely
different presets.

So at export, every preset in the bundle gets a **bundle-local UUID `ref`**, and
every internal link is rewritten to reference that `ref` instead of the numeric
id. At import, the importer creates the presets, builds a `ref → new id` map, and
resolves every `*_ref` to the freshly created id. A link that read
`rag_preset_id: 6` on the source becomes `rag_preset_ref: "uuid"` in the bundle
and `rag_preset_id: <new id>` on the target — pointing at the *imported copy*,
never at some unrelated local preset.

`ref` lives only inside the file. It is not stored, not the same as any id, and
does not survive the round trip.

---

## Policies

### Secrets

Secrets are discovered from each engine's field declaration: any config field
declared `type: password` is nulled at export. This is a whitelist of
secrecy — the engine itself says what's secret — not a hardcoded key blacklist.
Add an engine with an `access_token` password field and it's stripped
automatically, with no change to the exporter.

The structure around the secret is preserved. You still see which driver, model
and endpoint were configured — only the secret value is `null`. So an imported
capability reads as "embedding via novita on bge-m3, key needed", not an opaque
blank.

On import, the same declaration drives a matching relaxation: a null secret does
**not** fail validation (`skipSecretValidation`). Range checks (temperature,
token limits) still run — only the *required-secret* rule is lifted. Export and
import stay symmetric through one source of truth.

### Uniqueness: codes vs names

Two kinds of identifier, two policies — because they play different roles.

- **`preset_code` / `agents.code`** are *functional*: handoffs, `preset_code_next`
  and lookups reference them, and a code can appear as a literal string inside a
  prompt (`[speak analyst]`). A collision **blocks the whole import** with a clear
  report. Auto-suffixing a code would silently break every prompt that names it —
  the exact silent failure fail-closed exists to prevent.
- **`preset.name`** is a *cosmetic* label; nothing references it in code. A
  collision is resolved by suffixing (`… (imported)`), never by blocking. Two
  people naming a preset "Assistant" is inevitable when moving between instances,
  not an error. (Agent names aren't unique in the schema, so they aren't
  suffixed.)

### Errors vs warnings

Preflight accumulates *all* problems — it never stops at the first — and splits
them:

- **Errors block the import.** Missing/unknown engine, dangling `*_ref`, missing
  planner, wrong active-prompt count, code collision, unknown `format_version`.
  These are load-bearing: the graph can't stand.
- **Warnings don't block.** A capability whose driver isn't registered on this
  instance — the config imports but is inert until you install the driver or
  re-point it. Optional degradation, surfaced but not fatal.

The rule: *missing load-bearing → error; degraded optional → warning.*

---

## Architecture

```
Contracts/Agent/Exchange/
  PresetExporterInterface        exportPreset / exportAgent / exportBundle
  PresetImporterInterface        preflight / import

Services/Agent/Exchange/
  PresetExporter                 closure walk, secret stripping, serialization
  PresetImporter                 preflight + two-phase transactional import
  DTO/PreflightResult            accumulated errors + warnings + counts
  DTO/ImportResult               created ids + carried warnings

Exceptions/Exchange/
  ExportException                unexportable object (e.g. spawned preset)
  ImportException                blocking errors; carries the full list

Http/Controllers/Admin/
  ExchangeController             5 endpoints (thin; delegates to services)
```

**Exporter** walks the dependency graph from the given roots into a `visited`
set that both breaks reference cycles (inner_voice ↔ cycle_prompt) and
de-duplicates shared presets. It's read-only — no transaction. Contracts are
read via the query builder (they live on raw SQL for the hot `contract:tick`
path); everything else via Eloquent relations.

**Importer** orchestrates existing services rather than writing models directly
where a service exists: `PresetService::createPreset` (validation, plugin-config
init, registry refresh), `PresetPromptService::create` (legitimate v1),
`PluginManager::updatePluginConfigForPreset` (plugin validation),
`AgentService`. Only service-less collections (capabilities, known sources,
plugin data, behavior, contracts) are written directly, mirroring how the app
itself writes them.

The import runs in **two phases inside one transaction**:

1. Create every preset *without* self-links; build `ref → new id`.
2. Resolve self-links and owned collections; create agents (whose refs now
   resolve).

One transaction over both phases means any failure — including a service
returning `success: false`, which the importer converts into a thrown
exception — rolls back everything. There is no half-import.

`AgentService` returns `['success' => bool, ...]` instead of throwing, so the
importer checks the flag and throws itself to unwind the transaction. After a
successful commit, `PresetRegistry::refresh()` makes the imported presets visible
immediately rather than after cache TTL.

### Tests

Unit-level, no database (mocks + reflection), fast:

- `PresetExportSchemaTest` — every `ai_presets` column is classified as exported
  or ignored. Catches a newly added column silently vanishing from bundles.
- `PresetExporterSecretsTest` — password fields are nulled; secrets never leak,
  even when the engine is unknown (fallback path).
- `PresetImporterPreflightTest` — the branch-heavy preflight: envelope, dangling
  refs, active-prompt count, engine-error vs driver-warning, accumulation.

A full round-trip test (export → import on one database) is the natural next
addition once DB test fixtures exist; it wasn't required for the initial release
because the round trip was verified manually.

---

## Extending it

**Adding a preset column?** The schema test will fail until you classify it.
Add it to `PresetExporter::EXPORTED_FIELDS` (and serialize it) or to
`IGNORED_FIELDS` (with a reason). This is the guardrail against silent drift.

**Adding an engine with a secret field?** Declare it `type: password` in the
engine's config fields. Export strips it and import stops requiring it —
automatically, no exchange code changes.

**Breaking the bundle shape?** Bump `format_version` and branch on it in the
importer. Adding a field to the whitelist does *not* need a bump — the importer
ignores unknown fields or supplies a default.

**A new optional dependency (like capability drivers)?** Decide its class:
load-bearing absence → preflight error; optional degradation → warning.
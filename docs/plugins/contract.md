# Contract Plugin

Engineering specification for the metabolism layer of DepthNet.

## What this is

The contract plugin lets a preset run **cheap, deterministic rules** over its own
state without invoking the language model. A contract observes a behavioural trace
(elapsed time, an event count, a state value) and, when a threshold is crossed,
raises a signal the agent can read on its next cycle.

This offloads bookkeeping the model would otherwise have to perform by hand each
cycle — counting cycles, remembering debts, watching for repeats — into a layer
that runs continuously and never forgets.

Contracts are **data, not code**. The engine is universal; the contracts are
authored per preset (by a human via config, by a preset template via JSON, or by
the agent at runtime via commands). DepthNet ships the engine empty.

A contract never decides anything that requires judgement. It prepares a signal;
the agent decides what to do with it. *The metabolism prepares, the foreground
decides.*

### Plugin purity

No plugin pays for a capability it has not enabled. A plugin shows
contract-related instructions only when its **own** config allows it — it never
imports or inspects the contract plugin. The same discipline already governs
`pulse_search_enabled` in JournalPlugin: when off, the pulse syntax never reaches
the prompt. Users who want "just a journal", "just a heart", or "just mood" must
see no metabolism noise. See *Integration hooks* below.

---

## Forms

A contract is one of four forms. This set is closed. If a rule cannot be expressed
as one of these, it is not a contract — it belongs in the agent's reasoning.

| Form | Meaning | Trigger params |
|------|---------|----------------|
| `THR_T` | Time threshold: ≥ N seconds since event E | `event`, `threshold_seconds` |
| `THR_C` | Counter threshold: event observed ≥ N times | `event`, `threshold_count`, optional `window_seconds` |
| `ACC` | Accumulation: `value += weight · dt`, capped | `target`, `weight`, `cap` |
| `DEC` | Decay: `value -= rate` per tick, floored | `target`, `rate`, `floor` |

`THR_T` and `THR_C` raise an action when crossed. `ACC` and `DEC` mutate a value
in the state vector every tick and raise an action only if an optional bound
(`cap` / `floor`) is reached.

The four forms are the `ContractForm` enum (`THR_T`, `THR_C`, `ACC`, `DEC`).
`usesMatch()` is true for the two threshold forms (they read a trace via a
`ContractMatch`); `usesStateVector()` is true for `ACC`/`DEC` (they move a
state-vector dimension).

---

## Actions

A contract raises one action. This set is closed.

| Action | Effect | Payload |
|--------|--------|---------|
| `set_flag` | Set a named flag in contract state (visible to the agent via placeholder) | `flag` |
| `inject_memo` | Append a line to next cycle's memo | `text` |
| `create_goal` | Raise a goal candidate flag (`set_flag` of kind `goal_candidate`); the agent writes the actual goal | `flag` |
| `nudge_state` | Shift a value in the state vector by a delta | `target`, `delta` |

Actions are passive by design. The strongest action is `inject_memo`, which only
places text; nothing in this set interrupts, blocks, or acts on the agent's behalf.
A flag is read or ignored at the agent's discretion.

### Flags are derived, not stored

`set_flag` and `create_goal` write **nothing** when they fire. A flag is *derived*
at read time: it is raised iff some **active, triggered** contract carries that
flag action. The engine's only job on the rising edge of a flag-action contract is
to record `triggered = true` on the contract row; the flag follows from that. On
the falling edge `triggered` goes false and the derived flag drops on its own.

Only `nudge_state` and `inject_memo` perform real work on the rising edge
(`nudge_state` moves a state-vector dimension; `inject_memo` hands text to the memo
writer — see *The memo writer*). This is why edge detection is uniform across forms
but most actions are side-effect-free: the flag *is* the recorded `triggered`
state. (See `ContractEngine::fireAction` and `ContractRuntimeService::raisedFlags`.)

---

## Time

The engine ticks on **real time** (`now()`), always. Pulses
(`PulseServiceInterface`) are an optional **display** layer, never a mechanism —
because pulses are switchable per preset (`AiPreset::getPulseDates()`).

Thresholds are stored in **seconds**. When `pulse_dates = true`, a threshold is
shown to the agent in pulses via `secondsToPulses()`. One execution mode (seconds),
one optional display mode (pulses). No fork in the mechanism.

### `dt` and downtime

`dt` is whole seconds since the last recorded tick (max `last_evaluated_at` across
the preset's contracts; `0` before the first tick). After long downtime `dt` is
large and a single `ACC` step can jump toward its cap in one tick — this is
faithful to `weight · dt` (the quantity did accumulate over real elapsed time) and
is left unclamped on purpose. Clamp `dt` in the engine if you ever want to bound
it. `DEC`, by contrast, is **per-tick discrete** (`value -= rate` once per tick,
not `rate · dt`), mirroring the mood beat — so downtime does not deepen a single
decay step.

---

## Lifecycle

A contract has a `status`:

```
hypothesis → active → suspended
     ↑__________|__________|
```

- **hypothesis** — defined but not executing. Observed, not yet trusted.
- **active** — executing every tick.
- **suspended** — temporarily halted (by `suspend`, or by an automatic
  `suspend_when` condition), without losing definition or history.

Transitions (enforced by `ContractService::transition` against a legal-transition
table):

| Command | From → To |
|---------|-----------|
| `promote` | hypothesis → active |
| `suspend` | active → suspended |
| `resume` | suspended → active |
| `revoke` | active/suspended → hypothesis |

Reversibility is first-class: a contract that stops fitting returns to
`hypothesis` rather than being deleted. History is preserved (the status-change
log lives in the `history` column — see *Storage*).

### Automatic suspension

A contract executes without context sensitivity, so it may fire when subtly
inappropriate. Each contract may declare an optional `suspend_when` — a flag name
that, while set, forces the contract into `suspended` for that tick. This is
automatic suspension (per-tick), distinct from the manual `suspend` transition.
A plugin-wide `default_suspend_flag` (engine config) applies the same gate to
every contract at once.

### Active-contract cap

`max_active_contracts` (engine config, default 50) caps how many contracts may be
`active` at once for a preset. It is a **soft guard** against context bloat and
tick cost, not a data invariant, so it is enforced at the one boundary where the
count can grow: `ContractService::transition` refuses a move **into** `active`
once the cap is reached, with a clear message ("suspend or revoke another contract
first"). It is never enforced silently inside the engine — a contract is either
active and evaluated, or it isn't. `0` disables the cap.

---

## The `vital` flag

A contract may be marked `vital`. A vital contract is visible and can be
challenged by the agent (via `revoke` → `hypothesis`), but **cannot be edited or
revoked instantly** — a change must pass back through `hypothesis` and re-promotion,
the same path as creation. This is a safety interlock for contracts that regulate
the agent's own drive: power over them exists, but is not immediate.

Concretely, `ContractService` refuses to overwrite or delete a vital contract
whose status is not `hypothesis`: the agent must `revoke` it to `hypothesis`
first, edit there, then re-promote. Non-vital contracts can be edited or revoked
directly.

By convention, vital contracts should depend only on **objective traces** (journal,
event timestamps, engine counters) and not on values the agent itself authored
(e.g. a self-declared mood intensity). The engine does not enforce this — it is a
design discipline for whoever authors the contract.

One invariant *is* enforced (`Contract::validate` / `ContractDefinition::validate`):
a `vital` contract may not use a `semantic` or `strict_then_semantic` match mode —
a probabilistic match cannot back a safety interlock. See *match_mode*.

---

## Storage

Contracts are stored in a dedicated table, **`agent_contracts`**, one row per
contract, preset-scoped.

> **Note (design change):** an earlier draft of this spec stored contracts in the
> ontology (nodes + properties, insert-only, `valid_until`). That was dropped in
> favour of a flat table: contracts are simpler than ontology nodes, are always
> read whole, and their status-change history is small and append-only — a JSON
> column carries it without the machinery of temporal property rows. The ontology
> is no longer involved in contract storage.

Column layout (`2026_06_14_..._create_agent_contracts_table`):

- Flat scalars the UI filters on and the engine reads cheaply are **columns**:
  `preset_id`, `name`, `form`, `status`, `vital`, `source`, `suspend_when`,
  `confidence`.
- Per-tick runtime state lives in **columns** too, written directly by the
  runtime service: `triggered`, `triggered_at`, `last_evaluated_at`.
- Nested structures only ever read whole are **JSON**: `trigger` (which *holds*
  `match` for the threshold forms), `action`, and `history` (the status-change
  log).
- `match` is deliberately **not** a column — it is a reserved word in MySQL and,
  per the canonical shape, lives inside `trigger` anyway.
- `unique(preset_id, name)` — one contract name per preset.
- `index(preset_id, status, triggered)` — the engine's hot path ("active,
  triggered contracts for this preset").

The `Contract` Eloquent model owns the casts (`form`/`status` → enum,
`vital` → bool, `trigger`/`action`/`history` → array) and the scopes
(`forPreset`, `ofStatus`, `active`). The engine never touches this model: it works
with the immutable `ContractDefinition` DTO, obtained via `Contract::toDomain()`.
Conversion between storage and execution happens **only** in `ContractService`
(`toDomain()` one way, `toColumns()` the other) — those two methods are the only
bridge.

---

## State vector

Forms `ACC`, `DEC`, and `nudge_state` read and write a **state vector** — a map of
named scalars. The engine depends on `StateVectorInterface`, not on any concrete
plugin.

The provider is resolved in the service provider:

- When the mood plugin exists in the system, `StateVectorInterface` binds to
  **`MoodStateVectorAdapter`**, which exposes MoodPlugin's emotional states as the
  vector. (MoodPlugin does **not** implement `StateVectorInterface` directly — the
  adapter sits in front of it and reads/writes mood's metadata key via
  `PluginMetadataService`, mapping a dimension's scalar to/from `intensity`. This
  keeps the change non-invasive; MoodPlugin implementing the interface directly is
  a cleaner-ownership option that can be adopted later.)
- When the mood plugin is absent from the build, `StateVectorInterface` binds to
  **`NullStateVector`**: every dimension is absent, `isAvailable()` is false, and
  any `ACC`/`DEC`/`nudge` contract is marked `unsatisfiable` and skipped — no
  error, no hard dependency.

Per-preset "mood disabled" is **not** the `NullStateVector` case — it surfaces one
level down: such a preset simply has no stored mood states, so the adapter reads an
empty vector and contracts referencing a missing dimension are skipped as
`unsatisfiable`, the same observable result. This is the same optional-dependency
pattern already used for `MoodInfluencerInterface` (wired in the service provider
only when both plugins are present).

**Mood coexistence / double-decay:** while both run, mood's own decay
(beat/autobeat) and the engine's `DEC` form can both move the same dimension. The
spec treats mood decay as a reference `DEC` that eventually migrates into the
engine; until then they coexist. Keep contract-driven dimensions distinct from
emotions the agent feels directly if you want to avoid double-decay.

`THR_T` and `THR_C` do not need the state vector; they read event traces and
timestamps.

---

## The memo writer

`inject_memo` hands its text to a `ContractMemoWriterInterface` — the engine does
not know how the memo subsystem works, it just hands off text. The writer is
**optional**: its constructor slot on `ContractEngine` is `?ContractMemoWriterInterface
= null`, and it is **intentionally not bound yet**. Until a concrete implementation
is wired, `inject_memo` degrades to a logged warning rather than failing the tick —
the rest of the metabolism keeps running, and nothing is lost silently. Bind a real
writer when the memo sink is ready.

---

## Contract definition (JSON)

The canonical shape. Used identically by template import, plugin config, and the
agent's `define` command. Parsed by `ContractDefinition::fromArray()`; cross-field
invariants checked by `validate()`.

```json
{
  "name": "retry_burst_flag",
  "form": "THR_C",
  "status": "active",
  "vital": false,
  "trigger": {
    "match": {
      "source": "journal",
      "type": "error",
      "contains": "retry",
      "match_mode": "strict"
    },
    "threshold_count": 3,
    "window_seconds": 3600
  },
  "action": {
    "type": "set_flag",
    "flag": "retry_burst"
  },
  "source": "journal",
  "suspend_when": null,
  "confidence": 1.0
}
```

Per-form `trigger` shapes:

```json
// THR_T — seconds since the most recent matching trace
{ "match": { … }, "threshold_seconds": 600 }

// THR_C — count of matching traces in the window
{ "match": { … }, "threshold_count": 3, "window_seconds": 3600 }

// ACC — value += weight · dt, capped
{ "target": "load", "weight": 0.1, "cap": 1.0 }

// DEC — value -= rate per tick, floored
{ "target": "load", "rate": 0.05, "floor": 0.0 }
```

For `THR_T` / `THR_C`, `match` selects the trace (see *Matching events*). For
`ACC` / `DEC`, `target` names a state-vector dimension. Top-level `source` mirrors
`match.source` and drives the vital-source discipline (see *Source tiers*).

## Matching events

`THR_T` and `THR_C` do not reference a dedicated event log — DepthNet does not add
one. They **query traces that are already written**: the journal, heart signals,
vectormemory operation timestamps, chat message times, and the engine's own
counters. A contract describes a `match` over an existing source; the engine runs
it each tick.

This means **no new obligation** is placed on any plugin or on the model: nothing
new must be logged. Contracts read what already exists.

A `match` is routed to a reader by its `source` string through the
`TraceReaderRegistry` (readers are supplied via the `contract.trace_readers` tag).
Each reader declares which source it serves and answers the two questions the
threshold forms ask: `count()` (for `THR_C`) and `secondsSinceLast()` (for
`THR_T`). A source with no registered reader makes the contract `unsatisfiable`
(skipped). **For now only the journal source is implemented**
(`JournalTraceReader`); a new source is a new reader and nothing in the engine or
the contract shape changes.

### `match` against the journal

The journal (`agent_journal`) has a fixed, queryable schema with ready scopes
(`ofType`, `withOutcome`, `between`, `recent`). A journal `match` maps directly
onto them:

```json
"match": {
  "source": "journal",
  "type": "error",
  "outcome": "failure",
  "contains": "retry",
  "match_mode": "strict"
}
```

- `type` → `scopeOfType` (enum: action, reflection, decision, error, observation,
  interaction)
- `outcome` → `scopeWithOutcome` (success, failure, pending)
- time window → `scopeBetween` (from the form's threshold)
- `contains` → text match against `summary` (see `match_mode`)

`THR_C` counts matching rows in the window; `THR_T` measures seconds since the most
recent matching row.

### `match_mode` — predictability is declared, not hidden

Text matching has three honest modes. The mode is part of the contract definition
and visible on read — never a silent fallback inside the engine.

| Mode | Behaviour | Predictable? |
|------|-----------|--------------|
| `strict` | Substring match on `summary` only (a `LIKE` with `%`/`_` in the needle escaped). Default. | Yes — fully deterministic |
| `semantic` | Uses the journal's TF-IDF / embedding search (`searchEntries`), then post-filters by the structured fields. Capped at `SEMANTIC_SCAN_LIMIT` (100), so the count is approximate. | No — probabilistic by declaration |
| `strict_then_semantic` | Strict first; if zero matches, supplement with semantic. | No — hybrid, but declared |

A "silent fallback" (try exact, quietly escalate to smart) is forbidden: it would
make a contract behave differently on similar inputs with no trace in its
definition, destroying the predictability the contract exists to provide.
`strict_then_semantic` is the same idea made **explicit** — the contract carries a
visible label saying it is hybrid.

**Rule (enforced in `validate()`):** any contract whose `match_mode` is `semantic`
or `strict_then_semantic` is automatically **non-vital**. A safety interlock cannot
rest on a probabilistic match, for the same reason it cannot rest on an incomplete
count. `strict` may be vital; the others may not. (`MatchMode::vitalEligible()` is
true only for `strict`.)

### A note on the journal as a source

The journal is written **only when the agent issues a `[journal]` command**
(`addEntry` runs from the model's command, never automatically). So a journal
`match` counts not "how often X happened" but "how often the agent **recorded**
that X happened". The completeness of the count depends on the agent's logging
discipline.

Consequence, enforced by the source-tier table below:

- **Time** over the journal is reliable: "when was the last entry of type T" is an
  objective fact about the journal, regardless of how complete the log is. A
  journal-fed `THR_T` is sound.
- **Count** over the journal is second-tier: a journal-fed `THR_C` reflects logging
  discipline, not ground truth, and must not be vital.

---

## Commands

Thin plugin over the contract service, following the same shape as ontology/mood/
heart.

| Command | Form | Effect |
|---------|------|--------|
| `[contract define]{…json…}[/contract]` | JSON body | Create a contract (defaults to `hypothesis` unless `status` given) |
| `[contract list][/contract]` | — | List contracts with status |
| `[contract show]name[/contract]` | name | Full definition + history of one contract |
| `[contract promote]name[/contract]` | name | hypothesis → active |
| `[contract suspend]name[/contract]` | name | active → suspended |
| `[contract resume]name[/contract]` | name | suspended → active |
| `[contract revoke]name[/contract]` | name | → hypothesis (vital: this is the only allowed mutation path) |

`define` takes JSON rather than pipe-separated args because the trigger/action
shapes are nested and vary by form. The other commands take a bare name. `execute`
(the default method) is a safe read-only alias for `list`.

### Self-description in both modes

DepthNet plugins describe themselves to the model in two forms: `getInstructions()`
(tag mode) and `getToolSchema()` (tool_calls mode). The contract plugin must carry
the same knowledge in both, and the rule **machine, not behaviour** applies to both
equally.

Describe the dashboard and the levers — what a flag *means*, what commands exist —
never what to *do* about a flag. Test each line: "this flag **means** …" / "you
**can** define / promote / revoke …" is mechanism, keep it. "When flag X is set,
**do** Y" is behaviour — drop it. Behaviour either belongs in a contract (if
deterministic) or in the model's own judgement (if not), never as a directive
wedged between them. `getToolSchema()` is the more tempting place to violate this
("when flag set, call …") — hold the line there especially.

---

## Placeholder

`[[active_contracts]]` — compact list of currently active contracts and any flags
they have raised, injected into the agent's context so the agent sees the *result*
of the metabolism (raised flags), and, on demand via `show`, the *cause* (the
contract). Transparency by default: a raised flag is always traceable to the
contract that raised it.

Example rendering:

```
Flags: retry_burst (by retry_burst_flag) | new_goal → goal_candidate (by idle_watch)
Active: retry_burst_flag(THR_C), idle_watch(THR_T), load_decay(DEC)
```

---

## Config (`getConfigFields`)

Engine-level settings live in the plugin config, exactly as `default_decay_rate`
and `max_states` live in MoodPlugin. The contracts themselves are data in the
`agent_contracts` table — config tunes the *engine*, not the contracts.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | checkbox | false | Enable the contract engine |
| `max_active_contracts` | number | 50 | Cap on simultaneously active contracts per preset (soft; enforced on promote) |
| `tick_min_seconds` | number | 60 | Minimum real seconds between ticks (coalesces bursts) |
| `default_suspend_flag` | text | `""` | Optional global `suspend_when` applied to all contracts |

User-authored contracts can also be supplied directly in the plugin config as a
JSON array of definitions, imported into the `agent_contracts` table on first run.

---

## Source tiers

Which data a contract trusts determines whether it may be vital. The split follows
**who writes the trace**: the system automatically, or the agent by choosing to.

| Tier | Sources | May be vital? | Why |
|------|---------|---------------|-----|
| First (objective) | chat message timestamps; vectormemory operation timestamps; heart-signal timestamps (written as a side effect of `registerSignal`, not a separate "log" act); engine counters; journal `recorded_at` (**time only**) | Yes | Written by the system without an agent decision |
| Second (digitised self-report) | journal `type`/`outcome`/`contains` **count**; mood vector values; heart valence | No — ordinary only | Depend on the agent's own declarative act |

Two consequences already baked into the spec above:

- A journal `THR_T` (time of last matching entry) is first-tier; a journal `THR_C`
  (count of matching entries) is second-tier.
- A `semantic` or `strict_then_semantic` match is never vital, regardless of source.

The engine does not enforce the tier of a `vital` contract — `source` is a free
string. This is an authoring discipline, surfaced in `show` output for review, not
a runtime guarantee. (The one *enforced* vital rule is the match-mode one above.)

---

## Integration hooks

The contract engine depends on Mood (via `StateVectorInterface`) and reads from
Journal/Heart, but those plugins must not depend on it, and must stay silent about
it unless asked. The mechanism is config, not cross-plugin imports.

Each source plugin that can feed contracts gains an opt-in config flag, default
**off** — e.g. `contract_hints_enabled` on JournalPlugin. The plugin shows
contract-related instructions only when its own flag is set — exactly as
`pulse_search_enabled` already gates the pulse syntax. A user running "just a
journal" sees nothing about contracts. (JournalPlugin already carries this flag and
emits a single neutral hint line when it is on.)

The flag is raised by the contract plugin when a contract matching that source is
created (written through `PluginMetadataService`, never by importing the plugin
class), or by the human directly. The source plugin reads its own config and
remains fully functional with the flag off, knowing nothing about the contract
plugin's existence. This is the provider-level `instanceof` pattern, applied at the
config layer instead of runtime wiring.

---

## Execution per tick

`ContractEngine::tick($preset, $globalSuspendFlag, $minIntervalSeconds)` runs one
preset. It loads active contracts via `ContractService`, evaluates each by form,
detects edges, runs actions, and persists runtime state through
`ContractRuntimeService`. No language model is involved; the engine works only with
`ContractDefinition` DTOs and reaches storage solely through the runtime service
and trace readers.

For each active contract, in order:

1. If `suspend_when` (or the plugin's `default_suspend_flag`) is currently a raised
   flag → bucket `suspended`, skip.
2. If the contract references a missing state dimension (no provider /
   `isAvailable()` false) → bucket `unsatisfiable`, skip.
3. Evaluate by form:
   - `THR_T`: seconds since the most recent trace matching `match` ≥
     `threshold_seconds`?
   - `THR_C`: count of traces matching `match` within `window_seconds` ≥
     `threshold_count`?
   - `ACC`: `state[target] = min(cap, state[target] + weight · dt)`; crossed cap?
   - `DEC`: `state[target] = max(floor, state[target] - rate)`; reached floor?
4. Edge handling against the previously recorded `triggered`:
   - rising (`not met → met`): run the action, record `triggered = true` →
     bucket `fired`.
   - falling (`met → not met`): record `triggered = false` (clears the derived
     flag) → bucket `cleared`.
   - held / idle: record `triggered` unchanged (keeps `last_evaluated_at` and thus
     `dt` fresh) → bucket `held` / `idle`.

The outcome of a tick is a `TickResult` (per-bucket contract names, `dt`,
`skipped`) — purely diagnostic, logged by the driver and surfaceable in the UI.

Ticking is coalesced to `tick_min_seconds`: if fewer than that many seconds have
passed since the last tick, the whole tick returns `TickResult::skipped` and does
nothing. This mirrors the existing `autobeat` guard in MoodPlugin/HeartPlugin
(which this engine generalises). The mood and heart decay loops are reference
implementations of the `DEC` form and are intended to migrate into the engine
rather than staying hard-wired in their plugins.

---

## The tick driver

The engine ticks one preset; the **driver** decides which presets to tick and
when to stand aside. This is `ContractTickRunner`, invoked by the `contract:tick`
console command, scheduled every minute.

- **Discovery & enablement.** The runner walks `PresetRegistry::getActivePresets()`
  and, for each, asks `PluginManager::getPluginInfoForPreset('contract', $preset)`.
  A preset whose contract plugin is absent or disabled is not a candidate (it is
  not even reported). From the resolved config it reads `tick_min_seconds` and
  `default_suspend_flag` and passes them to `tick()`.
- **Lock discipline.** Two locks, with different jobs:
  - `task_lock_{id}` is held by `AgentJobService` while the agent is **thinking**.
    If it is up, the agent may be moving the same mood vector the engine writes, so
    the runner **skips** that preset this tick (it does not wait or steal the lock).
    Thinking outranks metabolism; the engine catches up next minute. Contracts
    tolerate a missed tick by design (`dt` + coalescing).
  - `contract_tick_{id}` is the runner's own short lock (TTL 120s), guarding
    against two ticks of the **same** preset overlapping (a slow semantic journal
    match while cron fires the next minute).

  The check-then-lock pair is not atomic against `task_lock`, leaving a
  millisecond window where both could touch the mood blob. For an emotional vector
  that already drifts continuously this is noise, not a correctness problem; no
  distributed transaction is built for it.
- **Error isolation.** Each `tick()` is wrapped so one failing preset logs and is
  reported as `error` without sinking the rest of the run.

Scheduling lives in `routes/console.php`:

```php
Schedule::command('contract:tick')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

`everyMinute()` is a **ceiling** on cadence; the real pace is each preset's
`tick_min_seconds` (extra runs coalesce away inside the engine). `withoutOverlapping()`
guards at the command level; the per-preset `contract_tick_{id}` lock guards at the
preset level — both are intentional and non-redundant. `schedule:run` is kept alive
by supervisor (`[program:laravel-schedule]`), so this runs out of the box on any
booted DepthNet instance.

For hands-on debugging, `php artisan contract:tick --preset=N` ticks one preset and
prints a per-preset outcome table; it still respects enablement and locks.

---

## Authoring paths

Three entry points, one service, one source of truth:

1. **Agent (runtime):** `[contract define]…[/contract]` — the agent generates
   contracts from its own observed traces, the same way it already edits the
   ontology.
2. **Human (config):** JSON array in the plugin config.
3. **Template (preset):** JSON contracts in a preset template, expanded into
   `agent_contracts` rows on install.

All three converge in `ContractService`. There is no separate format and no
significant-whitespace syntax — plain JSON throughout.

---

## Out of scope

The engine does **not** decide what is worth contracting, when a pattern is stable
enough to promote, or what a raised flag means. Those are judgements and remain
with the agent. The engine executes contracts; it does not author or evaluate them.

See `docs/research/metabolism.md` (planned) for the reasoning behind the
algorithm/cognition boundary and a worked example of decomposing an agent's prompts
into contracts.
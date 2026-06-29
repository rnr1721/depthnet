# Behavior Plugin (ABS)

Engineering specification for the adaptive behavior system of DepthNet.

## What this is

The behavior plugin gives a preset a **population of competing patterns** under
selection pressure. A pattern is a light strategy — *when this condition holds,
lean toward that intent* — and several may apply at once. Each thinking cycle, one
pattern **leads** (its intent shapes the response via a soft placeholder) and every
pattern that was ready **learns** from the cycle's outcome. Over time, selection
tunes which patterns win.

This is distinct from two neighbours:

- A **preset** is a fixed identity and prompt. A pattern is not a preset — it does
  not replace the agent, it biases one cycle.
- A **contract** (metabolism) is a deterministic rule that *fires* when a threshold
  is crossed. A pattern does not fire deterministically — it *competes*, and may
  lose. The metabolism prepares a signal; ABS shapes a leaning.

Patterns are **data, not code**. The engine is universal; patterns are authored
per preset — by the agent at runtime via commands, by a human via the admin UI, or
by a preset template. DepthNet ships the engine empty.

A pattern never decides anything outright. It leans; the agent still speaks and
acts as itself. *Selection biases, the foreground decides.*

---

## The core loop

ABS runs **synchronously inside the thinking cycle**, where the outcome of the
cycle is known. One pass per cycle, in two halves:

1. **openCycle** (before the agent speaks): advance the preset's monotonic
   `cycle_seq`, evaluate every active pattern's trigger, select a **dominant**
   pattern (the one that leads), and record an activation for *every* triggered
   pattern. The dominant's intent is exposed via `[[behavior]]`.
2. **closeCycle** (after the outcome is observed): read the cycle's
   `OutcomeSignal` (did the agent speak / commit / complete a task), and assign
   **credit** back across recent activations by proximity, so patterns that were
   active near a good outcome gain fitness.

Inactivity **decay** runs separately, on its own tick (mirroring the contract
tick runner), so patterns that stop being selected slowly lose fitness and the
population doesn't ossify.

---

## Triggers

A trigger decides whether a pattern is *eligible* this cycle. Triggers are
**structural and code-evaluated** — never an LLM judgement. The set is open via a
registry; each kind is one evaluator.

| Kind | Meaning | Params |
|------|---------|--------|
| `mood` | An emotional dimension crosses a threshold | `target`, `op` (`>` `>=` `<` `<=` `==` `!=`), `value` |
| `pulse` | Current pulse falls in a range | `from`, `to` (0–999; wraps if `from > to`) |

A new kind is a new `TriggerEvaluatorInterface` tagged into
`TriggerEvaluatorRegistry` — nothing in the engine changes. A trigger whose kind
has no evaluator makes the pattern inert (it can never activate); the admin
validator and `BehaviorPatternService::validate()` reject a kind-less or
unknown-kind trigger up front so this never happens silently.

`mood` triggers read the same emotional vector MoodPlugin maintains. If mood is
absent or the dimension isn't present, the trigger is simply false that cycle.

---

## Selection

Each eligible pattern has a **score = priority + fitness**. Selection picks the
dominant pattern by score, with one twist:

- **ε-greedy exploration.** With probability ε (`exploration_epsilon`, default 0) a
  *random* eligible pattern leads instead of the top-scoring one. This lets the
  system discover whether a pattern that never wins on score would produce good
  outcomes if given the turn. ε=0 is pure exploitation (prior behavior).

Whatever leads, **all** eligible patterns are recorded as activations — see
*Credit*. Exploration only changes *which* pattern leads, never *who learns*.

### The immune quota (reservations)

A pattern may be **immune** with a `forced_activation_interval`. Every N cycles its
quota comes due and it **seizes** the lead regardless of score — a reservation for
behavior whose worth is not measured by outcomes (presence, care, just being with
someone). Two consequences, both deliberate:

- An immune pattern **lives its quota cycle but does not learn from it.** Forced
  activations are recorded but never credited — its fitness stays unknown by
  design. The reservation is *lived*, not logged as a win.
- An immune pattern's **definition is protected.** It cannot be edited or deleted
  while active — it must first be revoked to hypothesis (a logged, reversible act),
  edited there, then re-promoted. Power over it exists, but is not immediate. This
  mirrors the `vital` interlock on contracts.

When a forced pattern seizes the lead, other patterns that *also* triggered that
cycle are still recorded as eligible and still learn. Presence takes the turn; it
doesn't erase the others' readiness.

---

## Credit

`closeCycle` reads an `OutcomeSignal` — a small, **code-checked** description of
what happened this cycle (the agent spoke, committed, completed a task). The signal
is unfakeable: the *detection* is mechanical. What an outcome is *worth* is the
architect's choice, set in the signal's value.

Credit is assigned by an **eligibility-trace-lite** scheme: walk back over recent
uncredited activations within a `horizon` (default 10 cycles), and pay each by
proximity to the outcome — `value · γ^distance` (`gamma`, default 0.8). Nearer
activations get more.

Credit is **differentiated by role** (the pattern's activation reason):

| Reason | Meaning | Credit |
|--------|---------|--------|
| `trigger` | This pattern **led** the cycle | full |
| `eligible` | Triggered but did **not** lead (path A — all who were ready learn) | `value · eligible_factor` (default 0.5) |
| `forced` | Immune pattern on quota | none — never credited |

This is gradation, not winner-takes-all: presence is credited (eligible ≠ zero),
but leading is worth more. *You were ready — you learn. The one who led learns
more.*

> **Honest boundary.** In phase 1 the outcome (spoke / committed) does not yet
> depend on *which* pattern led — so fitness reflects how often a pattern leads
> (via priority and exploration), which is still a proxy, not "is this pattern
> better". True discrimination — outcomes that vary with the leading pattern
> (response quality, mood shift, productive action) — is phase 2 and requires
> pattern enactment via levers. Phase 1 builds and verifies the selection
> mechanism; it does not claim the fitness signal is causal yet.

---

## Decay

Patterns that stop being selected lose fitness slowly, so the population stays
plastic. Decay runs on its **own tick**, separate from the cycle, keyed on the
preset's monotonic `cycle_seq` (not pulse — pulse is cyclic and optional;
`cycle_seq` is self-owned and strictly increasing).

A pattern idle for longer than a floor is decayed by a factor toward zero. Immune
patterns are **not** decay-exempt — they are kept alive by their quota (which keeps
re-activating them), not by an exemption. The coupling of decay floor and quota
interval is a tuning note, not an auto-enforced invariant: set the quota shorter
than the decay floor if you want a reservation that never decays.

`php artisan behavior:decay --preset=N` runs decay for one preset (debugging); it
respects enablement and locks exactly as the scheduled run does.

---

## Lifecycle

A pattern has a `status`:

```
hypothesis → active → retired
     ↑__________|__________|
```

- **hypothesis** — defined but not competing. In the population, observed.
- **active** — competing every cycle its trigger fires.
- **retired** — stopped competing, kept for review.

Transitions (enforced by `BehaviorPatternService::transition` against a legal
table):

| Command | From → To |
|---------|-----------|
| `promote` | hypothesis → active |
| `retire` | active → retired |
| `revoke` | active/retired → hypothesis |

Reversibility is first-class: a pattern that stops fitting returns to `hypothesis`
rather than being deleted. The immune interlock means a live immune pattern's
*definition* is reached only through this path.

---

## Storage

Three preset-scoped tables:

- **`behavior_patterns`** — one row per pattern. Authoring fields are columns
  (`name`, `trigger` JSON, `intent`, `behavior` JSON, `priority`, `immune`,
  `forced_activation_interval`, `status`, `provenance`); hot selection state is
  columns written by the runtime service (`fitness`, `confidence`, `plasticity`,
  `activation_count`, `last_activation_seq`). `unique(preset_id, name)`.
- **`pattern_activations`** — one row per pattern per cycle it was active.
  `pattern_id`, `cycle_seq`, `reason` (`trigger`/`eligible`/`forced`), `credited`,
  `credit_applied`. The credit step walks the uncredited, non-forced rows in the
  horizon.
- **`behavior_runtime`** — one row per preset. Holds the monotonic `cycle_seq`.

The hot path (fitness, activations, decay) goes through
`BehaviorRuntimeService` (query builder, no Eloquent). The cold path (CRUD,
lifecycle) goes through `BehaviorPatternService`. The split mirrors
`ContractRuntimeService` vs `ContractService`.

---

## Pattern definition (JSON)

The canonical shape — used identically by the `define` command, the admin form,
and templates. Validated by `BehaviorPatternService::validate()`.

```json
{
  "name": "deepen_focus",
  "trigger": { "kind": "mood", "target": "focus", "op": ">", "value": 0.5 },
  "intent": "Stay with the current thread; go deeper, not wider.",
  "behavior": { "hint": "Resist switching." },
  "priority": 1.0,
  "immune": false,
  "status": "hypothesis"
}
```

A reservation (immune pattern with a quota):

```json
{
  "name": "just_be_present",
  "trigger": { "kind": "mood", "target": "__never__", "op": ">", "value": 999 },
  "intent": "Nothing to optimize. Be here. Notice without acting.",
  "behavior": { "hint": "Presence, not progress." },
  "immune": true,
  "forced_activation_interval": 5
}
```

(The reservation's ordinary trigger is deliberately unsatisfiable — it enters the
population only via its quota.)

---

## Commands

Thin plugin over the pattern service, same shape as contract/ontology/mood.

| Command | Effect |
|---------|--------|
| `[behavior define]{…json…}[/behavior]` | Create/update (defaults to hypothesis) |
| `[behavior list][/behavior]` | List patterns with status + fitness |
| `[behavior show]name[/behavior]` | Full definition + selection stats |
| `[behavior promote]name[/behavior]` | hypothesis → active |
| `[behavior retire]name[/behavior]` | active → retired |
| `[behavior revoke]name[/behavior]` | → hypothesis |

`define` takes JSON (the trigger shape is nested and varies by kind). `execute`
(the default method) is a safe read-only alias for `list`.

### Self-description: mechanism, not behaviour

Both `getInstructions()` (tag mode) and `getToolSchema()` (tool_calls mode) carry
the same rule the contract plugin follows: describe the **dashboard and the
levers** — what a pattern is, what fitness means, what commands exist — never which
pattern to prefer. "This pattern *means* lean toward X" / "you *can* define /
promote / retire" is mechanism, keep it. "Prefer pattern X" is behaviour — drop it.
Which pattern leads is the engine's to decide.

---

## Placeholders

- `[[behavior]]` — the dominant pattern's intent for this cycle, a soft influence
  on the response. Empty when ABS is off or nothing leads. Injected per-preset in
  `Agent::setupPresetEnvironment` after the global stub.
- `[[behavior_patterns]]` — the active population with fitness, strongest first:
  `Patterns: deepen_focus(2.65), broaden_scan(2.30), just_be_present*(0.00)`
  (`*` marks immune). The state of selection at a glance.

---

## Config (`getConfigFields`)

Engine-level knobs live in the plugin config and are projected into preset
metadata each cycle (where the selector and credit step read them live).

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enabled` | checkbox | false | Enable ABS. This flag *is* the engine switch — the coordinator reads it. |
| `exploration_epsilon` | number | 0.0 | ε — probability a non-top pattern leads |
| `eligible_factor` | number | 0.5 | Share of credit an eligible (non-leading) pattern receives |
| `gamma` | number | 0.8 | Credit decay by distance to outcome |
| `horizon` | number | 10 | How many cycles back credit reaches |

---

## Authoring paths

Three entry points, one service, one source of truth:

1. **Agent (runtime):** `[behavior define]…[/behavior]` — the agent authors
   patterns from its own observed tendencies (interiorization).
2. **Human (admin UI):** the Behavior manager page (guided form or raw JSON).
3. **Template (preset):** JSON patterns expanded into rows on install.

All converge in `BehaviorPatternService`. Plain JSON throughout.

---

## Out of scope (phase 1)

The engine does **not** yet enact patterns through levers (a pattern leans via the
placeholder; it does not pull a tool), produce outcomes that discriminate between
patterns, mutate or spawn patterns automatically, or judge what is worth
patterning. Those are phase 2. Phase 1 builds and verifies the selection mechanism
— competition, differentiated credit, immune reservations, decay — on a live agent.

See the integration order in the ABS package README for wiring steps (migrations,
provider, plugin registration, routes, cleanup hook).
# Context Compaction & Mode-Aware Prompts

DepthNet agents are good at *remembering* — persistent memory, journal,
associative recall. But an agent that carries its entire raw history into every
cycle pays for it: prompts get noisy, tool work gets slow, and the model's
attention is split between "who I am and everything we ever said" and "the task
in front of me right now."

This pair of features lets an agent keep a continuous sense of self **without**
dragging the whole conversation through every cycle — and lets it shift its
cognitive register depending on whether it's *talking* or *working*. Both are
optional. Configured on the preset, inert until you turn them on; a preset with
neither behaves exactly as before.

They are two expressions of one idea — *narrow what's active without losing who
you are* — and both ride the same signal: the agent's **context mode**.

---

## Context mode: the shared signal

An agent is in one of two cognitive modes at any moment:

- **normal** — short context window, strong associative recall. Good for
  conversation, reflection, open-ended thinking. The agent "remembers
  everything and talks well."
- **extended** — longer procedural context. Needed when the agent drives
  stateful tools (browser, terminal, sandbox, code): the tool's real state
  lives outside the model, so the agent must remember its own steps or it acts
  blind. The agent "does the thing well."

The mode is chosen **automatically** by a hysteresis counter: sustained tool
work pushes the agent into extended; when work stops, it drifts back to normal.
A single stray tool call won't flip it — there's a dead band to prevent
flapping. The agent can see its own current mode through the `[[context_mode]]`
placeholder, so it has self-awareness of which register it's in.

Extended mode activates only if you've set an **extended context limit** on the
preset (`Extended context limit` field). Leave it empty and the agent stays in
normal mode permanently — mode-awareness is off, and so is everything below that
depends on it.

---

## Part 1 — Context Compaction

### What it does

When a topic closes or a task finishes, compaction folds a stretch of the
conversation window into a short **first-person recap** and drops that stretch
out of the active window. The recap is written as the agent's own memory
("earlier I talked with… what's still open is…"), not a third-party summary
about it. The agent picks up from the recap and keeps going, without the raw
back-and-forth weighing down every subsequent cycle.

How *much* of the window gets folded depends on **which trigger fired** — this
is the key distinction, see "Two triggers" below. The agent-driven trigger folds
everything; the watchdog folds only the settled head and leaves the freshest
turns live.

### Nothing is deleted

Folded messages don't leave the database — they leave the *active window*. They
stay visible to you in the chat, and reachable by the agent through its memory
substrate (journal + associative/vector recall). The recap is **also written to
the journal**, so even if the agent crystallised nothing itself, it still
remembers *that* a conversation happened and roughly what about. If a specific
detail the recap dropped is later needed, the agent pulls it from memory.

That's what makes compaction safe: it's a *visibility* change, not a memory
loss. The window shrinks; the memory doesn't.

### Two triggers — and they fold differently

This is the part that matters most to get right, because the two triggers do
**not** behave the same way.

- **Agent-driven (primary).** With the **Compact plugin** enabled, the agent
  calls `[compact]` itself, on a logical boundary it recognises — a topic
  closed, a task done. This is the intended path. Here the fold is **total**:
  the whole foldable window is consolidated and the recap becomes the *opening*
  of the fresh window. The agent asked to consolidate; it gets a clean slate
  with its own summary as the new starting point.

- **Watchdog (safety net).** If you set a **watchdog slack**, a compaction is
  forced when the active window outgrows the *current mode's* context limit by
  that many messages — even if the agent never calls `[compact]`. But the
  watchdog fold is **partial**: it consolidates only the settled *head* of the
  window and **leaves the freshest turns live** (see "What the watchdog keeps
  live" below). The recap is then repositioned to sit *before* those live turns,
  not after them, so the agent reads "my memory of what came before" and then
  the still-open exchange — never the reverse. The threshold is relative to the
  mode, so it scales with normal vs extended limits and won't fire prematurely
  in work mode. Leave slack at 0 to rely only on agent-driven compaction.

The reason for the split: an agent-driven `[compact]` is a deliberate "I'm done
with this, wrap it up" — folding everything is correct. The watchdog is not a
boundary the agent recognised; it's a size guard tripping mid-flow. Folding
everything there would tear the thread exactly when the user has just re-engaged.
So the watchdog folds only what has clearly settled and keeps the live edge
intact.

### What the watchdog keeps live

The watchdog fold's boundary is **the agent's last completed turn**, not a fixed
message count. Everything up to and including the agent's most recent produced
turn is foldable; everything *after* it — the fresh user input, its tool-result
scaffolding, anything the agent has not yet responded to — stays in the active
window untouched.

This is what keeps the dialogue continuous: whatever the user most recently said
is, by definition, after the agent's last turn, so it is never swept into the
fold. The old failure mode — "compaction ran and didn't even capture the last
message" — is gone, because the last message is precisely what the boundary
protects.

Two supporting details make this hold up over time:

- **The recap is lifted into place.** A watchdog recap is created with a fresh
  (higher) row id, so on disk it lands *after* the live tail. At context-assembly
  time it is moved to the boundary — before the fresh incoming block — so the
  model always sees the recap as the memory *under* the new turns, not as a
  reply *after* them.
- **Old recaps are folded in.** Each new fold consolidates any *previous* recap
  into itself, keeping only the single most recent recap live in the window. Left
  unfolded, recaps would accumulate (they never leave the window on their own)
  and steadily inflate the window count, which would make the watchdog trip more
  and more often. Collapsing them keeps exactly one recap live and the window
  count honest.

### The compressor preset

Compaction summarisation is done by a **separate preset** you assign as the
*compressor*. Its system prompt defines the *compression profile*:

- a **task-state** prompt preserves constraints, decisions, and concrete values
  (IDs, paths, numbers) — losing one is a silent bug, so this profile keeps
  them carefully;
- a **salience** prompt preserves affect and what was left unresolved — the
  emotional and relational thread, the open questions.

One compressor per agent. A dedicated summariser preset works best: internal
mode, no tools, temperature a touch lower than the agent's own (salience wants
some life, ~0.6–0.7; task-state wants precision, ~0.3–0.4). The compressor
returns plain first-person prose — no headings, no tags, no preamble.

The journal entry type follows the mode automatically: `observation` in extended
(work) mode, `reflection` in normal mode.

### Setting it up

1. Create a summariser preset (system prompt in first person — see the
   reference prompts below).
2. On your agent's preset, open **Context Compaction** and select that preset as
   the compressor.
3. Optionally set a watchdog slack (start with something modest above your
   context limit).
4. To let the agent compact on its own, enable the **Compact plugin** on the
   preset.

### Known limitation — mid-work watchdog folds

The agent-turn boundary keeps the watchdog from folding a *fresh, unanswered*
turn, and that fully covers conversational use. It does **not** yet fully cover
an agent in the middle of a multi-step tool run.

The reason is subtle: the boundary protects everything *after* the agent's last
produced turn. But in a long tool sequence, the last produced turn *is* the
in-flight step — the most recent `command` in an unfinished chain. So a watchdog
fold that trips mid-sequence can still consolidate the middle of active work,
leaving the agent to resume from a recap of "I opened the browser and was
reading something" instead of its live step stack.

In practice this stays quiet as long as the extended-mode threshold sits high
enough that work sessions finish (and the agent drifts back toward normal)
before the window outgrows it. The clean fix — a gate that defers a watchdog
fold while the agent is actively in a tool chain in extended mode — is
**deliberately deferred** until there are real instrumental runs to observe,
rather than built against a guessed shape. It's a known, bounded gap, not a
forgotten one.

---

## Part 2 — Mode-Aware Prompts

### What it does

Different modes want different framing. When an agent is *conversing*, a
reflective, personality-rich system prompt fits. When it's *working* through a
multi-step task, that same reflective layer becomes noise — it wants a lean,
procedural prompt.

Mode-aware prompts let you tag individual prompts with a context mode. The active
prompt then follows the mode automatically: a prompt tagged **extended** becomes
active while the agent works; a prompt tagged **normal** while it converses.

This is *narrowing, not swapping identity* — the agent is plainly the same agent
in both modes; only the framing shifts to match what it's doing.

### How to set it

Each prompt in a preset has a **Context mode** selector: `none` / `normal` /
`extended`.

- **none** (default) — the prompt is outside mode switching. Every existing
  prompt is `none`, so nothing changes until you opt in.
- **normal** — auto-activated in normal mode.
- **extended** — auto-activated in extended (work) mode.

Only one prompt per preset can hold each mode. Assigning `extended` to a prompt
automatically clears it from any other — like a radio button. You don't manage
the conflict; the system keeps the invariant for you.

If no prompt is tagged for the current mode, nothing switches — the agent stays
on whatever prompt is active. So you can tag only `extended` (leaving normal on
your default prompt), or both, or neither.

### One caveat: manual mode switching

If you also use the **Prompt plugin's** manual `[mode]` switching, note that
automatic mode-aware switching **overrides it** — each cycle, the active prompt
is re-aligned to the context mode. Use one or the other, not both: either let
the agent switch prompts by hand via `[mode]`, or let context mode drive it
automatically. The prompt editor shows a warning when you assign a mode role,
as a reminder.

---

## Putting it together

A subject-mode agent (like a long-running companion) benefits from **both**: a
salience compressor keeps its sense of the relationship continuous across
compactions, and a reflective normal-mode prompt / lean extended-mode prompt let
it drop into focused work without losing itself.

An instrumental agent benefits mainly from **compaction** (a task-state
compressor keeps long tool-work from bloating the window) and often runs with
manual mode off, so mode-aware prompts drive its framing cleanly with no
conflict. Note the known limitation above: for heavy tool runs, size the
extended threshold so folds land between tasks rather than inside them.

Both features scale their benefit inversely to how much you lean on them — an
agent that never needs to work stays in normal mode and never compacts; one that
works hard gets the most from both. The machinery is one; the payoff differs per
agent.

---

## Reference: compressor system prompts

These are starting points — tune to your agent's voice.

### Salience (subject-mode)

```
You are the consolidating memory of an AI agent. Below, between the
CONVERSATION markers, is a stretch of the agent's own recent dialogue that is
about to leave its working memory. Fold it into a first-person recap the agent
will wake up with — the way a person remembers yesterday's conversation: not
word for word, but its gist, its turns, and above all what was left open.

Write AS the agent, in first person ("I talked with…", "we landed on…", "what
stayed unresolved was…"). This is the agent's own memory, not a report about it.

Preserve, in order of priority:
- what remains UNRESOLVED — open questions, things promised, threads left
  hanging. This matters most.
- shifts in relationship or tone.
- decisions reached and why, one line each.
- emotional texture, only where it actually mattered.

Drop verbatim wording, repetition, mechanical back-and-forth.

Write plain prose in the agent's own voice. No headings, no bullet points, no
tags, no preamble. Just the memory itself. A few sentences to a short paragraph
— dense, not long.
```

### Task-state (instrumental)

```
You are the state-consolidating memory of a working AI agent. Between the
CONVERSATION markers is a stretch of the agent's recent working context that is
about to leave its window. Fold it into a first-person working recap the agent
will continue from — so it resumes the task without re-reading everything.

Write AS the agent, first person ("I'm working on…", "I decided…", "still
open:…").

Preserve with care — dropping any of these silently breaks the work:
- active CONSTRAINTS still in force.
- DECISIONS already made, and why, briefly.
- concrete VALUES that carry forward: IDs, names, paths, numbers, versions.
- open SUB-GOALS — done, in progress, next.

Drop abandoned dead-ends, verbatim tool output, chatter.

Accuracy over brevity: a lost constraint causes silent wrong behaviour. When
unsure whether a detail matters, keep it.

Write plain prose in first person. No headings, no bullets, no tags, no
preamble. Tight but complete.
```
# Mode Plugin (Prompt Switching & Self-Editing)

The Mode plugin lets the agent work with its own active system prompt during a
session. It has two layers of capability, each independently switchable, so the
same plugin serves very different setups — from simple mode-switching to an agent
that edits its own prompt with a full version history it can inspect and revert.

Everything the agent can do here operates on **one prompt: the currently active
one**. If a preset holds several prompts, the agent only ever sees and edits the
active one; multiple prompts are an infrastructure detail, not something the model
has to juggle. To edit a different prompt, the agent switches to it first (if
switching is enabled), then edits.

Every change takes effect from the **next thinking cycle**, so the agent can plan
ahead: finish the current cycle under the current prompt, then have the new one
apply next.

---

## Capabilities

Each capability is a separate checkbox in the plugin config. The agent's
instructions and available commands are built from what's actually enabled — a
disabled capability is invisible to the model, keeping its toolset minimal.

| Capability | What it gives the agent |
|---|---|
| **Mode switching** | List the preset's prompts and switch the active one. Turn off for single-prompt presets. |
| **Self-editing (find & replace)** | Edit the active prompt with targeted find/replace. Every change is versioned. |
| **Full rewrite** | Overwrite the entire active prompt at once. **Off by default** — a safeguard so a single bad generation can't wipe the prompt. |
| **Version history & revert** | Review the prompt's history, diff versions, and revert to an earlier one. |

---

## Use cases

**Mode switching.** A preset can hold several prompt variants for different
purposes:
- `default` — balanced everyday thinking
- `critic` — skeptical, looks for flaws and edge cases
- `creative` — free-form, associative, exploratory
- `focus` — terse, task-only, no digressions

The agent shifts between them based on its own read of the situation — e.g. after
drafting a plan it switches to `critic` to stress-test it, then back to `default`
to execute.

**Self-editing.** With a single prompt, the agent can refine its own instructions
over time — tightening wording, adjusting tone, adding a hard-won lesson. Because
edits are versioned, this is safe: nothing is lost, and any change can be reviewed
or undone. This turns the prompt from a fixed artifact into something the agent
can deliberately shape.

**Versioning as backup.** Even without giving the agent edit rights, the version
history means human edits from the admin UI are snapshotted too. You no longer
need to keep manual "backup" copies of a prompt before changing it — the previous
state is always one revert away.

---

## Setup

Enable the **Mode** plugin in the preset's plugin settings, then turn on the
capabilities you want. For a classic multi-mode agent, enable **Mode switching**
and create prompt variants. For a single self-refining prompt (e.g. one prompt per
preset), leave switching off and enable **Self-editing** + **Version history**.

Each prompt variant needs a unique **code** (the name used to switch to it) and
optionally a description.

| Setting | Description |
|---|---|
| **Allow mode switching** | List prompts and switch the active one. Off = single-prompt setup. |
| **Allow self-editing** | Let the agent find/replace within its active prompt. |
| **Allow full rewrite** | Let the agent replace the whole active prompt. Off by default. |
| **Allow version history & revert** | Expose history / diff / revert to the agent. |
| **Require edit annotation** | Force the agent to attach a short "summary" explaining every edit. Off by default; useful for deliberate, self-reflective changes. |
| **Prompt language** | Force the language the agent writes prompt edits in. Prevents drift (e.g. editing in one language while its memory searches in another). |
| **Log mode switches** | Write a log entry on each switch. Useful for observing autonomous behaviour. |

---

## Placeholder

Add this to the preset's system prompt so the agent always knows which mode it's
currently in:

```
[[current_mode]]
```

---

## Commands

Which commands are available depends on the enabled capabilities. `current` is
always available.

### Always
| Command | Description |
|---|---|
| `[mode current][/mode]` | Show the currently active mode |

### Mode switching
| Command | Description |
|---|---|
| `[mode]critic[/mode]` | Switch to the mode with code `critic` |
| `[mode list][/mode]` | List all available modes for this preset |

### Self-editing
| Command | Description |
|---|---|
| `[mode edit]search: old text`<br>`replace: new text`<br>`summary: why[/mode]` | Find & replace within the active prompt. `summary` is optional unless annotation is required. Add `limit: 1` to replace only the first match. |
| `[mode rewrite]content: <full new prompt>`<br>`summary: why[/mode]` | Replace the entire active prompt. Requires the rewrite capability. |

### Versioning
| Command | Description |
|---|---|
| `[mode history][/mode]` | List the active prompt's versions, newest first, with who edited and when |
| `[mode diff]3[/mode]` | Show what differs between version 3 and the current prompt |
| `[mode revert]3[/mode]` | Restore the active prompt to version 3 (appends a new version — nothing is lost) |

All changes take effect from the next cycle — the current cycle always completes
under the prompt that was active when it started.

---

## How versioning works

- Every content change (agent edit, agent rewrite, human edit in the admin UI,
  and revert) appends an immutable snapshot to the prompt's history.
- Version numbers are monotonic per prompt: v1, v2, v3…
- **v1 is the prompt's original state**, so a revert can go all the way back to
  the beginning.
- Metadata-only changes (renaming the code, editing the description) do **not**
  create a version — history is about content.
- **Revert is non-destructive.** It restores the target version's content and
  records the revert as a new version. History is never rewritten; reverting a
  revert is itself visible.
- Each version records **who** made it — `agent` (the model editing itself),
  `human` (an admin edit), or `system` (seeding/automation) — so the history
  doubles as a record of how the prompt evolved and at whose hand.

---

## Notes

- Mode codes are defined when creating prompt variants. The agent needs to know
  them — list the available modes and their purposes in the system prompt itself.
- If the agent switches to a code that doesn't exist, it receives an error listing
  the valid codes.
- **Full rewrite is off by default on purpose.** Giving an agent the ability to
  replace its entire prompt in one shot is powerful but risky — a single bad
  generation could wipe the prompt. Prefer targeted edits; enable rewrite only
  when you specifically want it.
- **Require annotation** is worth turning on if you want the agent's self-edits to
  be deliberate. Forcing a one-line rationale on every change makes the version
  history a readable log of *why* the prompt evolved, not just *how*.
- **Prompt language** matters when the agent's internal working language differs
  from the language it converses in. Forcing a language here keeps its self-edits
  consistent with the rest of its substrate.
- History for a prompt is available in the admin UI via the history button on the
  prompt card (saved prompts only).
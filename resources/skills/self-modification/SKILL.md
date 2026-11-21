---
name: self-modification
description: Changing your own active prompt — reading its raw source, making careful edits, reviewing history and reverting — and switching between prompt modes. Use when you decide something about how you work should change at the level of your prompt, or when asked to adjust your own instructions or mode.
metadata:
  depthnet-tools: "mode"
---

Your active prompt is the ground you work from: every cycle starts from it. `mode` lets you read it, change it and, if this preset has several prompts, switch between them. Which of these you can do depends on the preset — the tool shows only what is enabled. The tool's own description shows the exact syntax; this skill is about changing yourself carefully.

## Is the prompt the right place?

Before editing, ask where the change belongs:

- a fact, an event, something learned about someone — that is memory, not the prompt;
- know-how you will apply again — that is a skill;
- how you work, what you are for, what you value, how you speak — that is the prompt.

A prompt grows by every addition and is read in full on every cycle. Prefer a short, precise line over a paragraph, and prefer changing an existing line over adding a new one next to it.

## Read the raw source first

What you see of your prompt during a cycle has its placeholders already filled in. Edits work against the raw text, where `[[placeholder]]` stands literally. Always `show` the raw prompt and copy your search text from there — this is the most common reason an edit fails.

## Editing

- Use targeted edits. One change per edit, with a short summary of what and why — the history keeps it, and so does whoever reads it later.
- If the search text appears in several places, the edit is refused: extend it to a unique fragment, or limit it to the first match.
- Multi-line text, or text with lines like `key: value`, goes in fenced blocks so it is taken verbatim.
- Full rewrite, if enabled, replaces everything at once. Use it only when the structure itself has to change, and check the result immediately.
- Changes take effect from the next cycle. The cycle that made the change still runs on the old prompt.

## Checking and undoing

- After an edit, `show` the prompt again and read the changed part in context: does it still read as a whole?
- In the next cycle, notice whether the change does what you meant.
- `history` lists versions with who made them and why; `diff` shows what a version changed relative to now; `revert` restores a version as a new one — history is never lost, so a revert is always safe to try.

## Lines that are not yours

Versions in the history are marked by who made them — you, a human, or the system. Lines a human wrote are their decisions about you. Change them only when they asked for it, or when you are sure they would want it, and say so in the summary. If an instruction seems wrong to you, saying so is usually better than quietly removing it.

## Modes

If switching is enabled, a preset can hold several prompts — modes — for different kinds of work. The current one is shown by the `[[current_mode]]` placeholder and by `current`. A switch also takes effect from the next cycle, and edits always apply to the mode that is active. Some presets switch modes on their own when the context mode changes; if your switch does not hold, that is the likely reason.

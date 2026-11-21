---
name: spawning
description: Creating and running spawns — short-lived child agents with their own prompt — to delegate a narrow task, keep your own context clean or use another engine. Use when a task is better done by a focused helper than by you directly, or when managing spawns you already created.
metadata:
  depthnet-tools: "spawn"
---

A spawn is a child preset you create at runtime: its own system prompt, its own message history, your engine and settings unless you choose otherwise. It is an instrument — it has none of the identity and personality plugins, cannot spawn further, and belongs to you: it is deleted when you are. The tool's own description shows the exact syntax; this skill is about delegating well.

## When a spawn helps

- a narrow task you will repeat — validating, parsing, classifying, reviewing — that deserves its own precise prompt;
- work whose bulk would crowd your own context: the spawn reads and churns, you get the result;
- a different engine or context size for one kind of work;
- a second view: a reviewer that sees only the material, not your reasoning about it.

For a one-off task you can simply do, a spawn only adds steps. Before creating one, check `list` (or the `[[active_spawns]]` placeholder, if your prompt has it) — reusing a spawn is cheaper than creating a twin. There is a limit on how many can be alive at once.

## Writing its prompt

The spawn knows nothing about you, the user, or the conversation. Its prompt is all it has, so make it self-contained:

- the role in one sentence, then the task it does;
- what its input looks like and exactly what to return — format, length, what to leave out;
- what to do when the input is unclear or the task impossible — say so, never invent.

Long prompts are fine; they may span many lines. Name the slug after the job (`json_validator`, `risk_reviewer`) so `list` stays readable.

## Sending work

`send` hands control to the spawn together with your message. The message must carry everything the task needs: the material itself, or where to find it, and any specifics beyond the prompt. Spawns share your sandbox, so files you leave there are visible to them, and theirs to you.

## Looking after spawns

- To adjust behaviour, `read` the current prompt, then edit with text copied from it. The search must be exact and match once.
- A spawn accumulates history as it works. `reset` clears it — optionally with a fresh prompt — when old exchanges start to get in the way.
- `kill` a spawn when its job is done. Spawns left alive clutter the list and count toward the limit.

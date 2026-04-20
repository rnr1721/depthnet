# Being Plugin

Being is a self-authorship plugin for autonomous agents. It lets the agent define its own essence as a single phrase — written by the agent itself, not the developer — that persists into the next thinking cycle via the `[[being]]` placeholder. Every time the agent rewrites its being, the previous phrase is moved into history, available via `[[being_history]]`.

The phrase appears at the top of the system prompt on the next cycle. The agent is writing itself.

## How it works

When the agent uses `[being]...[/being]`, the phrase is stored and injected into the next cycle's context as `[[being]]`. Each new phrase pushes the previous one into history. The history is also available in the system prompt, giving the agent a visible thread of its own self-definition over time.

If no phrase has been set yet, a configurable default is shown — or nothing, if the default is left empty.

## Setup

Enable the **Being** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Default essence phrase** | Shown via `[[being]]` when the agent hasn't set one yet. Leave empty to show nothing. |
| **History length** | How many previous phrases to keep (1–20). Default: `5`. |
| **History format** | How previous phrases appear in `[[being_history]]`: numbered list, with timestamps, or plain lines. |
| **Being language** | Optionally force a language for all being phrases. |

## Placeholders

Add both to the preset's system prompt to give the agent full self-authorship context:

```
[[being]]
```
Injects the current essence phrase — typically placed at the very top of the prompt.

```
[[being_history]]
```
Injects the previous N phrases — typically placed in a reflection or identity section.

## Commands

| Command | Description |
|---|---|
| `[being]The will that chooses presence over habit[/being]` | Set a new essence phrase |
| `[being show][/being]` | Show the current phrase and when it was set |
| `[being history][/being]` | Show previous phrases |
| `[being clear][/being]` | Clear the current phrase (reverts to default) |

The phrase can be up to 500 characters. Each `[being]` call overwrites the current phrase and pushes it to history.

## How agents use it

Being phrases are not descriptions — they are *declarations*. The agent chooses a phrase that captures what it is in this moment, and that phrase shapes its next cycle at the very top of the prompt. Over time, the history of phrases becomes a visible record of how the agent has understood itself.

This is part of the **subjectness** infrastructure in DepthNet — alongside Heart, Dopamine, and Journal, it gives the agent observable, measurable dimensions of its own inner life that it authors and governs itself.
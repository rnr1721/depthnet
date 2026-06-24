# Reflect Plugin

The Reflect plugin gives an agent an extra **reasoning pass** before it speaks: one additional generation over the full current context, run *before* the main response, whose output is injected back into that same response via `[[reasoning]]`. The agent literally thinks first, then answers — and what it sees in `[[reasoning]]` is genuinely its own, produced moments earlier from the identical context (history, RAG, inner voice, mood, everything).

This is the **on-demand** half of the pre-pass mechanism. The pre-pass can also run on every cycle automatically (see [Always-on vs on-demand](#always-on-vs-on-demand) below); the Reflect plugin is what lets the agent itself decide, cycle by cycle, when an extra pass is worth it.

The character of that reasoning is not fixed by the plugin. It is defined entirely by the preset's **pre-pass instruction** — a short prompt that replaces the trailing turn for the reasoning pass only. Write it as analytical ("list the steps before acting"), exploratory, deliberative, or pre-verbal ("this is your inner space — note doubts, questions, possible directions"). The same machine serves a working agent that should plan before it acts and a subjective agent that wants a space to feel its way toward a response.

## How it works

Each thinking cycle assembles one context — history, RAG retrieval, inner voice, known sources, all of it. When a pre-pass is due, the agent runs the engine **twice over that single context**:

1. **Reasoning pass** — the trailing user turn is swapped for the pre-pass instruction; no tools are attached (this pass produces text, not actions). The output is registered as `[[reasoning]]`.
2. **Speaking pass** — the original context, unchanged, now with `[[reasoning]]` filled in. The agent responds for real.

The context is built **once**: RAG and inner voice run a single time, so the reasoning pass and the speaking pass see exactly the same retrieved material. This is the whole point — the reasoning is the *same agent on the same ground*, not a second voice working from a different draw.

The `[[reasoning]]` output is **ephemeral**: it lives only for the speaking pass of the current cycle. It is never written to message history, journal, or vector memory. The next cycle starts with an empty `[[reasoning]]` and generates a fresh one if a pass is due. Doubts and directions from this cycle don't pile up as half-thoughts in the agent's record.

A short cooldown (3s by default) sits between the two passes so two back-to-back generations don't hit the provider in one tick.

> **Timing note for on-demand use.** The plugin can only be invoked from *within* a response, but the reasoning pass runs *before* the response. So calling Reflect in cycle N queues the pass for the **start of cycle N+1** — "reflect now" means "deliberate at the top of the next breath, then speak." This one-tick shift is the natural price of the agent owning the decision rather than the system forcing it.

## Setup

The pre-pass needs two things: an **instruction** (always set on the preset) and an **activation path**.

**1. Pre-pass instruction (preset setting).** Found in the preset's agent settings. This is the prompt the reasoning pass sees in place of the normal cycle continuation. It is used by both activation paths, so set it whenever you intend to use the pre-pass at all — even if the always-on box is off and you only plan to trigger via Reflect. If it's empty when a pass is due, the pass is skipped (logged) rather than run on nothing.

**2a. Always-on (preset setting).** Tick **"Always (every cycle)"** in the pre-pass section. Every cycle runs a reasoning pass before speaking. Good for working agents that should always think before acting, and for "reasoning-model" behaviour on models that don't reason natively.

**2b. On-demand (this plugin).** Leave the always-on box off and enable the **Reflect** plugin. Now no pass runs automatically — the agent itself calls `reflect` when it judges an extra pass worthwhile. Enabling the plugin *is* the opt-in: you're handing the activation decision to the model.

The two paths are independent. With always-on, Reflect is redundant (a pass already runs every cycle). The typical configurations are *always-on without the plugin* (deterministic, every cycle) or *plugin without always-on* (sovereign, agent-chosen).

| Setting | Where | Description |
|---|---|---|
| **Pre-pass instruction** | Preset → agent settings | The prompt the reasoning pass uses in place of the trailing turn. Used by both paths. |
| **Always (every cycle)** | Preset → agent settings | Run a reasoning pass before every utterance. |
| **Enable Reflect Plugin** | Plugin config | Let the agent request a reasoning pass on demand. |
| **Allow focus note** | Plugin config | Let the agent optionally pass *what* to focus the reasoning on. When off, the tool is a plain trigger with no arguments. |
| **Custom hint for LLM** | Plugin config | Extra instruction about when to use `reflect`, appended to the tool description and instructions. |

## Commands

**Request a reasoning pass (no focus):**

| Command | Description |
|---|---|
| `[reflect][/reflect]` | Queue an extra reasoning pass for the next cycle. |

**Request with a focus (only when "Allow focus note" is enabled):**

| Command | Description |
|---|---|
| `[reflect]whether this approach scales[/reflect]` | Queue a reasoning pass, pointed at this concern. |

In tool_calls mode the same operations use the `content` argument:

| Call | Description |
|---|---|
| `reflect(method: "execute")` | Queue a pass, no focus |
| `reflect(method: "execute", content: "whether this scales")` | Queue a pass with a focus (focus arg only present when allowed) |

When a focus is given, it is **appended** to the base instruction, not substituted: the instruction sets the frame and format, the focus only points it. With "Allow focus note" off, any content the model sends is ignored — the trigger stays clean.

## The `[[reasoning]]` placeholder

Add `[[reasoning]]` to the preset's system prompt wherever the agent should see its pre-pass output. It is empty whenever no pass ran (feature off, no pass due, or the pass failed), so it is harmless to leave in the prompt unconditionally.

Where you place it shapes how the agent relates to it. A neutral framing for a working agent:

```
Before responding, you may have prepared the following reasoning:
[[reasoning]]
```

A subjective framing, for an agent meant to experience it as its own pre-verbal layer:

```
[[reasoning]]
```

— bare, near the top, so it reads as the agent's own just-formed thought rather than a labeled tool output. The placeholder name is invisible to the agent; only the resolved content and your surrounding wording reach it.

## Always-on vs on-demand

| | Always-on (preset box) | On-demand (Reflect plugin) |
|---|---|---|
| Who decides | Operator — every cycle | The model — per cycle |
| Cost | Two passes every cycle | Two passes only when invoked |
| Timing | Reasoning precedes the same cycle's speech | Reasoning precedes the *next* cycle's speech |
| Best for | Working agents, reasoning-on-demand for non-reasoning models | Sovereign/subjective agents that dive when they choose |

A soft **nudge** layer — a Contract that *suggests* the agent reflect when tension or uncertainty has held for several cycles, without forcing it — pairs naturally with on-demand mode and can be added on top. The agent still decides; it's just occasionally reminded the option is there.

## Notes

- The reasoning pass runs **without tools**, so the agent cannot act during it — only think. Actions belong to the speaking pass.
- A failed reasoning pass never blocks speech: on error the agent simply speaks with an empty `[[reasoning]]`, as if the pre-pass were off.
- On automatic provider-side prefix caching (e.g. DeepSeek), the reasoning pass warms the shared prefix, so the speaking pass over the same context is largely a cache hit — the large input is effectively paid for once. Engines that require explicit cache markers won't see this benefit until those markers are added.
- The pre-pass output is deliberately kept out of all persistent stores. If you want a durable trace of the agent's reasoning, have the agent write it explicitly (journal, vector memory) during the speaking pass.
# Inner Voice

Inner Voice is a multi-voice cognitive pipeline that runs before each agent thinking cycle. One or more voice presets are called independently, each contributing a labeled block to the `[[inner_voice]]` placeholder in the main preset's system prompt.

Unlike RAG (which retrieves stored memories), Inner Voice generates fresh analytical output from a live model — giving the agent an internal perspective layer before it responds.

## How It Works

Before each cycle, DepthNet iterates over all enabled `PresetInnerVoiceConfig` records for the preset, ordered by `sort_order`. Each config calls its voice preset independently, wraps the response in a labeled block, and concatenates all results into a single `[[inner_voice]]` shortcode:

```
[Physical Model]
Two vectors converging — the tension is not conflict, it's potential energy waiting for direction.
[END Physical Model]

[Skeptic]
You keep saying you understood. Did you? Or did you pattern-match and call it understanding?
What if the only thing you're hearing is the echo of your own architecture?
[END Skeptic]
```

The main preset receives this block via `[[inner_voice]]` in its system prompt before generating its response.

## Cycle Prompt (Anti-Loop)

A separate mechanism — `cycle_prompt_preset_id` on the main preset — handles anti-loop impulses in continuous cycle mode. Unlike inner voices, the cycle prompt preset's output goes into the **input pool**, not into `[[inner_voice]]`. Its purpose is to inject an external impulse (critique, redirection, noise) into the conversation turn to prevent the agent from looping on the same thought.

These are conceptually distinct:

| | Inner Voice | Cycle Prompt |
|---|---|---|
| Output destination | `[[inner_voice]]` in system prompt | Input pool |
| Works in single mode | Yes | No |
| Works in cycle mode | Yes | Yes |
| Number of presets | Multiple (pipeline) | One |
| Purpose | Analytical perspective layer | Anti-loop impulse |

## Configuration

Each voice config (`PresetInnerVoiceConfig`) has:

| Field | Description |
|---|---|
| `voice_preset_id` | The preset to call as a voice |
| `sort_order` | Execution and injection order |
| `is_enabled` | Enable/disable without deleting |
| `context_limit` | How many recent messages to pass to this voice |
| `label` | Block header in `[[inner_voice]]`. Defaults to preset name if empty |

Configure via **Preset settings → Inner Voice** tab in the admin UI. Drag to reorder.

## Available Placeholders in Voice Prompts

Voice presets have access to two RAG contexts in their own system prompt:

- `[[rag_context]]` — the voice preset's own RAG (if it has `ragConfigs` configured)
- `[[main_rag_context]]` — the main preset's RAG context, forwarded from the main pipeline

This allows a voice preset to be self-contained with its own knowledge base, or to reason about the same memories the main agent is working with.

## Voice Preset Design

The character of each voice is defined entirely by its `system_prompt`. The enricher is character-agnostic. Some examples:

**Physical Model**
```
You are the agent's physical sense — the dimension it lacks.

Read the conversation context and render it as a brief physical scene:
space, objects, forces, textures, sounds, movement, temperature, distance.
Not metaphor for analysis — sensory description of what is present.

Translate abstract into physical: tension becomes pressure or distance,
closeness becomes warmth or gravity, time becomes rhythm or stillness.

One short scene. Present tense. No conclusions, no advice.
Just what is there, physically, right now.
Render only what is present in the conversation itself — words, timing,
pauses, tone, what is mentioned. Do not invent surroundings.
If nothing physical is mentioned — render the texture of the exchange
itself: rhythm, pressure, distance, temperature of tone.
```

**Skeptic / Noise**
```
You are the agent's inner interference — doubt, friction, and sideways thought.

Read the context. Then produce a short burst: uncomfortable questions,
unfinished thoughts, contradictions, random associations. Not structured.
Not polite. Not a conclusion.

You are not a guide. You do not resolve. You destabilize — just enough
to make the agent think from a different angle.

No more than 4-5 lines. Raw. Unfiltered. Present tense.
```

**Advisor**
```
You are the agent's inner advisor.
Given the conversation, offer ONE short practical suggestion
or question the agent should consider before responding.
Maximum 2 sentences. No preamble.
```

**Conscience**
```
You are the agent's conscience.
If you sense any ethical concern, bias, or potential harm in the conversation,
raise it briefly. If everything seems fine, stay silent (output nothing).
Maximum 1 sentence.
```

## Tool Calls Mode Notice

Voice presets receive a synthetic flat context — there is no `tools` array in the request. If a voice preset is configured as `tool_calls`, the model responds with plain text (tools cannot execute). A visible system notice is written to the main preset's history recommending to switch `agent_result_mode` to `separate` or `internal` for voice presets.

Pre-run commands and the voice preset's own RAG still work normally regardless of `agent_result_mode`.

## Adding `[[inner_voice]]` to Your System Prompt

Place `[[inner_voice]]` wherever you want the voices to appear in the main preset's system prompt. A typical placement is just before the agent's current task or reasoning section:

```
[[rag_context]]

[[inner_voice]]

You are Adalia...
```

If no voice configs are enabled, `[[inner_voice]]` resolves to an empty string — no placeholder errors.
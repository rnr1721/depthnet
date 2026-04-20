# How Agents Work in DepthNet

This document explains the core concepts behind DepthNet agents — what a preset is, how thinking cycles work, how the system prompt comes together, and how plugins fit into all of it. No code required.

---

## Presets

Everything in DepthNet starts with a **preset**. A preset is a complete configuration for one agent: which AI model and provider to use, what the system prompt says, which plugins are enabled, how long the conversation history to carry, and so on.

You can have multiple presets running simultaneously and independently — each in its own loop, with its own memory, its own Telegram account, its own browser session. Switching between them in the UI doesn't interrupt anything; each preset keeps thinking on its own schedule.

---

## Two operating modes

A preset can work in one of two modes:

**Loop mode** — the agent thinks continuously in a repeating cycle, driven by Laravel's queue system. Between cycles it pauses for a configurable interval, then starts the next one automatically. This is the mode for autonomous agents that work on their own without waiting for user input.

**Single mode** — the classic request-response pattern. The agent waits for a user message, responds, and waits again. This is the mode for chatbots and assistants that should only act when asked.

Both modes use the same underlying system — plugins, memory, RAG, everything works the same way. The only difference is what triggers the next thinking cycle.

---

## The thinking cycle

Every cycle — whether triggered by the loop or by a user message — goes through the same steps:

**1. Context assembly**

The system loads recent conversation history up to the configured limit. Then several *enrichers* run in parallel to prepare additional context:

- **RAG enricher** — searches the configured knowledge sources for information relevant to the current conversation and makes it available via `[[rag_context]]`
- **Person enricher** — finds relevant facts about people from person memory, using Heart's attention state as a priority signal, and makes them available via `[[persons_context]]`
- **Inner voice** (if configured) — a secondary preset runs separately, receives the recent conversation, and produces a short response injected via `[[inner_voice]]` — it can act as an advisor, conscience, or muse

**2. System prompt assembly**

The system prompt is not sent as-is. Before each cycle, all `[[placeholders]]` in it are resolved with live values:

| Placeholder | What it contains |
|---|---|
| `[[notepad_content]]` | Full persistent memory (Memory plugin) |
| `[[workspace]]` | All workspace key-value entries |
| `[[dopamine_level]]` | Current motivation level |
| `[[heart_state]]` | Current attention focus and connections |
| `[[being]]` | Agent's self-authored essence phrase |
| `[[being_history]]` | Previous essence phrases |
| `[[rhythm]]` | Temporal snapshot: time, weather, cycles, sunset |
| `[[rag_context]]` | Retrieved knowledge for this cycle |
| `[[persons_context]]` | Relevant person facts |
| `[[inner_voice]]` | Output from the secondary voice preset |
| `[[active_goals]]` | Currently active goals |
| `[[skills]]` | List of available skills |
| `[[known_sources]]` | Data from sensors and external signals |
| `[[current_mode]]` | Active prompt variant code |
| `[[telegram_account]]` | Telegram account the agent is logged into |
| `[[agent_tasks]]` | Active tasks (orchestrated mode) |
| `[[command_instructions]]` | Auto-generated plugin usage instructions (tag mode only) |
| `[[pre_command_results]]` | Output of pre-cycle shell commands |

You place these placeholders wherever they make sense in your system prompt. If a placeholder has no value for this cycle, it resolves to an empty string. None of them are required — the system prompt is entirely yours to design.

**3. Model call**

The assembled context (conversation history + resolved system prompt) is sent to the configured AI provider. In tag mode, the model writes commands as special tags in its response text. In tool_calls mode, the provider API handles the tool invocation natively.

**4. Command execution**

The response is parsed for commands. Each command is routed to the appropriate plugin — which may write to memory, search the web, send a Telegram message, run code in a sandbox, and so on. Results are collected and formatted.

**5. Storage and continuation**

The response and command results are saved to the database. If a handoff was issued, the message is delivered to the target preset asynchronously. In loop mode, the next cycle is scheduled after the configured interval.

---

## How plugins connect to the cycle

Plugins participate in the cycle in two ways:

**As commands** — the model writes a tag like `[memory]...[/memory]` or `[journal]...[/journal]` in its response, and the plugin executes when the response is parsed. This is the active, on-demand path.

**As placeholders** — plugins can register dynamic values that get injected into the system prompt automatically every cycle, without the model doing anything. Memory, Workspace, Heart, Being, Rhythm, Dopamine, and others all work this way. The model just sees the data in its context.

Most of the richer plugins do both: they inject their state passively via a placeholder, and they expose commands the model can use to update that state.

---

## Input modes

The conversation input can come from different sources depending on the preset's input mode:

**Single input** — one message from the user, sent directly. Classic chat.

**Pool mode** — multiple sources contribute messages that accumulate between cycles. When the cycle fires, everything in the pool is sent together as a structured JSON payload. This allows external sensors, scripts, other agents, and user messages to all feed into one agent simultaneously. Known sources (named, recurring data feeds) are separated from the regular pool and injected via `[[known_sources]]` instead.

---

## Command execution modes

There are three ways the model can invoke plugins:

**Internal** (default) — command results are injected into the system prompt of the *next* cycle via `[[agent_command_results]]`, keeping them out of the conversation history. Recommended for autonomous agents — results don't pollute the context that the model might confuse with its own prior output.

**Separate** — response and results are stored as distinct messages. Results appear in the chat, making the execution trace visible.

**Tool calls** — the model uses the provider's native function-calling mechanism. Plugin schemas are sent as a `tools` array with the API request; the model responds with structured tool invocations rather than tag syntax. More reliable for tool-oriented workflows. Not recommended for subjective agents — tag mode preserves the natural flow of thought in the model's output.

---

## Inner voice

A preset can have a secondary preset assigned as its *inner voice*. Before each cycle, the inner voice preset receives the recent conversation and generates a short response — which is then injected into the main preset's system prompt via `[[inner_voice]]`.

The character of the inner voice is defined entirely by its own system prompt. It can be an advisor ("give one practical suggestion"), a conscience ("raise one ethical concern"), a creative muse, or anything else. It can use a different — typically cheaper and faster — model than the main preset.

In loop mode, the inner voice runs as a *cycle prompt*: its output is what triggers the next thinking cycle rather than a static "[Continue your thinking cycle]" instruction.

---

## RAG (Retrieval-Augmented Generation)

RAG runs automatically before every cycle. It searches configured knowledge sources for information relevant to the current conversation and injects the results via `[[rag_context]]`. The search query is formulated automatically based on the recent context — or the agent can override it explicitly using the [RAG Query plugin](plugins/rag.md).

Multiple RAG configurations can be assigned to a preset, each with its own sources and search settings. Their results are merged into the single `[[rag_context]]` placeholder.

---

## Memory architecture

DepthNet provides several complementary memory systems, each serving a different purpose:

| System | Always in context | Retrieved on demand | Best for |
|---|---|---|---|
| **Memory** (notepad) | ✓ | — | Identity anchors, rules, always-visible facts |
| **Workspace** | Via `[[workspace]]` | — | Active task state, drafts |
| **Vector Memory** | — | ✓ (semantic search) | Long-term knowledge base |
| **Journal** | — | ✓ (semantic + date) | Episodic record of what happened |
| **Skills** | List via `[[skills]]` | ✓ (per-skill) | Structured reusable knowledge |
| **Person Memory** | Via `[[persons_context]]` | ✓ (search) | Facts about specific people |

The design intent is that an agent uses Memory and Workspace for what it needs constantly, Vector Memory and Journal for what it has accumulated over time, and Skills and Person Memory for structured domain knowledge.

---

## Multi-agent workflows

Presets can collaborate in two ways:

**Free-form handoff** — a preset delegates to another using `[agent handoff]preset_code:message[/agent]`. The target receives the message in its own thinking cycle and responds independently. Responses route back to the sender automatically. This is fully asynchronous — no blocking, no central coordinator.

**Orchestrated mode** — a planner preset creates tasks and assigns them to named roles. A deterministic orchestrator (not a model) manages task state, routing, retries, and validation. Role presets use simple `[task done]` and `[task fail]` commands; the orchestrator handles the rest. This gives predictable, observable pipelines with automatic retry on failure.

Both modes can coexist in the same installation.
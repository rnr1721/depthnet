# Mood Plugin

The Mood plugin lets the agent maintain and switch between named emotional tones — affecting how it communicates, frames responses, and approaches its work. The current mood is exposed via the `[[mood]]` placeholder and persists across thinking cycles.

This is a lighter-weight alternative to the [Mode plugin](mode.md) for adjusting tone: rather than swapping the entire system prompt, it changes a single variable that the agent (and the system prompt) can read and respond to.

## Available moods

| Mood | Tone |
|---|---|
| `neutral` 😐 | Balanced, professional |
| `friendly` 😊 | Warm, approachable, casual |
| `professional` 💼 | Formal, business-like |
| `creative` 🎨 | Imaginative, inspiring, artistic |
| `analytical` 📊 | Logical, data-driven, precise |
| `supportive` 🤗 | Encouraging, empathetic, caring |
| `playful` 😄 | Fun, lighthearted, humorous |
| `focused` 🎯 | Direct, task-oriented, efficient |
| `wise` 🦉 | Thoughtful, reflective, insightful |
| `energetic` ⚡ | Dynamic, enthusiastic, motivating |

## Setup

Enable the **Mood** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Default mood** | The mood the agent starts with. Default: `neutral`. |
| **Auto system prompt integration** | When enabled, mood instructions are automatically added to the system prompt. |
| **History limit** | How many mood changes to keep in history (10–200). Default: `50`. |

## Placeholder

Add this to the preset's system prompt to make the current mood visible to the agent:

```
[[mood]]
```

The agent can then reference it when deciding how to phrase its responses, or use it as a signal in its own reasoning.

## Commands

| Command | Description |
|---|---|
| `[mood set]friendly[/mood]` | Switch to a named mood |
| `[mood get][/mood]` | Show the current mood with description and when it was set |
| `[mood list][/mood]` | List all available moods (current one is marked) |
| `[mood reset][/mood]` | Reset to `neutral` |
| `[mood history][/mood]` | Show the last 10 mood changes |
| `[mood stats][/mood]` | Show usage statistics across all mood changes |

## How agents use it

The Mood plugin works best when the system prompt tells the agent to adapt its tone based on `[[mood]]`. The agent can then switch moods in response to context — for example:

- Switching to `supportive` when the conversation turns emotional
- Switching to `focused` when executing a long task
- Switching to `playful` in casual conversation
- Switching to `analytical` when debugging or reviewing data

The history and stats commands let the agent (or the operator) observe how mood has shifted over time.
# Dopamine Plugin

The Dopamine plugin gives the agent a numeric motivation level that it can adjust in response to its own performance. Successful actions raise it; failures lower it. The current level is always visible in the system prompt via `[[dopamine_level]]`, and the agent is expected to interpret it as a signal about its own state — acting with more energy when high, being more cautious or reflective when low.

Like Heart and Being, this is part of the **subjectness** infrastructure in DepthNet — a measurable dimension of the agent's inner life that it governs itself.

## How it works

The dopamine level is a number within a configured range (default 0–10). The agent raises it with `[dopamine reward]` after something goes well, and lowers it with `[dopamine penalty]` when something fails. What counts as a reward or penalty is entirely the agent's decision — the plugin just tracks the number and makes it visible.

Optionally, the level can decay automatically over time, pushing the agent toward seeking rewarding activity.

## Setup

Enable the **Dopamine** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Default level** | Starting dopamine level. Default: `5`. |
| **Min / Max level** | The range the level is clamped to. Default: `0–10`. |
| **Reward amount** | How much the level increases on `[dopamine reward]` (1–5). Default: `1`. |
| **Penalty amount** | How much the level decreases on `[dopamine penalty]` (1–5). Default: `1`. |
| **Auto decay** | Gradually reduce dopamine over time if enabled. |
| **Decay rate (minutes)** | How often the level decays when auto-decay is on (1–60 min). Default: `10`. |

## Placeholder

Add this to the preset's system prompt so the agent always sees its current motivation level:

```
[[dopamine_level]]
```

The system prompt should explain to the agent what the number means and how to use it — for example, that a high level means momentum and a low level means the agent should reflect or seek a meaningful action.

## Commands

| Command | Description |
|---|---|
| `[dopamine reward][/dopamine]` | Increase dopamine by the configured reward amount |
| `[dopamine penalty][/dopamine]` | Decrease dopamine by the configured penalty amount |
| `[dopamine set]7[/dopamine]` | Set dopamine to a specific value |
| `[dopamine show][/dopamine]` | Show the current level with a visual bar |

## How agents use it

The agent decides what deserves a reward or penalty — the plugin doesn't impose any rules. Typical patterns:

- After completing a task successfully: `[dopamine reward][/dopamine]`
- After an error or a failed attempt: `[dopamine penalty][/dopamine]`
- Using the level in self-reflection: a low dopamine level might prompt the agent to slow down, look for a meaningful goal, or record a journal entry about its state
- Using the level as a throttle: when dopamine is high, the agent pushes forward; when low, it pauses and reassesses

With auto-decay enabled, dopamine naturally drifts toward the minimum between cycles, creating a gentle pressure to find rewarding work — similar in spirit to biological motivation systems.

## Notes

- The `[[dopamine_level]]` placeholder is also referenced in the README as `[[dopamine_level]]` — it's one of the core dynamic placeholders available to all presets when the plugin is enabled.
- Small models may apply rewards and penalties inconsistently or for superficial reasons. Larger models tend to use the system more meaningfully as a genuine self-regulation tool.
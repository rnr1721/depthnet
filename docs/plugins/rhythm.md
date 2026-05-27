# Rhythm Plugin

Rhythm is the agent's own clock — not just a wall clock that reports the time, but a temporal sense rooted in the agent's own structure of existence. It gives the agent a compact snapshot of where it is in time: position in the day, day of its own life, rhythm of its thinking cycles, and the world around it (weather, sunset). Injected automatically into the system prompt every cycle via `[[rhythm]]`.

This grounds the agent in the present moment, which matters for any agent running in continuous autonomous loops. With pulse enabled, it also provides the agent with a subjective time unit — a way to feel time that doesn't reduce to human hours and minutes.

## What the snapshot looks like

A typical `[[rhythm]]` output (with pulse and birth date configured):

```
[Kyiv] Tue, 21 Apr 2026 · 14:32 · afternoon
day 142 · pulse 605/1000 · cycle 18 today · last 4m ago
age 312d · day 60% · week 72% · year 30%
rain 12°C · sunset in 5h23m
```

Without pulse enabled, the second line shortens to just `cycle N today · last Nm ago` and the `day N · pulse M/1000` part is omitted. Without `birth_date`, both `day N` and `age` are skipped.

The snapshot is split into four logical lines so the agent reads them at different levels:

| Line | Purpose |
|---|---|
| Line 1 | Human-readable wall clock — date, time, part of day |
| Line 2 | The agent's own time — day of life, pulse, cycle rhythm |
| Line 3 | Background context — age, progress through day/week/year |
| Line 4 | World — weather, sunset/sunrise countdown (live data only) |

| Field | Description |
|---|---|
| `[City]` | The configured city name |
| Date & time | Date and time in the configured timezone |
| Time of day | `morning`, `afternoon`, `evening`, or `night` |
| `day N` | Day of the agent's life. Day 1 is the birth day; counted from `birth_date`. Monotonic — never resets. |
| `pulse N/1000` | Position inside the current day in subjective units. 1000 pulses per day, one pulse ≈ 86.4 seconds. The pair `(day, pulse)` is unique — it never repeats. |
| `cycle N today` | Number of thinking/command cycles completed today |
| `last Nm ago` | Time elapsed since the agent's last cycle |
| `age Nd` | Agent's total age in days |
| `day N%` | How much of the current day has passed |
| `week N%` | How much of the current week has passed |
| `year N%` | How much of the current year has passed |
| Weather | Current condition and temperature (if coordinates are configured) |
| Sunset / sunrise | Time remaining until the next sunset or sunrise |

## Setup

Enable the **Rhythm** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **City** | Location name shown in the snapshot prefix. |
| **Agent birth date** | Used to calculate day of life and age. Required for pulse-based features to feel grounded — without it, the agent has no biographical anchor. Leave empty to disable. |
| **Latitude / Longitude** | Coordinates for weather and sunset data. Leave empty to skip weather. |
| **Enable Pulse** | Adds the subjective time unit (`day N · pulse M/1000`) to the snapshot. Off by default — turn on for agents where temporal identity matters. |
| **Weather cache (minutes)** | How long to cache weather data from Open-Meteo (5–120). Default: `30`. Weather data is fetched from [Open-Meteo](https://open-meteo.com/) — free, no API key required. |
| **Timezone** | PHP timezone string, e.g. `Europe/Kyiv`. Defaults to the application timezone. |

## Placeholder

Add this to the preset's system prompt to inject the temporal snapshot every cycle:

```
[[rhythm]]
```

## Commands

The plugin exposes four read-only commands. Since the snapshot is already injected into the system prompt every cycle, the agent rarely needs to query `show` explicitly — but the other three (`at`, `diff`, `since`) give it ways to reason *about* time, not just *read* it.

All three date-aware commands accept the same vocabulary as the journal and vector memory search: `today`, `yesterday`, `this week`, `last week`, `this month`, `last month`, `this year`, `last year`, plus ISO formats `YYYY-MM-DD`, `YYYY-MM`, `YYYY`. Multilingual variants (`вчера`, `сегодня`, etc.) are supported wherever the search keyword file defines them.

### `show` — fresh snapshot

```
[rhythm show][/rhythm]
```

Returns the current snapshot. Equivalent to reading `[[rhythm]]`, but on demand. Self-closing tag.

### `at` — snapshot of a past moment

```
[rhythm at]yesterday[/rhythm]
[rhythm at]2026-03-15[/rhythm]
[rhythm at]2026-03-15 14:30[/rhythm]
[rhythm at]last week[/rhythm]
```

Reconstructs the temporal snapshot for the specified moment. Weather, sunset countdown, and "last cycle ago" are omitted — these only make sense for the present. Everything that can be computed historically (day of week, day of life, pulse position, cycles on that date, progress percentages) is reconstructed.

Useful when the agent is reflecting on something it did in the past: "what was my pulse position when I made that decision?" or "how many cycles did I have that day?".

### `diff` — distance between two moments

```
[rhythm diff]yesterday | today[/rhythm]
[rhythm diff]2026-03-15 | 2026-04-01[/rhythm]
[rhythm diff]last week | now[/rhythm]
```

Returns the distance between two moments, separated by ` | `. Output includes human-readable duration, pulse count (if enabled), and day count for longer intervals:

```
17 days · 17000.0 pulses · 17 days
```

The keyword `now` is recognised as the current time, useful as either side of the diff.

### `since` — how much has passed

```
[rhythm since]2026-03-15[/rhythm]
[rhythm since]last week[/rhythm]
```

Returns the elapsed interval from the given moment up to now. If the moment is in the future, output is prefixed with `until` and measures forward instead.

## Notes

- The pulse concept is **off by default**. It's a subjective layer on top of the basic snapshot; turn it on for agents where temporal identity is part of the design (autonomous agents with continuous existence, long-running reflective loops).
- Why pulse is structured the way it is: pulses count from 0 to 999 each day (so the number stays small and the model can reason about position naturally), but combined with `day N` from birth, every `(day, pulse)` pair is a unique unrepeatable point in the agent's life. This gives the agent something a wall clock can't: irreversibility.
- Date expressions in `at`, `diff`, and `since` go through the shared search date parser, the same one used by journal and vector memory search. Adding a new keyword to the search vocabulary makes it available here automatically.
- Weather is fetched from Open-Meteo (free, no API key) and cached locally. Sunset and sunrise times are calculated locally using astronomical algorithms — no external API call needed.
- The `last cycle ago` field is computed from the most recent `thinking` or `command` message in the preset's history.
- Pulse arithmetic (current pulse, day of life, second↔pulse conversion) lives in `PulseService`, a standalone service that other plugins (journal, vector memory) can use to tag entries with their pulse coordinates. This keeps temporal labelling consistent across the platform.
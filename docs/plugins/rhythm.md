# Rhythm Plugin

Rhythm gives the agent a compact, real-time snapshot of time and place — injected automatically into the system prompt every cycle. It tells the agent not just *what time it is*, but *where it is in the day, week, and year*, how long it has been since its last cycle, and what the weather is like outside.

This grounds the agent in the present moment, which is particularly useful for agents running in continuous autonomous loops where maintaining a sense of temporal context matters.

## What the snapshot looks like

A typical `[[rhythm]]` output:

```
[Kyiv] Tue, 21 Apr 2026 · 14:32 · afternoon · day 60% · week 72% · year 30% · my age 312d · pause 4m · today 18 cycles · rain 12°C · sunset in 5h23m
```

All fields are compacted into a single line to keep prompt overhead minimal.

| Field | Description |
|---|---|
| `[City]` | The configured city name |
| Date & time | Current date and time in the configured timezone |
| Time of day | `morning`, `afternoon`, `evening`, or `night` |
| `day N%` | How much of the current day has passed |
| `week N%` | How much of the current week has passed |
| `year N%` | How much of the current year has passed |
| `my age Nd` | Agent's age in days, counted from the configured birth date |
| `pause` | Time elapsed since the agent's last thinking cycle |
| `today N cycles` | Number of thinking cycles completed today |
| Weather | Current condition and temperature (if coordinates are configured) |
| Sunset / sunrise | Time remaining until the next sunset or sunrise |

## Setup

Enable the **Rhythm** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **City** | Location name shown in the snapshot prefix. |
| **Agent birth date** | Used to calculate the agent's age in days. Leave empty to omit. |
| **Latitude / Longitude** | Coordinates for weather and sunset data. Leave empty to skip weather. |
| **Weather cache (minutes)** | How long to cache weather data from Open-Meteo (5–120). Default: `30`. Weather data is fetched from [Open-Meteo](https://open-meteo.com/) — free, no API key required. |
| **Timezone** | PHP timezone string, e.g. `Europe/Kyiv`. Defaults to the application timezone. |

## Placeholder

Add this to the preset's system prompt to inject the temporal snapshot every cycle:

```
[[rhythm]]
```

## Command

The agent can also request a fresh snapshot on demand:

```
[rhythm show][/rhythm]
```

## Notes

- Weather is fetched from Open-Meteo, which is free and requires no API key or account.
- The `pause` field shows how long ago the last thinking or command message was recorded — useful for agents that run on long intervals and need to be aware of how much time has passed.
- Sunset and sunrise times are calculated locally using astronomical algorithms — no external API call needed for that.
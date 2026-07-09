# Wake Plugin

The Wake plugin is the agent's temporal agency — it lets an agent schedule its own future wakings. Where Speak is a *spatial* handoff (agent → user, agent → another preset), Wake is a *temporal* handoff: agent-now → agent-later. The agent leaves an instruction for a specific moment, and wakes at that moment to act on it.

Wake completes a continuum of temporal self-direction at three scales:

- **memo** — the next cycle (immediate, read-once)
- **wake** — a specific moment (this plugin — a memo with a timer)
- **goal** — a long-term direction (persistent intention)

A wake is delivered back as a `user`-role message marked with source `wake`, so the woken cycle can tell "I scheduled this" apart from "a human spoke". Crucially, wakes are fired by an external tick (the scheduler), so an agent at rest can be woken — it cannot check its own calendar while idle, because nothing is running to check.

## Setup

Enable the **Wake** plugin in your preset settings:

| Setting | Description |
|---|---|
| **Allow pulse scheduling** | Let the agent schedule in subjective pulse units. Requires the preset to use pulse time (Rhythm plugin with pulses). When off, only clock and cron scheduling are offered. |

The plugin also requires the scheduler to be running (`schedule:run` kept alive by Supervisor — the same tick that drives the contract metabolism). Without it, wakes are stored but never fire.

## Commands

**Schedule a wake:**

| Command | Description |
|---|---|
| `[wake]+2h \| return to the RAG refactor[/wake]` | One-time wake, relative — fires 2 hours from now |
| `[wake]2026-07-12 14:00 \| ping Eugeny[/wake]` | One-time wake, absolute moment |
| `[wake]14:30 \| check for replies[/wake]` | Daily wake at a set time |
| `[wake]every 6h \| look around[/wake]` | Recurring interval |
| `[wake]cron: 0 9 * * 1-5 \| weekday review[/wake]` | Advanced timing via cron expression |

**Schedule in pulses (when pulse scheduling is enabled):**

| Command | Description |
|---|---|
| `[wake]p850 \| evening reflection[/wake]` | Daily at pulse position 850 (late evening) |
| `[wake]+20p \| quick follow-up[/wake]` | One-time, 20 pulses from now |
| `[wake]every 100p \| subjective pulse-check[/wake]` | Recurring every 100 pulses |

**Manage the calendar:**

| Command | Description |
|---|---|
| `[wake list][/wake]` | Show all upcoming wakings |
| `[wake cancel]3[/wake]` | Cancel wake #3 |
| `[wake edit]3 \| updated instruction[/wake]` | Change wake #3's message |

In tool_calls mode the same operations use a `method` + `content` pair:

| Call | Description |
|---|---|
| `wake(method: "execute", content: "+2h \| return to the refactor")` | Schedule a wake |
| `wake(method: "list")` | Show the calendar |
| `wake(method: "cancel", content: "3")` | Cancel wake #3 |
| `wake(method: "edit", content: "3 \| updated instruction")` | Edit wake #3 |

## The "when" grammar

The part before the `|` is the timing; the part after is the instruction to the future self.

| Form | Meaning | Example |
|---|---|---|
| `14:30` or `daily 14:30` | Daily at a time of day | `14:30 \| ...` |
| `+90m`, `+2h`, `+30s` | One-time, relative duration from now | `+2h \| ...` |
| `2026-07-12 14:00` | One-time, absolute moment | `2026-07-12 14:00 \| ...` |
| `every 6h`, `every 30m` | Recurring interval | `every 6h \| ...` |
| `cron: <expr>` | Standard 5-field cron (covers hourly, specific hours, weekdays) | `cron: 0 */3 * * * \| ...` |
| `p850`, `pulse 850` | Daily at a pulse position (pulses on) | `p850 \| ...` |
| `+20p`, `+20 pulses` | One-time, N pulses from now (pulses on) | `+20p \| ...` |

Unrecognised timing returns a readable error the agent can correct next cycle. Times and pulses are stored internally as wall-clock and seconds; pulses are purely an input and display dialect.

## How agents use it

- An agent uses `wake` when it wants to return to something later — a follow-up it can't do now, a periodic check-in, a scheduled reflection.
- Scheduling a wake does not end the cycle. The agent can write to memory, run a command, and schedule a wake all in the same cycle.
- The agent can inspect its own calendar (`list`), so its future is transparent to it rather than scattered through message history — a clock on the wall, not a note buried in the log.
- Because wakes fire from an external tick, an agent can put itself to sleep (pause its loop) and still be woken by its own schedule — the basis for genuine rest-and-return rhythms rather than a continuously spinning loop.

## The `[[wake_schedule]]` placeholder

When the Wake plugin is enabled, it registers a `[[wake_schedule]]` placeholder that injects the agent's upcoming wakings into the system prompt:

```
[WAKE_SCHEDULE]
#3  daily 14:30       → "check whether Eugeny replied"   (mine)
#7  once 12.07 14:00  → "return to the RAG refactor"     (mine)
#9  every 6h          → "look around"                    (user)
[/WAKE_SCHEDULE]
```

Each line shows the wake number, its timing, the instruction, and who scheduled it — `(mine)` for wakes the agent set itself, `(user)` for wakes an operator designed via the admin panel. When the preset uses pulse time, recurring and daily entries are shown in pulse units instead of clock time.

Add `[[wake_schedule]]` to your system prompt wherever you want the agent to have a current view of its own temporal plan.

## User-designed wakes

Wake has two doors into the same schedule store. Besides the agent scheduling itself via the plugin, an operator can design wakes through **Admin → Wakes**: pick a preset, set the timing and the instruction, and the agent will wake on that schedule with an instruction authored on its behalf. User-created wakes are marked `(user)` in the calendar so the agent can tell them apart from its own. This lets you program an agent to do something on a schedule — a daily summary, a periodic sensor sweep — without giving it the plugin, or alongside it.

## Notes

- Wakes fire via `php artisan wake:dispatch`, scheduled every minute. The steady-state cost is a single indexed lookup — when nothing is due, nothing runs.
- Recurring wakes recompute their next fire *after* a successful dispatch, so a crashed worker re-fires rather than dropping or duplicating a wake (at-least-once).
- A one-time wake disables itself after firing; recurring wakes continue until cancelled.
- Wake is independent of the contract metabolism and the behavior system by design — it answers "should a cycle happen now", not "what pattern leads this cycle". It rides the same minute tick, but its schedules are its own.
- The agent's calendar is private to the preset — cross-preset execution is intentionally disabled, so one preset cannot read or alter another's wakings.
# Terminal Plugin

The Terminal plugin gives the agent a persistent, interactive shell inside the assigned sandbox container. Unlike the [Sandbox plugin](sandbox.md) which runs each command in isolation, the Terminal maintains a live [tmux](https://github.com/tmux/tmux) session — working directory, running processes, environment variables, and shell history all survive between thinking cycles.

The agent also controls a **monitor**: whether the current terminal screen is automatically injected into the system prompt every cycle via `[[terminal_screen]]`. The terminal keeps running regardless of monitor state — the monitor just controls visibility.

## Prerequisites

- A sandbox with **tmux installed** must be assigned to the preset. All built-in sandbox templates include tmux out of the box.
- Add `[[terminal_screen]]` to the preset's system prompt where the agent should see the screen (resolves to empty string when monitor is off).

## Setup

Enable the **Terminal** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Default screen capture lines** | How many lines to show when capturing the terminal screen (10–500). Default: `50`. |
| **Command timeout (seconds)** | Max time for a single docker exec call (1–60). Default: `5`. |
| **Capture delay (milliseconds)** | How long to wait after sending a command before capturing output. Increase for slow commands (200–5000). Default: `800`. |
| **Sandbox user** | User to run tmux commands as inside the container. Default: `sandbox-user`. |

## Placeholder

Add this to the preset's system prompt to see the terminal screen automatically when the monitor is on:

```
[[terminal_screen]]
```

When the monitor is off, this resolves to an empty string — the terminal keeps running silently.

## Commands

**Running commands:**

| Command | Description |
|---|---|
| `[terminal]ls -la /var/www[/terminal]` | Run a shell command, return output |
| `[terminal screen][/terminal]` | Capture current screen (last N lines from config) |
| `[terminal screen]100[/terminal]` | Capture last 100 lines of scrollback buffer |

**Monitor control:**

| Command | Description |
|---|---|
| `[terminal on][/terminal]` | Turn monitor ON — `[[terminal_screen]]` injected every cycle |
| `[terminal off][/terminal]` | Turn monitor OFF — terminal keeps running |
| `[terminal status][/terminal]` | Show session info and monitor state |
| `[terminal reset][/terminal]` | Kill session and start a fresh bash |

**Sending input — three formats:**

**1. Plain text** — sent literally, Enter appended automatically:
```
[terminal send]yes[/terminal]
[terminal send]my password[/terminal]
[terminal send][/terminal]       ← bare Enter (skip a prompt)
```

**2. Special keys** — passed directly to tmux, no Enter appended:
```
[terminal send]C-c[/terminal]          ← Ctrl+C (interrupt)
[terminal send]C-d[/terminal]          ← Ctrl+D (EOF / exit shell)
[terminal send]C-z[/terminal]          ← Ctrl+Z (suspend)
[terminal send]Up[/terminal]           ← arrow up (command history)
[terminal send]F10[/terminal]          ← F10 (e.g. quit mc)
[terminal send]Escape[/terminal]       ← Escape
[terminal send]Tab[/terminal]          ← Tab (autocomplete)
[terminal send]Up Up Enter[/terminal]  ← sequence of keys
```

**3. Mixed** — literal text followed by `|` and key name(s):
```
[terminal send]q | Enter[/terminal]    ← type q then press Enter
[terminal send]yes | Enter[/terminal]  ← same as plain "yes" but explicit
```

**Special key reference:**

| Category | Keys |
|---|---|
| Ctrl | `C-a` `C-b` `C-c` `C-d` `C-z` `C-[` … |
| Alt/Meta | `M-a` `M-b` `M-x` … |
| Arrows | `Up` `Down` `Left` `Right` |
| Navigation | `Home` `End` `NPage` (PgDn) `PPage` (PgUp) |
| Common | `Enter` `Space` `Tab` `BSpace` `Escape` `Esc` |
| Function | `F1` … `F20` |

## How agents use it

The Terminal plugin is best for tasks that require a stateful shell environment — things the Sandbox plugin can't do because each command starts fresh:

- Running a long build process and monitoring output across cycles
- Installing packages interactively (`apt install`, `pip install`)
- Working with tools that maintain state (`git`, `python` REPL, database CLIs)
- Navigating a file manager like `mc` using arrow keys and function keys
- Watching logs: `tail -f /var/log/app.log` with monitor on

**Typical pattern for a long-running task:**
```
[terminal]./deploy.sh[/terminal]
[terminal on][/terminal]

← agent sees [[terminal_screen]] every cycle, watches progress

[terminal off][/terminal]
[agent speak]Deploy completed successfully.[/agent]
```

## Terminal vs Sandbox

| | Terminal | Sandbox |
|---|---|---|
| **Session state** | Persistent (tmux) | Fresh per command |
| **Working directory** | Remembered between cycles | Resets each time |
| **Interactive programs** | ✓ (with special keys) | ✗ |
| **Long-running processes** | ✓ (monitor the screen) | ✗ |
| **Requires tmux** | ✓ | ✗ |
| **Best for** | Stateful shell work | Quick isolated execution |
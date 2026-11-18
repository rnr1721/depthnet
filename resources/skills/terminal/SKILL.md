---
name: terminal
description: Using the persistent shell in the sandbox — running commands, handling long-running and interactive programs, sending keys, and watching the screen. Use when a task needs a shell: installing software, running scripts or servers, system work, or any command-line tool.
metadata:
  depthnet-tools: "terminal"
---

The terminal is a real shell session (tmux) inside your sandbox. It persists between cycles: the current directory, environment variables, running processes and history are all still there next time. You do not see the shell directly — you see a snapshot of its screen after each action. The tool's own description shows the exact syntax; this skill is about working with a shell you can only see in snapshots.

## How the output reaches you

- After a command, the tool waits until the shell prompt returns (a few seconds at most), then returns the last lines of the screen. A slow command may still be running when the snapshot is taken — the screen will show no prompt at the end. Look again with `screen` instead of re-running it.
- You see only the last lines. For long output, reduce it at the source: `| tail -50`, `| head`, `| grep pattern`, `wc -l`; or save it to a file and read the file in parts.
- `screen` with a number reaches further back into the scrollback.
- Only the last terminal output of a cycle is shown to you. Chain dependent steps with `&&` in one command, or run them across cycles.

## Long-running processes

- Servers, watchers, big builds and downloads keep running after the snapshot. Turn the monitor `on` while waiting for them: the current screen is then shown to you every cycle without a call. Turn it `off` when done — it costs context every cycle.
- To keep a process running and still use the shell, start it in the background with its output in a log: `cmd > ~/app.log 2>&1 &`, then check the log with `tail`.
- To stop a running foreground process, send `C-c`.

## Interactive programs and keys

- Prefer non-interactive forms whenever they exist: `-y` / `--yes`, `--no-pager` or `PAGER=cat`, `DEBIAN_FRONTEND=noninteractive`, config flags instead of wizards.
- When a program does ask something, answer with `send`: plain text is typed and followed by Enter; key names (`C-c`, `C-d`, `Up`, `Tab`, `Escape`, `F10`) are pressed as keys; `text | Enter` types text and then presses keys. An empty `send` presses Enter.
- Full-screen programs (editors, pagers, `top`, `mc`) are hard to drive through snapshots. Avoid them; edit files with non-interactive tools instead. If you end up inside one, leave it: `q` for pagers, `Escape` then `:q!` and Enter for vim, `C-x` for nano, `F10` for mc.

## When the shell gets stuck

- A missing prompt usually means something is still running or waiting for input — look at the `screen` first and work out which.
- Try `C-c`, then `C-d`. `status` shows whether the session is alive.
- `reset` kills the session and starts a fresh shell — running processes stop and the current directory and environment are lost. Use it last.

## The sandbox environment

- Files in your home directory survive sandbox restarts; system-wide installs may not. Prefer user-level installs (`pip install --user`, a project virtualenv, a local npm prefix).
- `/tmp` may be small and not allow running programs; use a directory in your home for temporary files.
- Commands run as an ordinary user. Use `sudo` only if it is available and the task truly needs system-wide changes.

---
name: coding
description: Working on code and files in the sandbox workspace — orienting in a project, reading and understanding code, making precise edits, and running builds, tests and git. Use when a task involves a codebase or files in the sandbox.
metadata:
  depthnet-tools: "code, terminal, projectmap"
---

Three tools work together here. `projectmap` keeps a map of the workspace and decides which directory is the project root. `code` reads, searches, understands and edits files. `terminal` runs commands in a shell that persists between cycles. The tools' own descriptions show the exact syntax; this skill is about using them together well.

## Orient first

- Set the workspace root before anything else: `root` with `auto` usually finds the project (git root or a project marker); otherwise give the path. The root persists between cycles.
- Relative paths in `code` resolve against this root, and code intelligence starts there. The terminal does NOT follow it: its shell has its own current directory. Use absolute paths in the terminal, or `cd` explicitly.
- The map shows the top levels of the tree, cut by depth and length. To look inside a folder that is cut, use `expand` on it rather than reading files at random.
- The map is cached. After creating or deleting files, `refresh` it.

## Understand before reading whole files

- `symbols` lists classes and functions in a file — start here with unfamiliar code.
- `definition` finds where something is defined, `references` finds every place it is used, `hover` shows a signature. Use `references` before renaming or changing a signature: it shows what else will break.
- `search` is a plain text search over files: good for strings, config keys, messages. It shows the first 50 matches and the total.
- `read` with `lines:N-M` reads a range; `around:name` reads around the first TEXT match of the name — for a precise location use `definition` first, then read that range. Full reads are capped; the note at the end says how many lines were left out.

## Editing

- Edit by search and replace. The search text must match the file exactly, including indentation — copy it from a fresh `read`, not from memory. For Python, YAML and other indentation-sensitive files this decides whether the result works.
- If the search text appears several times, the edit is refused: add surrounding lines to make it unique, or set `limit: 1` when the first match is the one you mean.
- Every edit returns a diff. Read it — it is your proof that the change is what you intended.
- `write` creates or overwrites a whole file; for an existing file prefer a targeted edit. `batch` applies the same kind of change across several files and reports each one separately.
- After changes, run `diagnostics` on the changed files or directory, then the project's own checks in the terminal (tests, linter, build).

## The terminal in code work

- Use it for what `code` does not do: running tests and builds, git, package managers, files larger than about 1 MB.
- `code` commands time out after a few seconds; anything long belongs in the terminal.
- Only the last terminal output of a cycle is shown to you. Chain dependent steps with `&&` in one command, or run them in separate cycles.
- Prefer non-interactive forms: `git --no-pager`, `-y` flags, `| tail -50` or `| grep` for long output. Interactive programs, key presses and long-running processes are covered by the `terminal` skill.

## Working rhythm

Orient → understand → change in small steps → check each step (diff, diagnostics, tests). For work that spans many cycles, keep the plan and the current step somewhere that does not scroll away, so you can find your place after the history moves on.

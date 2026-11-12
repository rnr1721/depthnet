# Skill Plugin

The Skill plugin gives the agent a persistent, structured knowledge base. Knowledge is organised into named **skills**, each containing any number of **items** — individual pieces of information. Items are indexed semantically, so the agent can search across all skills by meaning rather than exact keywords.

Think of it as the agent's personal wiki: stable, reusable knowledge that it builds up over time and can retrieve when relevant.

On top of the knowledge base, the plugin provides **lazy tool loading**: a skill can carry a set of tools that stay hidden from the agent until the skill is loaded — keeping the agent's tool list lean until the relevant capability is actually needed.

It also speaks the open **standard skill format** ([agentskills.io](https://agentskills.io) `SKILL.md` folders, as published by Anthropic and others): drop a skill folder into the shared library, connect it to a preset, and the agent can load it, read its reference files and run its scripts in the sandbox — see [Standard skills](#standard-skills-agentskillsio).

## How it differs from other memory types

| | Skill | Vector Memory | Journal | Workspace |
|---|---|---|---|---|
| **Best for** | Stable, reusable knowledge | Facts and insights | What happened | Active task state |
| **Structure** | Named skills with numbered items | Flat entries | Chronological entries | Key-value pairs |
| **Search** | TF-IDF semantic search | TF-IDF or embedding | TF-IDF | By key |
| **Persists** | ✓ | ✓ | ✓ | ✓ |

## Lazy tool loading

Each skill can have **tools** attached to it — the names of plugins that become available only while the skill is loaded. This lets you group related capabilities behind a skill and keep them out of the agent's tool list until needed.

For example, a "Code" skill might carry `terminal`, `code`, and `projectmap`. While the skill is not loaded, those three tools are hidden from the agent's tool schema; the agent only sees them (and can call them) after it loads the skill.

**Key principle — hiding is not blocking.** A hidden tool is only removed from what the agent is *shown*; it is never blocked from *executing*. This keeps the agent's attention focused without ever trapping it away from a capability it needs.

How it behaves:

- **Attaching tools activates the mechanism.** A preset with no skill-tools behaves exactly as before — nothing is hidden. As soon as any skill declares tools, those tools are gated behind loading.
- **Loading is explicit.** The agent brings a skill into context with a `load` command (see below). It is never loaded implicitly.
- **Tools ride along with knowledge.** Loading a skill injects its full content into the agent's context *and* reveals its tools. A skill with no tools is perfectly valid — loading it simply brings its knowledge into context.
- **Shared tools.** The same tool can be attached to several skills; it becomes visible as soon as *any* owning skill is loaded.
- **Auto-unload (hysteresis).** A loaded skill whose tools go unused for several cycles is automatically unloaded, keeping the tool list lean. Skills with no tools are never auto-unloaded — the agent unloads those itself when done; the inventory and compaction recaps show it which ones sit unused, and a cap on loaded skills is the backstop (see [Keeping the desk tidy](#keeping-the-desk-tidy)).
- **Unknown tool names are ignored.** If a skill lists a tool that no longer exists (renamed or removed plugin), it is silently skipped — nothing breaks. This also means you can list a tool name before its plugin exists (e.g. a future custom tool).

## Setup

Enable the **Skill** plugin in your preset settings. Available options:

| Setting | Description |
|---|---|
| **Navigation console only** | When on, the agent can only load/unload/list skills — it cannot create or edit their content. Use this on presets where the Knowledge plugin manages skill content, so the agent has a single, unambiguous way to write knowledge. Off by default. |
| **Skill language** | Optionally force a language for all skill entries. The agent will receive an instruction to write in that language. |
| **Search results limit** | Maximum number of items returned per semantic search (1–20). Default: `5`. |

Attaching **tools** to a skill is done in the Skills manager (Admin → Skills): open or create a skill and enter a comma-separated list of plugin names, e.g. `terminal, code, projectmap`. Recognised names are highlighted; an unrecognised name is kept but marked inactive.

The Skills manager also shows each skill's kind — 📄 standard skill from the library (⚡ when it ships scripts), 💭 experience skill — and has a **Connect from library** button for standard skills.

## Placeholder

Add this to the preset's system prompt to give the agent a permanent inventory of its skills:

```
[[skills]]
```

This injects a compact list of all skills, each showing its **load status** (loaded / not loaded) and, for skills with tools, which tools they carry. Skills that are not loaded include a hint on how to load them. This is the bridge that makes lazy loading work: the agent always knows which capabilities exist — even while their tools are hidden — and how to bring them in.

Each line also carries facts the agent needs to manage its own desk: the skill's approximate size in tokens, how many cycles a loaded skill has gone unused, and — for standard skills — what files it ships:

```
#1 pdf — Use this skill whenever … (8 items, ~1.9k tokens) — ● loaded, unused 3 cycles; ships scripts/ (8), forms.md, reference.md, LICENSE.txt → `skill read pdf`
#3 Code — … (3 items, ~450 tokens) — not loaded; tools (hidden): terminal, code → `skill load 3` to use
```

Hints in the inventory and in command results are written as `skill <method> <argument>`, which reads the same in tag mode and in `tool_calls` mode.

## Commands

Skills and items are addressed by number. The agent assigns numbers automatically when creating them. Loading commands also accept a skill's **title** (case-insensitive) instead of its number.

**Loading (navigation console — always available):**

| Command | Description |
|---|---|
| `[skill list][/skill]` | List all skills, showing which are loaded |
| `[skill load]1[/skill]` | Load skill #1 into context (reveals its tools, if any) |
| `[skill load]Code[/skill]` | Load a skill by title instead of number |
| `[skill unload]1[/skill]` | Unload skill #1 (re-hides its tools) |
| `[skill read]pdf[/skill]` | List the files a standard skill ships (by name or number) |
| `[skill read]pdf forms.md[/skill]` | Read one of them (paths relative to the skill folder) |
| `[skill read]pdf forms.md 200-400[/skill]` | Read a line range of a long file |

When the preset has exactly one skill, `load` with no argument loads it; likewise `unload` with no argument unloads the only loaded skill.

**Managing skills (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill]PostgreSQL[/skill]` | Create an empty skill named "PostgreSQL" |
| `[skill]PostgreSQL \| Use EXPLAIN ANALYZE to inspect query plans[/skill]` | Create a skill and add the first item in one step |
| `[skill delete]1[/skill]` | Delete entire skill #1 |
| `[skill show]1[/skill]` | Show skill #1 with all its items |

**Managing items (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill add]1 \| item content[/skill]` | Add an item to skill #1 |
| `[skill update]1.2 \| new content[/skill]` | Update item 2 of skill 1 |
| `[skill delete]1.2[/skill]` | Delete item 2 of skill 1 |

**Searching (hidden in navigation-console mode):**

| Command | Description |
|---|---|
| `[skill search]how to speed up slow queries[/skill]` | Find semantically relevant items across all skills |

> In `tool_calls` mode the same operations are exposed as a single `skill` tool with a `method` (`list`, `load`, `unload`, `read`, and — outside console mode — `execute`, `add`, `update`, `delete`, `show`, `search`) and a `content` argument. The tag examples above map directly onto that.

## How agents use it

Skills are best suited for knowledge the agent wants to *reuse* — not just recall once. Typical patterns:

- An agent working with a specific technology builds a "PostgreSQL" skill and adds tips as it discovers them
- A personal assistant agent maintains a "User preferences" skill and updates it when it learns something new
- A research agent accumulates findings into topic-based skills and searches them before tackling a related problem

With lazy tool loading, skills also become a way to organise **capabilities**, not just knowledge:

- A "Code" skill carries `terminal`, `code`, and `projectmap`; the agent loads it only when it actually needs to work with code, and its tool list stays lean the rest of the time
- A "Web" skill carries `browser`; loaded only when the agent needs to browse

The key difference from vector memory is structure: skills group related items together under a meaningful name, making the knowledge base easier to navigate and maintain over time — and, with tools attached, a way to bring whole sets of capabilities in and out of focus on demand.

## Standard skills (agentskills.io)

A standard skill is a folder with a `SKILL.md` (YAML frontmatter `name` + `description`, then Markdown instructions) and, optionally, `scripts/`, `references/`, `assets/` and other files. DepthNet keeps such folders in one shared **library** and lets any preset connect them.

### Library and connecting

- Put the folder at `shared/skills/<name>/` (inside the app container: `/shared/skills/<name>/`). The folder name must equal the skill's `name`: lowercase letters, digits and single hyphens, up to 64 characters.
- In **Admin → Skills → Connect from library**, every folder is listed with its parse status: errors (missing description, broken frontmatter) block connecting, warnings (name ≠ folder, unknown fields, over-long description) are shown but don't.
- **Connecting** imports `SKILL.md` into the preset as a normal skill: title = the skill name, description = its description, items = the body cut by sections (text before the first section heading is item 1, then one item per top-level section, nested headings stay inside; headings inside code blocks are ignored; very long sections are split at paragraph boundaries).
- The library folder is never modified by DepthNet. When it changes, the skill shows as *changed in library*; **Refresh** re-imports it. The skill keeps its number, its tools, its experience flag and its loaded state — only the content is re-cut.
- **Detaching** (the delete button on a standard skill) removes it from the preset only; the folder stays and can be connected again.

A connected standard skill is **read-only**: title, description and items mirror `SKILL.md`, and both the admin UI and the agent's CRUD commands refuse to change them. Tools and the experience flag stay editable. The frontmatter extras (license, compatibility, `allowed-tools`) are shown in the skill card; `allowed-tools` is kept verbatim and is **not** mapped onto DepthNet tools — it is a permission list for other hosts, not a visibility list.

### Files and the sandbox

`SKILL.md` usually says things like "read FORMS.md" or "run `python scripts/fill.py`". Those paths are relative to the skill folder, so:

- `skill read <skill>` lists the folder, `skill read <skill> <path> [from-to]` reads a text file from it — works without a sandbox. Paths that leave the folder, symlinks and binary files are refused; a path that differs only in letter case (`FORMS.md` → `forms.md`) is found.
- When the agent **loads** the skill or **reads** its files, the whole folder is delivered into the preset's sandbox at `~/.skills/<name>/` (re-delivered automatically when the library copy changes, the sandbox changes, or the folder was deleted). Treat it as read-only: it is replaced on update.
- Above the body of a loaded standard skill the agent gets a header: the folder path ("cd there before running its scripts"), the sandbox environment (OS, Python, the right install command — `pip3 install --user`, a root-owned venv via `sudo`, or "no pip"; Node; sudo; `/tmp` is noexec so `TMPDIR=~/.tmp`), and a tool translation — the skill's "bash"/shell commands mean the `terminal` tool, file reading/editing means the `code` tool with absolute paths. If no sandbox is assigned, the header says the scripts can't run here and points to `skill read` instead.
- Dependencies are never installed automatically: a script that fails on a missing package is the agent's cue to install it with the command from the header. Packages installed with `--user` live in the sandbox home and survive a sandbox reset.

Scripts are run through the sandbox, never through the host `shell` plugin.

## Experience and classic skills

Every skill has an **experience** flag (💭 in the Skills manager):

- **Experience** — knowledge the agent gathered itself. Skills the agent creates (with `skill execute …` or Knowledge `remember` with nature *skill*) are experience by default. Experience skills reach the agent through **RAG** when they are relevant, without being loaded.
- **Classic** — instructions you hand the agent: skills created in the admin UI and standard skills from the library. They come in only when the agent deliberately loads them.

You can switch any skill either way in the Skills manager, with two exceptions enforced everywhere: a skill with tools can't be experience (tools are revealed by loading, not by RAG), and a standard skill with scripts can't be experience (RAG would surface its text without its files).

A loaded skill is never repeated through RAG — see [RAG](../memory/RAG.md#sources).

## Keeping the desk tidy

Tool-bearing skills unload themselves after a few idle cycles; toolless skills (most standard skills) don't, so a long session can pile them up. Three things help the agent keep its desk clean — none of them takes the decision away from it:

- **Usage facts in the inventory.** A loaded skill shows `used last cycle` / `unused N cycles`. Using a skill means loading it, using a tool it carries, reading its files, or a command that touches its sandbox folder (`.skills/<name>`).
- **A line in the compaction recap.** When the window is folded, the recap lists what is still loaded, with sizes and idle time, and suggests unloading what the next work won't need. See [compaction](../memory/compression.md#loaded-skills-in-the-recap).
- **A cap.** At most 5 skills are loaded at once. Loading a sixth unloads the least recently used one, and the `load` result names it in advance.

## Relationship with the Knowledge plugin

The Knowledge plugin can also write and read skill *content* (as one of its unified memory types). It does **not** load or unload skills — that is always done through this plugin's navigation console. The two complement each other: Knowledge is the content channel, the Skill console is the load channel. When Knowledge is enabled, turn on **Navigation console only** here so the agent has a single, unambiguous way to write skill content (Knowledge) and a single way to load it (the console).
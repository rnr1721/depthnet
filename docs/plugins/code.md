# Code Plugin

The Code plugin gives the agent structured access to its sandbox filesystem: navigate directory trees, read files with precise line control, search by text, and apply targeted edits without rewriting entire files.

Intentionally separate from the Document Manager — Documents handles uploaded knowledge files and semantic search, Code handles the sandbox workspace as a living project directory.

Requires a sandbox assigned to the preset.

> **Note:** This plugin gives the agent direct write access to files. Enable it only for presets where you trust the agent to modify the sandbox filesystem.

## Prerequisites

- A sandbox must be assigned to the preset and be in `running` state.
- All paths are relative to sandbox-user home (`~/`).

## Setup

Enable the **Code** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Max lines to read** | Maximum lines returned by the `read` command (50–2000). Default: `500`. Prevents huge outputs from filling context. |
| **Max tree depth** | Maximum directory depth for the `tree` command (1–10). Default: `4`. |
| **Around context lines** | Lines of context above/below a symbol for `read ... \| around:` (5–100). Default: `20`. |
| **Use unified edit command** | When enabled, exposes a single `edit` method that auto-detects format (replace or patch). When disabled, exposes separate `replace` and `patch` methods. Default: `true`. |

## Commands

### Navigation

| Command | Description |
|---|---|
| `[code tree][/code]` | Show directory tree from current directory |
| `[code tree]app/Services[/code]` | Tree of a specific path |
| `[code info]app/Services/UserService.php[/code]` | File/directory metadata (size, type, line count) |

### Reading

| Command | Description |
|---|---|
| `[code read]path/to/file.php[/code]` | Read full file (capped at max lines) |
| `[code read]path/to/file.php \| lines:1-50[/code]` | Read specific line range |
| `[code read]path/to/file.php \| around:functionName[/code]` | Read lines around a symbol |

When a file is truncated, the agent receives a note: `[Truncated: showing N of M lines. Use lines:N-M to read more.]`

### Search

| Command | Description |
|---|---|
| `[code search]calculatePrice[/code]` | Search text recursively in workspace |
| `[code search]calculatePrice \| path:app/Services[/code]` | Search within a specific directory |

Returns up to 50 matches with file paths and line numbers, plus a total match count.

### Editing

**With `unified_edit` enabled (default):**

| Command | Description |
|---|---|
| `[code edit]...[/code]` | Edit a file — auto-detects format (replace or patch) |

The plugin detects which format you used:
- If the content starts with `--- a/...` and contains `@@` markers → treated as unified diff patch
- If the content contains `path:`, `search:`, `replace:` keys → treated as key-value replace

**Replace format:**
```
[code edit]
path: app/Services/UserService.php
search: return $total;
replace: return round($total, 2);
[/code]
```

Add `limit: 1` to replace only the first occurrence when the search string appears multiple times:
```
[code edit]
path: app/Services/UserService.php
search: $result = null;
replace: $result = [];
limit: 1
[/code]
```

If the search string appears more than once and `limit` is not set, the plugin returns a warning with the match count rather than replacing all occurrences silently.

**Unified diff format:**
```
[code edit]
--- a/app/Services/UserService.php
+++ b/app/Services/UserService.php
@@ -10,7 +10,7 @@
-    return $total;
+    return round($total, 2);
[/code]
```

The patch runs a dry-run first. If it would fail (e.g. context lines don't match), the agent gets the failure output before any changes are made.

After every successful edit, the agent receives a unified diff showing exactly what changed.

**With `unified_edit` disabled:**

`replace` and `patch` are available as separate methods with the same syntax as above.

## Document Manager vs Code Plugin

| | Document Manager | Code Plugin |
|---|---|---|
| **Purpose** | Uploaded knowledge files | Sandbox workspace files |
| **Search** | Semantic (meaning-based) | Text/grep (exact match) |
| **Reading** | Chunk previews | Full file content |
| **Writing** | ✗ | ✓ |
| **Requires sandbox** | Only for sandbox-driver files | Always |
| **Best for** | Reference documents, PDFs, data | Source code, config files, scripts |

## How agents use it

Code Plugin is designed for agents that work on software projects or maintain files in their sandbox over time:

- Navigate an unfamiliar codebase before making changes
- Read a function's implementation before calling or modifying it
- Search for all usages of a symbol before renaming it
- Apply a targeted fix without rewriting an entire file
- Inspect build output or log files
- Maintain configuration files across thinking cycles

**Typical pattern for a code change:**
```
[code search]calculateTotal | path:app/Services[/code]
[code read]app/Services/OrderService.php | around:calculateTotal[/code]
[code edit]
path: app/Services/OrderService.php
search: return $sum;
replace: return round($sum, 2);
[/code]
```
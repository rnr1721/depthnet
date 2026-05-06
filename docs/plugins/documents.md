# Document Manager Plugin

The Document Manager gives the agent access to files uploaded by users or stored in the preset's workspace. Unlike vector memory (which stores short knowledge fragments), Document Manager works with **full files** — PDFs, spreadsheets, code, plain text — chunking them automatically for semantic search.

Files live in one of two storage backends depending on how they were uploaded. The agent searches across all files visible to its preset and can inspect or delete them.

## How it works

When a file is uploaded, it passes through a processing pipeline:

1. **Storage** — the file is saved to the configured driver (Laravel storage or sandbox filesystem)
2. **Processing** — a type-specific processor extracts text and splits it into overlapping chunks
3. **Indexing** — each chunk gets a TF-IDF vector and optionally a dense embedding vector
4. **Search** — on query, chunks are ranked by cosine similarity (embedding → TF-IDF fallback)

This means the agent can search a 200-page PDF by meaning, not just keywords.

## Storage drivers

| Driver | Where files live | Agent access |
|---|---|---|
| **Laravel storage** | `storage/app/presets/{id}/files/` | Read-only (via chunks) |
| **Sandbox** | `~/files/` inside the assigned sandbox | Full — agent can read, modify, execute |

Use **Laravel storage** when the user uploads reference documents the agent should know about. Use **Sandbox** when the agent needs to work with the file directly — process it, modify it, run it through a script.

## File visibility (scope)

| Scope | Visible to |
|---|---|
| **Private** | Only the owning preset |
| **Global** | All presets |

## Setup

Enable the **Document Manager** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Search result limit** | Maximum chunks returned per search query (1–20). Default: `5`. |
| **Similarity threshold** | Minimum similarity score (0.0–1.0). Default: `0.2`. |
| **Content preview length** | Characters shown in file preview (100–2000). Default: `500`. |

## Uploading files

**Via the Document Manager UI** (`Admin → Documents`):
- Select a preset, choose storage driver and scope, upload any file
- Processing runs automatically — status shows `processed` with chunk count when ready

**Via chat**:
- Use the paperclip button in the message input to attach one or more files
- Files are uploaded together with the message and processed immediately
- The agent sees an attachment note in the message with file IDs and chunk counts

## RAG integration

Document Manager integrates with the multi-RAG pipeline. To have file contents automatically retrieved before each thinking cycle:

1. Go to `Admin → Presets → RAG Configs` for your preset
2. Add or edit a RAG config
3. Enable **Files** in the sources list

The agent will then receive relevant file chunks in `[[rag_context]]` alongside vector memory, journal, and other sources — automatically, without needing to call the plugin explicitly.

## Commands

| Command | Description |
|---|---|
| `[documents search]query[/documents]` | Search file contents by meaning |
| `[documents list][/documents]` | List all files visible to this preset |
| `[documents show]42[/documents]` | Show file metadata and content preview |
| `[documents delete]42[/documents]` | Delete a file permanently |
| `[documents]query[/documents]` | Shorthand for search |

In `tool_calls` mode, use method names `search`, `list`, `show`, `delete` with the content argument.

## Supported file types

| Type | Extensions | Notes |
|---|---|---|
| Plain text | `.txt` `.md` `.html` `.json` `.csv` `.xml` | Chunked by paragraph/sentence boundaries |
| Code | `.php` `.py` `.js` `.ts` `.sh` and more | Chunked as text |
| PDF | `.pdf` | Requires `smalot/pdfparser` |
| Spreadsheets | `.xls` `.xlsx` `.ods` | Requires `phpoffice/phpspreadsheet` |
| Other | any | Binary detection — stored without chunks if not readable as text |

Files without text content (images, binary) are still stored and accessible to the agent in sandbox mode via the terminal.

## Sandbox files and the agent

When a file is stored in the **Sandbox** driver, the agent can work with it directly using the Terminal or Sandbox plugins. The `[documents show]` command includes the sandbox path:

```
Sandbox path: ~/files/report.csv
Tip: accessible via terminal, agent can read/modify directly
```

The agent can then read, process, or transform the file using shell commands or code execution.

## How agents use it

Document Manager is best for tasks where the agent needs to **reference external knowledge** or **work with structured data**:

- User uploads a product catalogue → agent answers questions about it via search
- User uploads a CSV → agent processes it in the sandbox with a Python script
- User uploads documentation → agent searches it before answering technical questions
- Agent accumulates project files in its sandbox workspace over multiple sessions
---
name: documents
description: Working with files stored for you — documents the user uploaded, split into searchable chunks. Use when an answer may be in a provided document, when the user mentions a file they gave you, or when you need to see which files exist.
metadata:
  depthnet-tools: "documents"
---

`documents` reaches the files stored for you: PDFs, text, spreadsheets and other formats, already split into chunks and indexed. You do not upload files here — they arrive through the chat. The tool's own description shows the exact syntax; this skill is about using it well.

## Finding things

- `search` finds chunks by meaning across all your files and shows each with its file name, file ID, chunk number and a match score. Ask in the words the document itself would use, not in the words of your task.
- A chunk is a fragment, cut to a fixed size. If the answer seems to continue past it, search again with words from the fragment, or open the file.
- `list` shows every file with its ID, size, status and chunk count. A file that is not yet processed, or failed, cannot be searched — `list` and `show` say which.
- `show` gives a file's details and the beginning of its content. Files marked as sandbox files also have a path in the workspace and can be read in full with the terminal, if you have it.

## Documents and memory

A document is source material, not memory. What you conclude from it — a fact, a decision, something to apply again — belongs in memory, with a mention of the file it came from, so you can return to the original later.

If you have `knowledge`, it can search documents too, alongside memory, when you ask it to recall from documents. Its hits carry a reference like `doc:42#3` — file 42, chunk 3. That reference is the mention to keep: it is short, exact, and leads back to the file here.

## Deleting

Deleting removes the file and its chunks permanently. The files were given to you by someone else; delete only when asked to.

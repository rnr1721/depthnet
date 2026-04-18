# Vector Memory Defrag

This directory contains configuration files for the vector memory defragmentation feature.

## How it works

Defrag compresses raw vector memory records grouped by calendar day into a smaller number
of distilled summaries. This keeps the memory store compact and improves retrieval quality
over time by removing noise and duplicates.

The process runs oldest-day-first so history is compressed incrementally.
Days that already have `defrag_keep_per_day` records or fewer are skipped automatically.

## Files

- `default_prompt.txt` — system prompt used when a preset has no custom `defrag_prompt` configured.

## Prompt variables

The following placeholders are replaced at runtime:

| Variable  | Description                                          |
|-----------|------------------------------------------------------|
| `{keep}`  | Number of distilled records to produce (`defrag_keep_per_day` from preset config) |

## Custom prompt

You can override the prompt per preset via the `defrag_prompt` field in the preset settings.
The same `{keep}` placeholder is supported in custom prompts.

## Running manually

```bash
# All presets with defrag_enabled = true
php artisan agent:defrag

# Specific preset by ID (ignores defrag_enabled flag)
php artisan agent:defrag --preset=3
```

## Scheduling

Set `defrag_schedule` (cron string) on a preset to run defrag automatically.
Leave it `null` to run manually only.
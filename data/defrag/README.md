# Vector Memory Defrag

This directory contains configuration files for the vector memory defragmentation feature.

## How it works

Defrag compresses raw vector memory records grouped by calendar day into a smaller number
of distilled summaries. This keeps the memory store compact and improves retrieval quality
over time by removing noise and duplicates.

Each day's records are sent to the model with the calendar date for context. The model
returns a JSON array of crystallized memories, each prefixed with a time-of-day marker
(`Morning:`, `Afternoon:`, `Evening:`, `Night:`, `Late night:`). These markers are used
to set approximate timestamps on the distilled records, preserving intra-day chronology.

The process runs oldest-day-first so history is compressed incrementally.
Days that already have `defrag_keep_per_day` records or fewer are skipped automatically.

A cache lock (`defrag_lock:{preset_id}`, TTL 5 minutes) prevents concurrent defrag runs
on the same preset. If a preset is already being processed, subsequent runs skip it.

## Files

- `default_prompt.txt` — system prompt used when a preset has no custom `defrag_prompt` configured.

## Prompt variables

The following placeholders are replaced at runtime:

| Variable    | Description                                          |
|-------------|------------------------------------------------------|
| `[[keep]]`  | Number of distilled records to produce (`defrag_keep_per_day` from preset config) |

## Expected model response

A valid JSON array of strings, one per distilled memory. No preamble, no markdown fences.

Example:
```json
[
  "Morning: The user reported a critical login bug affecting Safari users, first observed at 09:23. The issue was traced to a missing polyfill for WebAuthn. This reveals a gap in cross-browser testing coverage.",
  "Evening: The team lead approved the architecture proposal after three rounds of review, noting 'this is the cleanest design we've had all year.' The decision unblocks the next sprint and signals growing trust in the engineering process."
]
```

## Custom prompt

You can override the prompt per preset via the `defrag_prompt` field in the preset settings.
The same `[[keep]]` placeholder is supported in custom prompts.

Custom prompts must still enforce:
- Output format: JSON array of strings only
- Time-of-day marker at the start of each memory string
- No calendar dates in the memory text itself

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

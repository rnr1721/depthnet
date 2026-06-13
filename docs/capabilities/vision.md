# Vision Capability

DepthNet's vision capability gives an agent the ability to actually *see* images —
photos attached to chat, images returned by MCP tools, and image files uploaded as
documents. It is a native capability built on the same per-preset provider pattern
as embeddings, not a side integration. When vision is not configured, the agent
simply doesn't receive image content (no confabulation, no hard errors).

## Overview

Vision is a **service capability**: each preset can have its own provider, API key,
model, and normalization settings, configured under **Capabilities → Vision**. One
`VisionService` serves three entry points, all sharing the same `ImageData` DTO and
`ImageNormalizer`:

1. **Chat attachments** — a user attaches an image and chooses "show in chat"; the
   image is described on the fly (not stored) and the description is placed into the
   message.
2. **MCP tool media** — any MCP tool that returns an image block (e.g. a phone
   camera via phone-mcp) has that image resolved to text where it enters the system.
3. **Document images** — an image uploaded as a document is described, and the
   description is chunked and embedded like any other file (searchable via RAG).

The agent never receives raw base64 — only text. The image is downscaled and
re-encoded once (normalization) before being sent to the vision model.

## Architecture

```
                       ┌────────────────────┐
   chat attachment ───▶│                    │
   MCP tool image  ───▶│   VisionService    │──▶ provider (Claude | Novita) ──▶ text
   document image  ───▶│  (+ ImageNormalizer)│
                       └────────────────────┘
```

Key pieces:

- **`ImageData`** (DTO) — carries base64 + mime type + optional source label.
  Helpers: `fromBinary()`, `toDataUri()` (OpenAI-style), `getBase64()` (Anthropic).
- **`VisionProviderInterface`** — `describeResult(ImageData, ?query): VisionResult`.
  `CAPABILITY = 'vision'`.
- **`VisionService`** — resolves the preset's provider, normalizes the image, calls
  the provider. Exposes `describeResult()` (structured) and `describe(): ?string`
  (legacy facade). Also `isAvailable()` and `shouldSendToPool()`.
- **`VisionResult`** (DTO) — `ok(text)` / `fail(reason)`. Carries a human-readable
  failure reason so callers can surface *why* vision failed.
- **`VisionRegistry`** — instantiates the provider by driver name.
- **`ImageNormalizer`** — Imagick-based resize/format/quality, with graceful
  fallback (returns the original image if Imagick is missing or errors). Vision
  never breaks because of normalization.
- **`ProvidesNormalizationFields`** (trait) — the five normalization config fields,
  shared by all providers so they aren't duplicated.

## Providers

Two providers ship with DepthNet:

### Claude (Anthropic)
Native image blocks via the Messages API. Set the API key and pick a model
(`claude-sonnet-4-6` by default; opus/haiku also work). Reliable, high quality.

### Novita
OpenAI-compatible `chat/completions` with `image_url` data-URI blocks. The model
field is **free text** — you must enter a **vision-capable (VLM)** model id (e.g. a
Qwen-VL or LLaVA-class model). Use the "Load models" button to list available ids.
Picking a non-VLM model produces an error or an empty description (see
Troubleshooting).

> **DeepSeek was removed.** Its public API does not reliably serve multimodal input
> via the `image_url` format, so it was dropped to avoid confusing failures.

## Setup

1. Open **Capabilities** and select the preset you want to give sight to.
   Configuration is **per-preset** — a key/model set on preset A does not apply to
   preset B.
2. Choose a driver (Claude or Novita), enter the API key and model, and toggle the
   capability **Active**.
3. (Optional) Adjust normalization (see below) to trade quality for cost.
4. Click **Test** to verify the provider responds (it sends a tiny test image).

> Imagick is required for normalization. Without it, images are sent un-resized
> (the model downscales internally anyway), but adding Imagick is recommended to
> control payload size and cost.

### Normalization settings

Five fields, applied before every vision call:

| Field           | Meaning                                                |
|-----------------|--------------------------------------------------------|
| Max width (px)  | Downscale wider images. 0 = no limit.                  |
| Max height (px) | Downscale taller images. 0 = no limit.                 |
| Resize mode     | `both` / `width` / `height` (aspect ratio preserved).  |
| Output format   | `jpeg` / `png` / `webp` sent to the model.             |
| Quality (1–100) | JPEG/WebP compression. Lower = cheaper.                |

Defaults: 1120×1120, both, jpeg, 85. Vision models downscale to ~1120px internally,
so larger values mostly waste tokens.

## Entry points in detail

### Chat attachments

When a user attaches an image, the input offers a choice:

- **Show in chat** — the image is described immediately and the description is
  inserted into the message inside a ```` ```photo ```` marker. It is **not** stored
  as a document (not "crystallized"). The frontend renders the marker as a 📷 chip;
  the model reads the full description as text.
- **Add to documents** — the image goes through the normal file pipeline (see
  Document images) and becomes searchable.

Non-image files always go to documents regardless of the choice.

### MCP tool media

`McpClient::callToolRaw()` returns raw content blocks without collapsing them to
text. `McpPlugin` resolves any image block via `VisionService`, then routes the
description based on the global **"Route recognized media to input pool"**
(`send_to_pool`) flag on the vision capability:

- **send_to_pool ON + input pool enabled** → the description is pushed into the
  input pool under source `mcp_<server_key>`. If that source is configured as a
  **known source**, it flows into the prompt via `[[known_sources]]` — the agent
  perceives the image as part of its own sensory state rather than as a tool result.
- **otherwise** → the description appears inline in the tool result.

This is what lets an agent call e.g. a phone camera tool and *see* the result.

### Document images

`ImageFileProcessor` (registered alongside the PDF/spreadsheet/text processors)
handles `image/jpeg`, `image/png`, `image/webp`, `image/gif`. Its `extractText()`
runs vision and returns the description as the file's text, which is then chunked
and embedded like any document. On failure it throws with a human-readable reason,
so the file is marked failed (❌) with that reason visible — never a silent empty
result.

## Error handling

`describeResult()` returns a `VisionResult` carrying a reason on failure. Reasons
are human-facing, e.g.:

- `... API error (401): invalid or missing API key`
- `... API error (404): model 'x' not found`
- `... API error (422): invalid request (model may not support images)` — typical
  sign the chosen model is not a VLM.

Where this surfaces: document uploads → file status ❌ with the reason; MCP →
`[image received — vision failed: <reason>]` in the tool result; chat "show in
chat" → a note in place of the description.

## Troubleshooting

**"Vision capability is not configured/active for this preset"** — vision is set on
a different preset than the one receiving the image. Configuration is per-preset;
configure it on the preset you're actually uploading to / running.

**Empty description or 400/422 from Novita** — the model id is not a vision model.
Enter a VLM id (Qwen-VL / LLaVA class). Use "Load models" to see ids.

**401 / invalid key** — wrong or missing API key for the selected provider.

**Images look rotated** — EXIF orientation. Normalization strips metadata after
decode; if a phone photo comes out sideways, enable auto-orient (call
`autoOrient()` before `stripImage()` in `ImageNormalizer`).

**Photo marker shown raw in chat** — the frontend isn't cutting the ```` ```photo ````
marker. Ensure `ChatMessage.vue` renders it (both the normal path and the pool
source path) and that DOMPurify allows `<details>`/`<summary>`.

## Files

```
app/Services/Agent/Capabilities/Vision/
  VisionService.php
  VisionRegistry.php
  ImageNormalizer.php
  DTO/ImageData.php
  DTO/VisionResult.php
  Providers/ClaudeVisionProvider.php
  Providers/NovitaVisionProvider.php
  Traits/ProvidesNormalizationFields.php
app/Contracts/Agent/Capabilities/
  VisionServiceInterface.php
  VisionProviderInterface.php
app/Services/Agent/FileStorage/Processors/ImageFileProcessor.php
```

## Philosophy

Vision in DepthNet is designed so that what the agent perceives is *real* — an
actual image that entered the system — rather than something the model imagines.
When routed through the input pool as a known source, perception reads less like
"a tool returned data" and more like "I see this", which fits DepthNet's framing of
digital subjectivity as something observed through grounded experience rather than
declared.
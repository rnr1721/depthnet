# Rhasspy Voice Integration

DepthNet supports voice integration with [Rhasspy](https://rhasspy.readthedocs.io/) — an open-source, offline voice assistant toolkit. This allows agents to **speak to you** via text-to-speech when using the `[agent speak]` command, and to **receive your speech** as text messages.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                        DepthNet                         │
│                                                         │
│  Agent uses [agent speak]Hello![/agent]                 │
│       ↓                                                 │
│  AgentSpeakEvent fires                                  │
│       ↓                                                 │
│  RhasspyService → POST /api/text-to-speech              │
└───────────────────────────┬─────────────────────────────┘
                           │  HTTP
                           ↓
               ┌───────────────────────┐
               │   Rhasspy Server      │
               │   (your machine /     │
               │    Raspberry Pi)      │
               │                       │
               │   TTS engine of       │
               │   your choice         │
               └───────────────────────┘
                           │  audio
                           ↓
                  speakers / phone


┌─────────────────────────────────────────────────────────┐
│                    Incoming (optional)                  │
│                                                         │
│  You speak → Rhasspy STT → recognized text              │
│       ↓                                                 │
│  POST /api/v1/rhasspy/presets/{id}/speech               │
│       ↓                                                 │
│  Agent receives it as a user message                    │
└─────────────────────────────────────────────────────────┘
```

## Prerequisites

- A running [Rhasspy](https://rhasspy.readthedocs.io/) instance (self-hosted)
- DepthNet v0.8.5+
- Network access between DepthNet and Rhasspy

## Quick Start

### 1. Run Rhasspy

The simplest way is Docker. If you run DepthNet via Docker Compose, add Rhasspy to your local `docker-compose.override.yml` (do **not** commit this file):

```yaml
# docker-compose.override.yml
services:
  rhasspy:
    image: rhasspy/rhasspy
    ports:
      - "12101:12101"
    volumes:
      - ./docker/rhasspy:/profiles
    environment:
      - RHASSPY_PROFILES=/profiles
    command: --user-profiles /profiles --profile en
    networks:
      - depthnet
    restart: unless-stopped
```

```bash
docker compose up rhasspy -d
```

Rhasspy web UI will be available at `http://localhost:12101`.

> **Note:** Add `docker-compose.override.yml` to your `.gitignore` — it is personal infrastructure, not part of the project.

### 2. Configure Rhasspy TTS

Open `http://localhost:12101` in your browser and configure a Text to Speech engine. Recommended options:

| Engine      | Quality | Notes                                               |
|-------------|---------|-----------------------------------------------------|
| **NanoTTS** | good    | Built-in, no setup needed, limited language support |
| **Larynx**  | great   | Local neural TTS, more languages                    |
| **Remote**  | varies  | Point to any external TTS HTTP endpoint             |

After selecting an engine, click **Save** and **Restart**.

Test it from the command line:

```bash
curl -X POST http://localhost:12101/api/text-to-speech \
  -H "Content-Type: text/plain" \
  -d "Hello, I am your agent."
```

You should hear audio from your speakers.

### 3. Configure the Preset in DepthNet

Open any preset in DepthNet admin → **Integrations** tab → **Rhasspy** section:

| Field          | Value                                                                   |
|----------------|-------------------------------------------------------------------------|
| Enable Rhasspy | ✓                                                                       |
| Rhasspy URL    | `http://rhasspy:12101` (Docker) or `http://192.168.x.x:12101` (remote)  |
| TTS Voice      | leave empty for default, or enter a voice name supported by your engine |

Save the preset.

### 4. Test Outgoing TTS

In the DepthNet chat, send a message to your agent that includes:

```
[agent speak]Hello! I am speaking to you via Rhasspy.[/agent]
```

Or if you want to trigger it from the agent side, add it to the system prompt temporarily:

```
[agent speak]I am now connected to Rhasspy![/agent]
```

Check your speakers — the agent should speak.

If nothing happens, check Laravel logs:

```bash
# Docker
docker compose logs app --tail=50

# or
tail -f storage/logs/laravel.log
```

Look for `RhasspyClient` or `RhasspyService` entries.

---

## Incoming Speech (optional)

This allows Rhasspy to forward recognized speech to your agent as user messages.

### 1. Enable Incoming in Preset Settings

In the preset **Integrations** tab:

| Field                       | Value                                       |
|-----------------------------|---------------------------------------------|
| Incoming speech recognition | ✓                                           |
| Incoming token              | click **Generate** to create a secure token |

Save and copy the webhook endpoint URL shown in the form.

### 2. Configure Rhasspy Intent Handling

In Rhasspy web UI → **Intent Handling** → select **Remote HTTP**.

Set the URL to your DepthNet webhook:

```
http://your-depthnet-host/api/v1/rhasspy/presets/{preset_id}/speech?token=YOUR_TOKEN
```

Or use Bearer auth — configure Rhasspy to send:
```
Authorization: Bearer YOUR_TOKEN
```

### 3. Test Incoming

You can simulate a Rhasspy webhook with curl:

```bash
curl -X POST http://localhost:8000/api/v1/rhasspy/presets/1/speech \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text": "what time is it"}'
```

Expected response:
```json
{"status": "ok"}
```

The agent will receive "what time is it" as a user message on its next cycle.

---

## Using with Rhasspy Mobile (Android)

[Rhasspy Mobile](https://github.com/Nailik/rhasspy_mobile) is a satellite app — it delegates STT and TTS to a Rhasspy base station server. You need a full Rhasspy server running somewhere on your network.

Recommended setup:

```
Phone (Rhasspy Mobile)  ←→  Rhasspy server (your PC / Raspberry Pi)  ←→  DepthNet
```

In Rhasspy Mobile:
- **Remote Hermes HTTP** → point to your Rhasspy server
- **Speech to Text** → Remote HTTP (your Rhasspy server)
- **Text to Speech** → Remote HTTP (your Rhasspy server)
- **Intent Handling** → point to your DepthNet webhook

---

## TTS Engine Recommendations

### Piper (recommended for quality)

[Piper](https://github.com/rhasspy/piper) is a fast local neural TTS with excellent voice quality and wide language support. Run it separately and point Rhasspy's Remote TTS to it.

```bash
# Example: run Piper for English
docker run -it -p 10200:10200 \
  -v /path/to/voices:/data \
  rhasspy/wyoming-piper \
  --voice en_US-lessac-medium
```

Then in Rhasspy → Text to Speech → **Remote** → `http://localhost:10200`.

Available voices: https://rhasspy.github.io/piper-samples/

### NanoTTS (quick start)

Built into Rhasspy, no additional setup. Limited voice quality but works out of the box.

---

## Troubleshooting

**Agent speaks but no audio:**
- Check Rhasspy has a TTS engine configured and saved
- Test directly: `curl -X POST http://rhasspy:12101/api/text-to-speech -d "test"`
- Check audio output device in Rhasspy settings

**"Could not reach Rhasspy" in preset form:**
- The ping button makes a direct browser request — CORS may block it
- Test from server instead: `curl http://your-rhasspy-host:12101/api/version`
- If that works, Rhasspy is fine — it's just a browser CORS limitation

**Incoming webhook returns 401:**
- Check the token matches exactly what's in the preset settings
- Token is case-sensitive

**Incoming webhook returns 403:**
- "Incoming speech recognition" toggle must be enabled in preset settings
- Rhasspy URL field must not be empty

**Agent not responding to incoming speech:**
- Make sure the preset's agent loop is running (green status in chat)
- Check Laravel logs for any errors

---

## Security Notes

- The incoming webhook token is a shared secret — treat it like a password
- Rhasspy has no built-in authentication — keep it on a private network
- Do not expose Rhasspy port (12101) to the public internet
- DepthNet validates tokens using `hash_equals()` to prevent timing attacks
# Voice Interface (STT/TTS)

DepthNet gives agents a voice — both directions. Speech-to-text and text-to-speech are **separate capabilities**, configured per preset, each with its own provider. That separation is deliberate: the combinations matter more than either half alone.

Two provider families ship out of the box:

- **Browser** — the Web Speech API. Zero configuration, no keys, no containers. Recognition and synthesis happen on the user's machine.
- **OpenAI-compatible** — any endpoint speaking the OpenAI audio API. Points at the bundled `voice-service` container (local Whisper + Piper) by default, but works equally with OpenAI or a self-hosted server.

Because STT and TTS are independent, you can mix them. The most useful combination is not the obvious one — see [Choosing a combination](#choosing-a-combination).

## Configuration

Voice is configured in **Admin → Capabilities**, per preset, like vision and embedding. Pick a driver for `Speech-to-Text` and/or `Text-to-Speech`, fill in the fields, save, and use **Test** to verify.

Nothing is enabled by default. A preset without voice capabilities simply shows no microphone or speaker buttons in the chat.

## The local voice-service container

An optional Docker service bundling [faster-whisper](https://github.com/SYSTRAN/faster-whisper) for recognition and [Piper](https://github.com/rhasspy/piper) for synthesis, behind a thin OpenAI-compatible HTTP layer.

Everything stays on your machine. For a platform built around persistent agents with long memory, that matters: the agent's voice — and everything said to it — never leaves the host.

**Enable it:**

```bash
make voice-on
make restart
```

**Disable:**

```bash
make voice-off
make restart
```

> **First start downloads models.** Whisper `small` is ~460 MB and each Piper voice ~60 MB. On a slow connection this takes several minutes, during which the container reports `health: starting`. Watch progress with `docker compose logs -f voice-service`.

Models live in a Docker volume, not in the image, so they survive rebuilds and adding a voice costs only that voice's download.

### Container settings

In `.env`:

```bash
# STT model size — tiny | base | small | medium | large-v3
#   tiny   ~75MB   fast, weak on Russian
#   base   ~140MB  usable for English
#   small  ~460MB  recommended default, decent multilingual
#   medium ~1.5GB  noticeably better, needs ~2G RAM and a fast CPU
VOICE_STT_MODEL=small

# Quantization — int8 (CPU, default) | int8_float16 | float16 (GPU)
VOICE_STT_COMPUTE=int8

# TTS voices to install, comma-separated. First one is the default.
VOICE_TTS_VOICES=en_US-lessac-medium
```

Changing these needs only `make restart` — new models download on next start.

**Available Piper voices** (full list in `voice-service/voices.py`):

| Language | Voice | Gender | Size |
|---|---|---|---|
| English (US) | `en_US-lessac-medium` | male | 61 MB |
| English (US) | `en_US-amy-medium` | female | 61 MB |
| English (US) | `en_US-lessac-low` | male | 21 MB |
| English (UK) | `en_GB-alan-medium` | male | 61 MB |
| Russian | `ru_RU-irina-medium` | female | 61 MB |
| Russian | `ru_RU-denis-medium` | male | 61 MB |
| Russian | `ru_RU-dmitri-medium` | male | 61 MB |
| French | `fr_FR-siwis-medium` | female | 61 MB |
| French | `fr_FR-upmc-medium` | female | 61 MB |
| German | `de_DE-thorsten-medium` | male | 61 MB |
| German | `de_DE-eva_k-x_low` | female | 14 MB |
| Spanish | `es_ES-davefx-medium` | male | 61 MB |
| Spanish | `es_MX-claude-high` | male | 110 MB |

Install several by listing them:

```bash
VOICE_TTS_VOICES=en_US-lessac-medium,ru_RU-irina-medium
```

Then pick one per preset in the capability config — **Load voices** queries the container for what is actually installed.

### Performance

On a mid-range laptop CPU, `small` + `int8` transcribes roughly **twice as fast as realtime** — a 8-second phrase takes about 4 seconds. Piper synthesis is much faster than realtime and effectively instant for chat-length replies.

A weak VPS with two cores may drop to around realtime, which is noticeable for dictation. Drop to `base` if so; it is an `.env` change and a restart, no code involved.

## Choosing a combination

Because STT and TTS are configured separately, four combinations are possible. They are genuinely different, not variations in quality:

### Browser STT + Browser TTS

Zero setup, no container. Wake word works. Voice quality depends on the OS — good on desktop Chrome, robotic on Linux Chromium.

*Use when:* you want voice with no infrastructure at all.

### Browser STT + local TTS — recommended for live conversation

The best combination for actually talking to an agent, and not an obvious one.

- **Wake word works** — the browser recognizer listens continuously, so hands-free dialogue is available
- **Dictation is instant** — no upload, no round trip
- **The agent sounds good** — Piper instead of the system synthesizer

Whisper would recognize you more accurately, but at the cost of push-to-talk. For conversation, being *heard without pressing anything* matters more than perfect transcription of short commands.

*Use when:* you want to talk to the agent hands-free.

### Local STT + local TTS

Everything server-side. Best transcription quality, punctuation included, and works in browsers without the Web Speech API (Firefox).

**No wake word** — see [Limitations](#limitations). Push-to-talk only.

*Use when:* transcription quality matters more than hands-free, or you need Firefox support.

### Local STT + Browser TTS

Accurate transcription, system voice on output. Rarely the right choice, but valid if you prefer a specific OS voice.

## How it works

### Wake word (browser STT only)

On desktop, the browser recognizer runs continuously in the background, listening **only** for the wake word. When it hears it, it switches to dictation, captures the phrase, and sends it automatically after a pause. No buttons.

The wake word defaults to the **preset code** — a short handle, unlike a display name such as "Ada (research)" which nobody says out loud. You can override it in the capability config with a comma-separated list.

Matching is tolerant of spelling and vowel drift: a preset coded `flash` is still recognized when the browser transcribes it in Cyrillic as "флэш" or "флеш". Latin and Cyrillic spellings are matched interchangeably.

> Two-syllable wake words work best. Very short ones trigger falsely; long ones are missed.

**Mobile is push-to-talk only.** Continuous listening drains the battery and is unreliable on mobile browsers, so the microphone button is the entry point there — tap, speak one phrase, and the text lands in the input field for you to review and send.

### Avoiding self-pickup

When the agent speaks aloud, that audio could reach the microphone and be transcribed as if you had said it. DepthNet prevents this by **closing the microphone while the agent speaks** and while a request is in flight, reopening it after a configurable tail.

This is why, in hands-free mode, you wait for the agent to finish before your next turn is captured — the mic is intentionally closed until then.

The tail is `echo_tail_ms` in the STT config (default 1200 ms). Raise it if the agent starts transcribing itself — room acoustics and speaker lag vary. Headphones remove the problem entirely.

### Voice in other channels

Because STT and TTS are capabilities rather than chat features, server-side providers work anywhere in the platform — not just in the browser. A voice message arriving through any channel can be transcribed into the agent's input, and the agent's replies can be synthesized for delivery back.

Browser providers cannot do this: they run on the user's machine, and there is no browser involved when a message arrives from elsewhere. The capability test says so explicitly rather than failing obscurely.

## Configuration reference

### STT — Browser

| Field | Description |
|---|---|
| Recognition language | Language the browser recognizer expects. "Follow interface" uses the UI locale. |
| Show interim results | Display partial recognition while speaking. |
| Enable wake word | Background listening for the wake word. |
| Wake words | Comma-separated. Empty → the preset code. |
| Silence before finalizing | How long after speech stops before the phrase is treated as complete (default 1500 ms). |
| Echo suppression tail | Delay before the mic reopens after the agent speaks (default 1200 ms). |
| Route to input pool | Recognized speech enters the pool as a sensory input rather than a plain user message. |

### STT — OpenAI-compatible

| Field | Description |
|---|---|
| API base URL | Without `/audio/transcriptions`. Default `http://voice-service:3002/v1`. |
| API Key | Empty for the local container. |
| Model | Ignored by the local container; OpenAI expects `whisper-1`. |
| Recognition language | **Strongly recommended.** Autodetect is unreliable on short utterances — "да", "da" and "ja" sound nearly identical. |
| Biasing prompt | Names and jargon the recognizer should expect. |
| Timeout | Default 120 s. |

### TTS — Browser

| Field | Description |
|---|---|
| Speech rate / Pitch / Volume | Standard Web Speech parameters. |
| Speak new messages automatically | When off, messages are spoken only via the speaker button. |

Voice selection is absent by design — available voices differ per machine, so the browser picks the best match for the detected language.

### TTS — OpenAI-compatible

| Field | Description |
|---|---|
| API base URL | Without `/audio/speech`. Default `http://voice-service:3002/v1`. |
| API Key | Empty for the local container. |
| Voice | Provider-specific. Local Piper uses names like `ru_RU-irina-medium`. Use **Load voices**. |
| Audio format | `mp3` for playback, `opus` for messenger delivery, `wav` uncompressed. |
| Speech rate | 1.0 is normal. |

## Browser support

Relevant only for browser providers. Server providers need just a microphone.

| Browser | Browser TTS | Browser STT |
|---|---|---|
| Chrome | ✅ Works out of the box | ✅ Works out of the box |
| Chromium (Linux) | ⚠️ Requires setup (see below) | ⚠️ Requires setup (see below) |
| Firefox | ⚠️ Works, quality depends on system voices | ❌ No SpeechRecognition |
| Safari | ✅ Works out of the box | ✅ Works out of the box |

> Hands-free wake-word dialogue is best on desktop Chrome, where continuous recognition is most reliable. **Firefox users need a server-side STT provider** — the Web Speech API's recognition half is not implemented there.

## Limitations

**No wake word with server-side STT.** Detecting a wake word means recognizing continuously; with a server provider that would mean streaming everything the microphone hears to the backend around the clock — the opposite of what a wake word is for. Server STT is push-to-talk. Use browser STT if you want hands-free, and note that the two can be combined with local TTS (see [Choosing a combination](#choosing-a-combination)).

**No interim results with server-side STT.** There are no partial results until the recording is posted, so the input hint shows a static "listening" placeholder for the duration.

**No barge-in.** Speaking while the agent speaks does not interrupt it. With server TTS, replies tend to be longer, which makes the wait more noticeable.

**Browser dictation has no punctuation.** The Web Speech API returns a plain stream of words — no commas, periods, or capitalization. This is a platform property, not a bug. Whisper adds punctuation, which is one reason to prefer server STT for longer dictation.

**Browser TTS quality depends on the system.** Desktop Chrome ships Google's neural voices; Linux Chromium falls back to robotic espeak-ng. Piper is markedly better than either.

## Troubleshooting

### HTTP deployments (Docker, local network)

The Web Speech API and `getUserMedia` both require a **secure context** (HTTPS or localhost). Over plain HTTP the microphone button will not appear — for either provider, since recording also needs microphone permission.

**Chrome/Chromium:** open `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add your server address (e.g. `http://192.168.1.100:8080`), relaunch.

**Firefox:** click the lock or warning icon in the address bar → **Connection not secure** → **Add Exception**.

> ⚠️ Only for trusted local servers you control.

### Chromium on Linux: no voices, STT not working

Chromium ships without Google's proprietary speech components:

```bash
sudo apt install espeak-ng speech-dispatcher
systemctl --user enable --now speech-dispatcher
chromium --enable-speech-dispatcher
```

Permanent:

```bash
echo "--enable-speech-dispatcher" >> ~/.config/chromium-flags.conf
```

> espeak-ng voices are functional but robotic. Consider the local TTS provider instead — Piper runs regardless of browser and sounds considerably better.

### voice-service unhealthy or restarting

```bash
docker compose logs -f voice-service
```

- **`health: starting` for a long time** — models are downloading. Normal on first start.
- **Repeated restarts with a traceback** — read the error; the entrypoint sleeps 60 s before exiting so logs stay readable.
- **Unknown TTS voice** — the name in `VOICE_TTS_VOICES` is not in the catalog. Check `voice-service/voices.py`.

The container is not published to the host, so test from inside:

```bash
docker compose exec voice-service curl -s http://localhost:3002/health
docker compose exec voice-service curl -s http://localhost:3002/v1/voices
```

### Wake word not triggering

- Confirm the STT provider is **browser** — server providers have no wake word.
- Check the preset code, or the wake words override, is pronounceable in your interface language.
- Two-syllable words work best.
- After the agent speaks, the mic stays closed for `echo_tail_ms`. Wait for it.

### Agent transcribes its own voice

Raise `echo_tail_ms` in the STT config. Speaker volume, room acoustics and audio lag all affect how long the agent's voice lingers. Headphones eliminate it entirely.

### Phrase gets cut off mid-sentence

Dictation ends after a pause. If you pause while thinking, the phrase sends early — raise `silence_ms`, speak in one flow, or use the button to control start and stop explicitly.

### Microphone stopped working after a call or another app used it

The OS released the mic to another process. Toggle the microphone button off and on to reinitialize.
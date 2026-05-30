## Voice Interface (TTS/STT)

DepthNet includes a built-in voice interface powered by the browser's native Web Speech API — no external services or API keys required. On desktop you can hold a hands-free spoken conversation with an agent using a wake word; on mobile a push-to-talk button lets you dictate instead of typing.

By default, DepthNet is deployed in Docker over HTTP, so you will need to configure your browser (see [HTTP deployments](#troubleshooting-http-deployments-docker-local-network) below).

**Features:**
- **Voice output (TTS)**: The agent's `thinking` messages and responses are read aloud automatically when enabled. A speaker button also appears on each eligible message for manual playback.
- **Voice input (STT)**: Dictate instead of typing. Two ways in — a wake word (the agent's preset name) on desktop, or the microphone button anywhere.
- **Hands-free dialogue (desktop)**: With TTS and a wake word both enabled, you can simply talk — say the agent's name, speak your message, and it sends automatically when you pause. The agent replies aloud, and the microphone reopens for your next turn. No buttons.
- Both features are **progressively enhanced** — the buttons only appear if your browser supports the corresponding API.

### How it works

The interface language is detected automatically from your app locale settings, so wake-word matching and dictation use the language you've configured DepthNet in.

**Desktop** keeps the microphone open in the background, listening only for the wake word — which is the **name of the active preset**. When it hears the name, it switches to dictation, captures your phrase, and sends it automatically after a short pause. You can also press the microphone button to dictate directly without the wake word; in that case the recognized text goes into the input field for you to review and send.

**Mobile** uses push-to-talk only: tap the microphone, speak one phrase, and the recognized text is placed in the input field. You then send it with the send button (or keep editing first). There is no always-on wake word on mobile — continuous listening drains the battery and is unreliable on mobile browsers, so the button is the entry point.

> **Wake word = preset name.** The agent listens for whatever the active preset is called. If you rename the preset, the wake word changes with it. Matching is tolerant of spelling and vowel differences (e.g. a Latin-named preset like `Flash` is still recognized when the browser transcribes it in Cyrillic as "флэш"/"флеш"), so you don't usually need exact pronunciation.

### Avoiding self-pickup (TTS feeding back into STT)

When the agent speaks its reply aloud, that audio could be picked up by the microphone and transcribed as if you said it. DepthNet prevents this by **pausing the microphone while the agent is speaking** and while a request is being processed, then reopening it once the agent is done. This is why, in hands-free mode, you wait for the agent to finish before your next turn is captured — the mic is intentionally closed until then.

### Browser Support

| Browser          | Voice Output (TTS)                        | Voice Input (STT)             |
|------------------|-------------------------------------------|-------------------------------|
| Chrome           | ✅ Works out of the box                   | ✅ Works out of the box        |
| Chromium (Linux) | ⚠️ Requires setup (see below)             | ⚠️ Requires setup (see below)  |
| Firefox          | ⚠️ Works, quality depends on system voices| ✅ Requires flag (see below)   |
| Safari           | ✅ Works out of the box                   | ✅ Works out of the box        |

> The hands-free wake-word experience is best on desktop Chrome, where continuous recognition is most reliable. Other browsers support dictation via the button but may not keep an always-on wake-word listener running smoothly.

### Quality and limitations

The voice interface runs entirely in the browser, which sets some honest limits:

- **No punctuation in dictation.** The Web Speech API returns a plain stream of words with no commas, periods, or capitalization. Spoken input arrives as one continuous flow. This is a property of the platform, not a bug — browser speech recognition does not produce punctuation.
- **TTS voice quality depends on the system.** Output uses whatever voices the browser/OS provides. Desktop Chrome ships with Google's high-quality neural voices; Chromium on Linux falls back to robotic espeak-ng voices unless Chrome is used instead.
- **OS audio interruptions.** If the operating system takes over the microphone (e.g. an incoming phone call on mobile), the recognition session may not resume cleanly afterwards. Toggling the microphone off and on re-initializes it.

For higher quality (punctuation, neural voices) the recognition and synthesis would need to move server-side — out of scope for the built-in browser interface, which is designed to work with zero configuration and no API keys.

### Troubleshooting: HTTP deployments (Docker, local network)

The Web Speech API requires a **secure context** (HTTPS or localhost). If you're running DepthNet over plain HTTP (e.g. in Docker on a local network), the microphone button won't appear.

**Option 1 — Chrome/Chromium: mark your origin as trusted**

Open `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add your server address (e.g. `http://192.168.1.100:8080`), and relaunch the browser.

**Option 2 — Firefox: mark your origin as trusted**

Open `about:config` and set:
```
network.websocket.allowInsecureFromHTTPS = true
```
Then open `about:config` again and navigate to your site — Firefox will show an "Add Exception" dialog for the insecure origin.

The easiest way for Firefox is the address bar: click the lock icon (or the warning icon) → **Connection not secure** → **Add Exception**.

> ⚠️ Only do this for trusted local/development servers you control.

### Troubleshooting: Chromium on Linux (no voices, STT not working)

Chromium on Linux ships without Google's proprietary speech components. To enable both TTS and STT:

**1. Install system voices:**
```bash
sudo apt install espeak-ng speech-dispatcher
systemctl --user enable --now speech-dispatcher
```

**2. Launch Chromium with speech-dispatcher enabled:**
```bash
chromium --enable-speech-dispatcher
```

To make it permanent, add the flag to your Chromium config:
```bash
echo "--enable-speech-dispatcher" >> ~/.config/chromium-flags.conf
```

> **Note:** espeak-ng voices are functional but robotic-sounding. For better quality, consider using Google Chrome instead of Chromium — it includes Google's high-quality neural voices out of the box.

### Troubleshooting: wake word not triggering or speech cut off

- **Wake word isn't recognized:** make sure TTS/STT is enabled and the active preset's name is something the recognizer can transcribe in your interface language. Short or unusual names are harder to detect; a two-syllable name works best.
- **Phrase gets cut off mid-sentence:** dictation ends after a brief pause in speech. If you naturally pause while thinking, the phrase may send early. Speak in one continuous flow, or use the button to control start/stop explicitly.
- **Microphone stopped working after a phone call or other app used it:** the OS released the mic to another process. Toggle the microphone button off and on to re-initialize the session.
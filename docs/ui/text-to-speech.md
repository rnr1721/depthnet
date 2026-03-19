## Voice Interface (TTS/STT)

DepthNet includes a built-in voice interface powered by the browser's native Web Speech API — no external services or API keys required.

By default, DepthNet is deployed in Docker over HTTP, so you will need to configure your browser.

**Features:**
- **Voice output (TTS)**: Agent's `thinking` messages and responses are automatically read aloud when enabled. A speaker button also appears on each eligible message for manual playback.
- **Voice input (STT)**: Click the microphone button in the message input area to dictate instead of typing. The interface language is detected automatically from your app locale settings.
- Both features are **progressively enhanced** — the buttons only appear if your browser supports the corresponding API.

### Browser Support

| Browser          | Voice Output (TTS)                        | Voice Input (STT) |
|------------------|-------------------------------------------|-------------------|
| Chrome           | ✅ Works out of the box                   | ✅ Works out of the box            |
| Chromium (Linux) | ⚠️ Requires setup (see below)             | ⚠️ Requires setup (see below)  |
| Firefox          | ⚠️ Works, quality depends on system voices| ✅ Requires flag (see below)   |
| Safari           | ✅ Works out of the box                   | ✅ Works out of the box         |

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

Actually the easiest way for Firefox is the address bar: click the lock icon (or the warning icon) → **Connection not secure** → **Add Exception**.

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

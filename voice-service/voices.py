"""
Piper voice catalog.

Only voices listed here can be requested via TTS_VOICES. This is deliberate:
the entrypoint needs to know the download URL and the expected files, and an
unvalidated name would fail at runtime with a confusing error instead of at
startup with a clear one.

Adding a voice = adding one entry. URLs follow the rhasspy/piper-voices layout
on HuggingFace:
  <base>/<lang_code>/<lang_region>/<name>/<quality>/<full_name>.onnx

Sizes are approximate (onnx + json) and are shown in startup logs so users
understand what a config change will cost them in download time and disk.

DepthNet officially supports: en, ru, fr, de, es.
"""

VOICES_BASE_URL = (
    "https://huggingface.co/rhasspy/piper-voices/resolve/main"
)

# full_name -> metadata
# path is the directory under the base URL; full_name.onnx / .onnx.json live there.
CATALOG = {
    # ── English (US) ─────────────────────────────────────────────────────────
    "en_US-lessac-medium": {
        "path": "en/en_US/lessac/medium",
        "lang": "en",
        "gender": "male",
        "size_mb": 61,
    },
    "en_US-amy-medium": {
        "path": "en/en_US/amy/medium",
        "lang": "en",
        "gender": "female",
        "size_mb": 61,
    },
    "en_US-lessac-low": {
        "path": "en/en_US/lessac/low",
        "lang": "en",
        "gender": "male",
        "size_mb": 21,
    },
    "en_GB-alan-medium": {
        "path": "en/en_GB/alan/medium",
        "lang": "en",
        "gender": "male",
        "size_mb": 61,
    },
    # ── Russian ──────────────────────────────────────────────────────────────
    "ru_RU-irina-medium": {
        "path": "ru/ru_RU/irina/medium",
        "lang": "ru",
        "gender": "female",
        "size_mb": 61,
    },
    "ru_RU-denis-medium": {
        "path": "ru/ru_RU/denis/medium",
        "lang": "ru",
        "gender": "male",
        "size_mb": 61,
    },
    "ru_RU-dmitri-medium": {
        "path": "ru/ru_RU/dmitri/medium",
        "lang": "ru",
        "gender": "male",
        "size_mb": 61,
    },
    # ── French ───────────────────────────────────────────────────────────────
    "fr_FR-siwis-medium": {
        "path": "fr/fr_FR/siwis/medium",
        "lang": "fr",
        "gender": "female",
        "size_mb": 61,
    },
    "fr_FR-upmc-medium": {
        "path": "fr/fr_FR/upmc/medium",
        "lang": "fr",
        "gender": "female",
        "size_mb": 61,
    },
    # ── German ───────────────────────────────────────────────────────────────
    "de_DE-thorsten-medium": {
        "path": "de/de_DE/thorsten/medium",
        "lang": "de",
        "gender": "male",
        "size_mb": 61,
    },
    "de_DE-eva_k-x_low": {
        "path": "de/de_DE/eva_k/x_low",
        "lang": "de",
        "gender": "female",
        "size_mb": 14,
    },
    # ── Spanish ──────────────────────────────────────────────────────────────
    "es_ES-davefx-medium": {
        "path": "es/es_ES/davefx/medium",
        "lang": "es",
        "gender": "male",
        "size_mb": 61,
    },
    "es_MX-claude-high": {
        "path": "es/es_MX/claude/high",
        "lang": "es",
        "gender": "male",
        "size_mb": 110,
    },
}


def voice_urls(full_name: str):
    """Return (onnx_url, config_url) for a catalog voice."""
    meta = CATALOG[full_name]
    base = f"{VOICES_BASE_URL}/{meta['path']}/{full_name}"
    return f"{base}.onnx", f"{base}.onnx.json"


def known(full_name: str) -> bool:
    return full_name in CATALOG


def by_language(lang: str):
    return {k: v for k, v in CATALOG.items() if v["lang"] == lang}

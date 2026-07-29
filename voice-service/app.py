"""
DepthNet voice-service — OpenAI-compatible HTTP layer over faster-whisper and piper.

Endpoints are shaped after the OpenAI audio API on purpose. That way the PHP
side needs exactly one driver class (OpenAiCompatible{Stt,Tts}Provider) whose
base_url points here by default, at api.openai.com for cloud use, or at any
other compatible backend. Local-first is a configuration choice, not a
separate code path.

    POST /v1/audio/transcriptions   multipart: file, [language], [model]  -> JSON
    POST /v1/audio/speech           JSON: {input, voice, [speed], [response_format]} -> audio
    GET  /v1/voices                 non-standard: lists installed voices
    GET  /v1/models                 non-standard: reports the loaded STT model
    GET  /health                    readiness for docker healthcheck

Deliberately NOT implemented: authentication. This service binds to the
internal compose network only and is never published to the host. If that ever
changes, an API key check belongs here first.
"""

import io
import os
import logging
import subprocess
import tempfile
import threading
import wave
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, Field

import voices as voice_catalog

logging.basicConfig(
    level=logging.INFO,
    format="[voice-service] %(levelname)s %(message)s",
)
logger = logging.getLogger("voice-service")

MODELS_DIR = Path(os.getenv("MODELS_DIR", "/models"))
PIPER_DIR = MODELS_DIR / "piper"

STT_MODEL = os.getenv("STT_MODEL", "small")
STT_COMPUTE = os.getenv("STT_COMPUTE", "int8")
STT_BEAM_SIZE = int(os.getenv("STT_BEAM_SIZE", "5"))
DEFAULT_VOICE = os.getenv("TTS_VOICES", "en_US-lessac-medium").split(",")[0].strip()

# Guard against a runaway upload pinning the CPU for minutes.
MAX_AUDIO_MB = int(os.getenv("MAX_AUDIO_MB", "25"))
MAX_TEXT_CHARS = int(os.getenv("MAX_TEXT_CHARS", "5000"))

app = FastAPI(title="DepthNet voice-service", version="1.0.0")

# ─── Model loading ───────────────────────────────────────────────────────────
# faster-whisper is not thread-safe for concurrent transcribe() calls on one
# instance. uvicorn runs sync endpoints in a threadpool, so we serialize with a
# lock rather than risk interleaved decoding. Throughput is bounded by CPU here
# anyway — parallelism would not buy much.

_whisper = None
_whisper_lock = threading.Lock()
_piper_cache: dict = {}
_piper_lock = threading.Lock()


def get_whisper():
    global _whisper
    if _whisper is None:
        from faster_whisper import WhisperModel
        logger.info("loading STT model %s (%s)", STT_MODEL, STT_COMPUTE)
        _whisper = WhisperModel(STT_MODEL, device="cpu", compute_type=STT_COMPUTE)
        logger.info("STT model loaded")
    return _whisper


def get_piper(voice_name: str):
    """Load and cache a piper voice. Raises HTTPException if not installed."""
    with _piper_lock:
        if voice_name in _piper_cache:
            return _piper_cache[voice_name]

        onnx = PIPER_DIR / f"{voice_name}.onnx"
        if not onnx.exists():
            installed = sorted(p.stem for p in PIPER_DIR.glob("*.onnx"))
            raise HTTPException(
                status_code=404,
                detail=(
                    f"Voice '{voice_name}' is not installed. "
                    f"Installed: {installed or 'none'}. "
                    f"Add it to VOICE_TTS_VOICES in .env and restart."
                ),
            )

        from piper import PiperVoice
        logger.info("loading TTS voice %s", voice_name)
        v = PiperVoice.load(str(onnx))
        _piper_cache[voice_name] = v
        return v


@app.on_event("startup")
def warm_up():
    """
    Load the STT model at startup so /health only reports ready when it is.
    entrypoint.sh already downloaded it; this is the in-process load.
    """
    try:
        get_whisper()
    except Exception:
        logger.exception("STT warm-up failed")

    if DEFAULT_VOICE:
        try:
            get_piper(DEFAULT_VOICE)
        except Exception:
            logger.warning("TTS warm-up skipped for %s", DEFAULT_VOICE)


# ─── Audio decoding ──────────────────────────────────────────────────────────

def decode_to_wav(raw: bytes, suffix: str) -> str:
    """
    Normalize any browser/messenger audio to 16kHz mono wav via ffmpeg.

    Browsers send webm/opus, Telegram sends ogg/opus, uploads may be anything.
    Whisper wants 16kHz mono, and letting ffmpeg handle the conversion is far
    more robust than depending on the decoder's format guessing.

    Returns a path to a temp wav file; caller is responsible for unlinking it.
    """
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as src:
        src.write(raw)
        src_path = src.name

    dst_path = src_path + ".wav"
    try:
        subprocess.run(
            [
                "ffmpeg", "-nostdin", "-loglevel", "error", "-y",
                "-i", src_path,
                "-ar", "16000", "-ac", "1", "-c:a", "pcm_s16le",
                dst_path,
            ],
            check=True,
            timeout=120,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
    except subprocess.CalledProcessError as e:
        os.unlink(src_path)
        detail = e.stderr.decode("utf-8", "replace")[:400]
        raise HTTPException(status_code=400, detail=f"Could not decode audio: {detail}")
    except subprocess.TimeoutExpired:
        os.unlink(src_path)
        raise HTTPException(status_code=400, detail="Audio decoding timed out.")

    os.unlink(src_path)
    return dst_path


# ─── STT ─────────────────────────────────────────────────────────────────────

@app.post("/v1/audio/transcriptions")
def transcriptions(
    file: UploadFile = File(...),
    model: Optional[str] = Form(None),
    language: Optional[str] = Form(None),
    prompt: Optional[str] = Form(None),
    response_format: str = Form("json"),
    temperature: float = Form(0.0),
):
    """
    OpenAI-compatible transcription.

    `model` is accepted and ignored — this container serves whichever model it
    was configured with. Rejecting a mismatch would break drop-in compatibility
    with clients that always send "whisper-1".

    `language` matters: Whisper's autodetect is unreliable on short utterances
    ("да" / "da" / "ja" are acoustically close), so the caller should pass the
    preset's language explicitly. Omitting it falls back to autodetect.
    """
    raw = file.file.read()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty audio payload.")

    size_mb = len(raw) / (1024 * 1024)
    if size_mb > MAX_AUDIO_MB:
        raise HTTPException(
            status_code=413,
            detail=f"Audio is {size_mb:.1f} MB, limit is {MAX_AUDIO_MB} MB.",
        )

    suffix = Path(file.filename or "audio.webm").suffix or ".webm"
    wav_path = decode_to_wav(raw, suffix)

    try:
        with _whisper_lock:
            segments, info = get_whisper().transcribe(
                wav_path,
                language=language or None,
                beam_size=STT_BEAM_SIZE,
                temperature=temperature,
                initial_prompt=prompt or None,
                vad_filter=True,
            )
            collected = [
                {
                    "start": round(s.start, 3),
                    "end": round(s.end, 3),
                    "text": s.text.strip(),
                }
                for s in segments
            ]
    except HTTPException:
        raise
    except Exception:
        logger.exception("transcription failed")
        raise HTTPException(status_code=500, detail="Transcription failed.")
    finally:
        try:
            os.unlink(wav_path)
        except OSError:
            pass

    text = " ".join(s["text"] for s in collected).strip()

    if response_format == "text":
        return Response(content=text, media_type="text/plain; charset=utf-8")

    payload = {"text": text}
    if response_format == "verbose_json":
        payload.update({
            "language": info.language,
            "language_probability": round(info.language_probability, 4),
            "duration": round(info.duration, 3),
            "segments": collected,
        })
    return JSONResponse(payload)


# ─── TTS ─────────────────────────────────────────────────────────────────────

class SpeechRequest(BaseModel):
    input: str = Field(..., description="Text to synthesize")
    voice: Optional[str] = Field(None, description="Installed piper voice name")
    model: Optional[str] = Field(None, description="Accepted and ignored")
    response_format: str = Field("wav", description="wav | mp3 | opus")
    speed: float = Field(1.0, ge=0.25, le=4.0)


def wav_bytes(voice, text: str, speed: float) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as wf:
        # length_scale is inverse: >1 is slower. Callers think in "speed",
        # so invert here rather than leaking piper's convention outward.
        voice.synthesize(text, wf, length_scale=1.0 / speed)
    return buf.getvalue()


def transcode(data: bytes, fmt: str) -> tuple[bytes, str]:
    """Convert wav to mp3/opus via ffmpeg. Returns (bytes, mime)."""
    if fmt == "wav":
        return data, "audio/wav"

    spec = {
        "mp3": (["-c:a", "libmp3lame", "-b:a", "128k", "-f", "mp3"], "audio/mpeg"),
        "opus": (["-c:a", "libopus", "-b:a", "48k", "-f", "ogg"], "audio/ogg"),
    }
    if fmt not in spec:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported response_format '{fmt}'. Use wav, mp3 or opus.",
        )

    args, mime = spec[fmt]
    try:
        proc = subprocess.run(
            ["ffmpeg", "-nostdin", "-loglevel", "error", "-y", "-i", "pipe:0",
             *args, "pipe:1"],
            input=data, check=True, timeout=60,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
        logger.exception("transcode to %s failed", fmt)
        raise HTTPException(status_code=500, detail=f"Could not encode {fmt}.")

    return proc.stdout, mime


@app.post("/v1/audio/speech")
def speech(req: SpeechRequest):
    text = (req.input or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="Empty input text.")
    if len(text) > MAX_TEXT_CHARS:
        raise HTTPException(
            status_code=413,
            detail=f"Text is {len(text)} chars, limit is {MAX_TEXT_CHARS}.",
        )

    voice_name = (req.voice or DEFAULT_VOICE).strip()
    voice = get_piper(voice_name)

    try:
        data = wav_bytes(voice, text, req.speed)
    except Exception:
        logger.exception("synthesis failed")
        raise HTTPException(status_code=500, detail="Synthesis failed.")

    data, mime = transcode(data, req.response_format)
    return Response(content=data, media_type=mime)


# ─── Introspection ───────────────────────────────────────────────────────────

@app.get("/v1/voices")
def list_voices():
    """
    Non-standard endpoint backing the GUI voice picker.

    Reports only what is actually installed — the catalog is bigger, but
    offering a voice the container cannot serve would produce a 404 at the
    worst possible moment.
    """
    installed = []
    for onnx in sorted(PIPER_DIR.glob("*.onnx")):
        name = onnx.stem
        meta = voice_catalog.CATALOG.get(name, {})
        installed.append({
            "id": name,
            "title": name,
            "language": meta.get("lang", "unknown"),
            "gender": meta.get("gender", "unknown"),
        })
    return {"voices": installed, "default": DEFAULT_VOICE}


@app.get("/v1/models")
def list_models():
    return {
        "models": [{
            "id": STT_MODEL,
            "title": f"faster-whisper {STT_MODEL}",
            "description": f"local, compute_type={STT_COMPUTE}",
        }]
    }


@app.get("/health")
def health():
    """
    Readiness, not liveness. Reports unhealthy until the STT model is loaded,
    so the app container's first request doesn't hit a cold service.
    """
    stt_ready = _whisper is not None
    installed = [p.stem for p in PIPER_DIR.glob("*.onnx")]

    body = {
        "status": "ok" if stt_ready else "loading",
        "stt": {"ready": stt_ready, "model": STT_MODEL, "compute": STT_COMPUTE},
        "tts": {"voices": installed, "default": DEFAULT_VOICE},
    }
    return JSONResponse(body, status_code=200 if stt_ready else 503)

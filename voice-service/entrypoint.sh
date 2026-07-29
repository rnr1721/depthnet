#!/usr/bin/env bash
# =============================================================================
# voice-service entrypoint
#
# Downloads whatever is missing from /models, then hands off to uvicorn.
# Runs on every start; already-present models are skipped, so a restart after
# adding a voice to .env only fetches the new one.
#
# Fails loudly on an unknown voice name rather than starting a service that
# would 500 on first use.
# =============================================================================
set -euo pipefail

MODELS_DIR="${MODELS_DIR:-/models}"
PIPER_DIR="${MODELS_DIR}/piper"
PORT="${VOICE_SERVICE_PORT:-3002}"

STT_MODEL="${STT_MODEL:-small}"
STT_COMPUTE="${STT_COMPUTE:-int8}"
TTS_VOICES="${TTS_VOICES:-en_US-lessac-medium}"

log()  { echo "[voice-service] $*"; }
warn() { echo "[voice-service] WARNING: $*" >&2; }
die()  { echo "[voice-service] ERROR: $*" >&2; exit 1; }

mkdir -p "${PIPER_DIR}" "${MODELS_DIR}/hf" "${MODELS_DIR}/cache"

# ── TTS voices ───────────────────────────────────────────────────────────────
# Validate the whole list before downloading anything, so a typo in the third
# voice doesn't leave two half-configured downloads behind.
IFS=',' read -ra VOICE_LIST <<< "${TTS_VOICES}"

for raw in "${VOICE_LIST[@]}"; do
    voice="$(echo "${raw}" | xargs)"   # trim whitespace
    [ -z "${voice}" ] && continue

    if ! python3 -c "import sys; sys.path.insert(0,'/app'); import voices; sys.exit(0 if voices.known('${voice}') else 1)"; then
        die "Unknown TTS voice '${voice}'.
     Available voices are listed in voice-service/voices.py.
     Check TTS_VOICES / VOICE_TTS_VOICES in your .env file."
    fi
done

for raw in "${VOICE_LIST[@]}"; do
    voice="$(echo "${raw}" | xargs)"
    [ -z "${voice}" ] && continue

    onnx="${PIPER_DIR}/${voice}.onnx"
    conf="${PIPER_DIR}/${voice}.onnx.json"

    if [ -f "${onnx}" ] && [ -f "${conf}" ]; then
        log "voice '${voice}' already present, skipping"
        continue
    fi

    read -r onnx_url conf_url < <(
        python3 -c "import sys; sys.path.insert(0,'/app'); import voices; print(*voices.voice_urls('${voice}'))"
    )
    size=$(python3 -c "import sys; sys.path.insert(0,'/app'); import voices; print(voices.CATALOG['${voice}']['size_mb'])")

    log "downloading voice '${voice}' (~${size} MB)..."
    # Download to .part first — an interrupted download must not look complete
    # on the next start.
    curl -fL --retry 3 --retry-delay 2 -o "${onnx}.part" "${onnx_url}" \
        || die "failed to download ${voice}.onnx"
    curl -fL --retry 3 --retry-delay 2 -o "${conf}.part" "${conf_url}" \
        || die "failed to download ${voice}.onnx.json"
    mv "${onnx}.part" "${onnx}"
    mv "${conf}.part" "${conf}"
    log "voice '${voice}' ready"
done

# ── STT model ────────────────────────────────────────────────────────────────
# faster-whisper pulls from HuggingFace into HF_HOME (= /models/hf) on first
# construction. We warm it here rather than on first request, so the healthcheck
# genuinely reflects readiness.
STT_READY_MARK="${MODELS_DIR}/.stt-${STT_MODEL}-${STT_COMPUTE}.ok"

if [ -f "${STT_READY_MARK}" ]; then
    log "STT model '${STT_MODEL}' already prepared, skipping"
else
    log "preparing STT model '${STT_MODEL}' (compute=${STT_COMPUTE})..."
    log "first run downloads the model — this can take several minutes"

    if python3 - <<PYEOF
from faster_whisper import WhisperModel
WhisperModel("${STT_MODEL}", device="cpu", compute_type="${STT_COMPUTE}")
print("[voice-service] STT model ready")
PYEOF
    then
        touch "${STT_READY_MARK}"
    else
        warn "failed to prepare STT model '${STT_MODEL}'."
        warn "Sleeping 60s before exit to avoid a restart loop — check the traceback above."
        sleep 60
        exit 1
    fi
fi

log "starting HTTP layer on port ${PORT}"
exec uvicorn app:app --host 0.0.0.0 --port "${PORT}" --log-level info

# =============================================================================
# DepthNet voice-service
#
# Local STT (faster-whisper) + TTS (piper) behind a thin OpenAI-compatible HTTP
# layer. Models are NOT baked into the image — they are downloaded into the
# /models volume on first start by entrypoint.sh, so changing the model set is
# a `make restart`, not a rebuild.
#
# Image stays ~700-900MB. Models add 200MB-2GB depending on configuration.
# =============================================================================
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HOME=/models/hf \
    XDG_CACHE_HOME=/models/cache

# ffmpeg  — decodes whatever the browser sends (webm/opus, ogg, m4a) to wav
# curl    — model downloads in entrypoint
# espeak-ng + libsndfile — piper runtime dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        curl \
        ca-certificates \
        espeak-ng \
        libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY app.py /app/app.py
COPY voices.py /app/voices.py
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Models live in a named volume so they survive rebuilds.
VOLUME ["/models"]

EXPOSE 3002

ENTRYPOINT ["/entrypoint.sh"]

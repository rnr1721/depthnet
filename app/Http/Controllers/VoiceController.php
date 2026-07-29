<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\Capabilities\SttServiceInterface;
use App\Contracts\Agent\Capabilities\TtsServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Voice endpoints for the chat UI.
 *
 * Three actions, one per thing the frontend cannot do alone:
 *
 *   config()     — what the preset's providers want the browser to know
 *   speak()      — server-side synthesis, for presets whose TTS runs on the backend
 *   transcribe() — server-side recognition, same idea in reverse
 *
 * speak() and transcribe() are NOT used when the preset uses browser providers —
 * in that case the browser does the work itself and never calls these. The
 * frontend decides based on execution_mode from config().
 */
class VoiceController extends Controller
{
    public function __construct(
        protected SttServiceInterface $sttService,
        protected TtsServiceInterface $ttsService,
        protected PresetServiceInterface $presetService,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Voice configuration for a preset — safe to expose to the browser.
     *
     * Always returns 200 with a usable shape, even when nothing is configured:
     * the chat UI asks for this on every preset switch, and an error response
     * for "voice is simply off" would be noise.
     */
    public function config(Request $request): JsonResponse
    {
        $preset = $this->resolvePreset($request->query('preset_id'));

        if ($preset === null) {
            return response()->json(['stt' => null, 'tts' => null]);
        }

        return response()->json([
            'preset_id' => $preset->getId(),
            'stt' => $this->sttService->isAvailable($preset)
                ? $this->sttService->getClientConfig($preset)
                : null,
            'tts' => $this->ttsService->isAvailable($preset)
                ? $this->ttsService->getClientConfig($preset)
                : null,
        ]);
    }

    /**
     * Synthesize speech on the server and stream the audio back.
     *
     * The response is raw audio, not JSON — the browser feeds it straight into
     * an <audio> element via a blob URL, so base64 in a JSON envelope would only
     * add a third of the payload for nothing.
     */
    public function speak(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'text'      => ['required', 'string', 'max:10000'],
            'preset_id' => ['nullable', 'integer'],
            'voice'     => ['nullable', 'string', 'max:120'],
            'speed'     => ['nullable', 'numeric', 'between:0.25,4.0'],
        ]);

        $preset = $this->resolvePreset($validated['preset_id'] ?? null);

        if ($preset === null) {
            return response()->json(['message' => 'Preset not found.'], 404);
        }

        $result = $this->ttsService->synthesizeResult(
            $validated['text'],
            $preset,
            $validated['voice'] ?? null,
            (float) ($validated['speed'] ?? 1.0),
        );

        if (!$result->success) {
            return response()->json(['message' => $result->error], 422);
        }

        return response($result->audio->getBytes(), 200, [
            'Content-Type'  => $result->audio->getMimeType(),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Transcribe an uploaded recording.
     *
     * Used when the preset's STT provider runs server-side: the browser records
     * with MediaRecorder and posts the blob here, instead of using the Web
     * Speech API.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio'     => ['required', 'file', 'max:25600'], // KB → 25 MB
            'preset_id' => ['nullable', 'integer'],
            'language'  => ['nullable', 'string', 'max:10'],
        ]);

        $preset = $this->resolvePreset($validated['preset_id'] ?? null);

        if ($preset === null) {
            return response()->json(['message' => 'Preset not found.'], 404);
        }

        $file = $request->file('audio');

        $audio = AudioData::fromBinary(
            (string) file_get_contents($file->getRealPath()),
            $file->getMimeType() ?: 'audio/webm',
            'browser',
        );

        $result = $this->sttService->transcribeResult(
            $audio,
            $preset,
            $validated['language'] ?? null,
        );

        if (!$result->success) {
            return response()->json(['message' => $result->error], 422);
        }

        return response()->json([
            'text'     => $result->text,
            'language' => $result->language,
            'duration' => $result->durationSec,
        ]);
    }

    // -------------------------------------------------------------------------

    private function resolvePreset(mixed $presetId): mixed
    {
        try {
            return $presetId
                ? $this->presetService->findById((int) $presetId)
                : $this->presetService->getDefaultPreset();
        } catch (\Throwable $e) {
            $this->logger->debug('VoiceController: preset lookup failed — ' . $e->getMessage());
            return null;
        }
    }
}

<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Contract for speech-to-text capability providers.
 *
 * NOTE the deliberate omission: there is no transcribe() here.
 *
 * Unlike vision or embedding, STT providers are not all the same kind of thing.
 * A server provider (faster-whisper, Whisper API) is a stateless request/response
 * call. The browser provider is a long-lived client-side session with a state
 * machine, wake word matching and echo suppression — it cannot transcribe on the
 * backend at all, and pretending otherwise would produce a contract half the
 * implementations lie about.
 *
 * So this interface carries only what EVERY provider can honour: identity,
 * config, and where it runs. Actual transcription lives in the optional
 * TranscribesAudioInterface, detected via instanceof — the same pattern already
 * used for ListsModelsInterface.
 *
 * @see TranscribesAudioInterface
 */
interface SttProviderInterface extends CapabilityProviderInterface
{
    public const CAPABILITY = 'stt';

    /** Runs on the backend — implements TranscribesAudioInterface. */
    public const MODE_SERVER = 'server';

    /** Runs in the user's browser — backend cannot invoke it. */
    public const MODE_CLIENT = 'client';

    /**
     * Where transcription actually happens: MODE_SERVER or MODE_CLIENT.
     *
     * The frontend uses this to decide whether to run its own recognizer;
     * SttService uses it to produce a clear error instead of a type error when
     * a client-side provider is asked to transcribe on the backend.
     */
    public function getExecutionMode(): string;

    /**
     * Config safe to expose to the browser.
     *
     * MUST NOT contain API keys or any other secret — this is serialized into
     * Inertia props and is visible in page source. Server providers typically
     * return only wake-word and UX settings; the browser provider returns
     * everything it needs to run.
     *
     * @return array<string, mixed>
     */
    public function getClientConfig(): array;
}

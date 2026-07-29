<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Contract for text-to-speech capability providers.
 *
 * Mirrors SttProviderInterface: the base contract covers identity, config and
 * execution mode; synthesis itself lives in the optional
 * SynthesizesSpeechInterface.
 *
 * The browser provider is the reason for the split. It produces no audio on the
 * backend — the client speaks through the Web Speech API using voices installed
 * on the user's machine, which the server cannot enumerate, let alone render.
 *
 * @see SynthesizesSpeechInterface
 */
interface TtsProviderInterface extends CapabilityProviderInterface
{
    public const CAPABILITY = 'tts';

    public const MODE_SERVER = 'server';
    public const MODE_CLIENT = 'client';

    /**
     * MODE_SERVER or MODE_CLIENT.
     */
    public function getExecutionMode(): string;

    /**
     * Config safe to expose to the browser. MUST NOT contain secrets.
     *
     * @return array<string, mixed>
     */
    public function getClientConfig(): array;
}

<?php

namespace App\Services\Agent\Capabilities\Speech;

use App\Contracts\Agent\Capabilities\SynthesizesSpeechInterface;
use App\Contracts\Agent\Capabilities\TtsProviderInterface;
use App\Contracts\Agent\Capabilities\TtsServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Speech\DTO\TtsResult;
use Psr\Log\LoggerInterface;

/**
 * High-level text-to-speech service.
 *
 * Mirrors SttService, including the client/server guard.
 */
class TtsService implements TtsServiceInterface
{
    /**
     * Providers charge per character and choke on very long inputs. Callers that
     * legitimately need more should chunk by sentence and call repeatedly.
     */
    private const MAX_TEXT_CHARS = 5000;

    public function __construct(
        protected TtsRegistry $registry,
        protected LoggerInterface $logger,
    ) {
    }

    public function synthesizeResult(
        string $text,
        AiPreset $preset,
        ?string $voice = null,
        float $speed = 1.0,
    ): TtsResult {
        $clean = $this->prepareText($text);

        if ($clean === '') {
            return TtsResult::fail('Nothing to speak — the message is empty once markup is removed.');
        }

        if (mb_strlen($clean) > self::MAX_TEXT_CHARS) {
            $len = mb_strlen($clean);
            return TtsResult::fail(
                "Text is {$len} characters, which exceeds the " . self::MAX_TEXT_CHARS . " limit."
            );
        }

        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return TtsResult::fail(
                'Speech synthesis is not configured or not active for this preset.'
            );
        }

        if (!$provider instanceof SynthesizesSpeechInterface) {
            return TtsResult::fail(sprintf(
                "The '%s' speech provider speaks in the browser and produces no audio on "
                . "the server. Configure a server-side TTS driver for this preset to send "
                . "voice to other channels.",
                $provider->getDisplayName()
            ));
        }

        try {
            $result = $provider->synthesize($clean, $voice, $speed);
        } catch (\Throwable $e) {
            $this->logger->error('TtsService: provider threw — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
            return TtsResult::fail('Speech synthesis error: ' . $e->getMessage());
        }

        if (!$result->success) {
            $this->logger->warning('TtsService: synthesis failed — ' . $result->error, [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
        }

        return $result;
    }

    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    public function isServerSideAvailable(AiPreset $preset): bool
    {
        return $this->resolveProvider($preset) instanceof SynthesizesSpeechInterface;
    }

    public function getClientConfig(AiPreset $preset): ?array
    {
        return $this->resolveProvider($preset)?->getClientConfig();
    }

    /**
     * Strip everything an agent message carries that should not be spoken.
     *
     * PHP counterpart of cleanTextForSpeech() in useVoice.js. Order matters and
     * matches the JS: tool output is cut first (everything after the marker is
     * machine output), then command tags with their bodies, then bare tags, then
     * markdown.
     */
    public function prepareText(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $text = $raw;

        // Everything after the last results marker is tool output, not speech.
        $marker = '<system_output_results>';
        $pos = mb_strrpos($text, $marker);
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // tool_calls JSON block, if the engine inlined one.
        $text = preg_replace('/\{"tool_calls".*?\}\s*$/s', '', $text) ?? $text;

        // Paired command tags with their contents: [memory]...[/memory]
        $text = preg_replace(
            '/\[([a-z][a-z0-9_]*)(?:\s+[a-z][a-z0-9_]*)?\][\s\S]*?\[\/\1(?:\s+[a-z][a-z0-9_]*)?\]/i',
            '',
            $text
        ) ?? $text;

        // Unclosed / bare tags.
        $text = preg_replace('/\[[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_]*)?\]/i', '', $text) ?? $text;

        // Photo and file blocks — their contents are descriptions for the agent,
        // not something to read aloud.
        $text = preg_replace('/```(?:photo|file)\n[\s\S]*?```/', ' ', $text) ?? $text;

        // Code blocks become a single spoken word rather than being read out.
        $text = preg_replace('/```[\s\S]*?```/', ' code block ', $text) ?? $text;
        $text = preg_replace('/`[^`]+`/', '', $text) ?? $text;

        // HTML/XML tags.
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;

        // Markdown decorations.
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;

        // HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function activeConfig(AiPreset $preset): ?PresetCapabilityConfig
    {
        return PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability(TtsProviderInterface::CAPABILITY)
            ->active()
            ->first();
    }

    private function resolveProvider(AiPreset $preset): ?TtsProviderInterface
    {
        try {
            /** @var TtsProviderInterface */
            return $this->registry->makeForPreset($preset);
        } catch (\Throwable $e) {
            $this->logger->debug('TtsService: provider unavailable — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return null;
        }
    }
}

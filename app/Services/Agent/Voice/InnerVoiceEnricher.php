<?php

namespace App\Services\Agent\Voice;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Voice\InnerVoiceEnricherInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * InnerVoiceEnricher
 *
 * Injects a "inner voice" into the main agent's context before each
 * thinking cycle via the [[inner_voice]] placeholder in system_prompt.
 *
 * Unlike RAG, there is no vector search involved — the voice preset's
 * engine simply receives the recent conversation and responds directly.
 * The character of the voice is defined entirely by the voice preset's
 * own system_prompt:
 *
 *   - Advisor:      "You are the agent's inner advisor. Give one short
 *                    practical suggestion based on the conversation."
 *   - Conscience:   "You are the agent's conscience. Raise one ethical
 *                    concern or doubt if you sense one. Be brief."
 *   - Subconscious: "You are the agent's subconscious. Surface one
 *                    hidden pattern or intuition from the conversation."
 *   - Muse:         "You are the agent's creative muse. Offer one
 *                    unexpected angle or idea. One sentence only."
 *
 * The voice preset can use any engine — a cheap fast model works well
 * since the output is short by design.
 */
class InnerVoiceEnricher implements InnerVoiceEnricherInterface
{
    /**
     * Enable verbose debug logging.
     * Set to true temporarily when diagnosing issues.
     */
    private bool $debug = false;

    public function __construct(
        protected PresetServiceInterface             $presetService,
        protected PresetRegistryInterface            $presetRegistry,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected LoggerInterface                    $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function enrich(AiPreset $preset, array $context): ?string
    {
        try {
            if (!$preset->hasVoice()) {
                $this->debugLog('skipped — voice_preset_id not set', ['preset_id' => $preset->getId()]);
                return null;
            }

            $voicePreset = $this->presetService->findById($preset->voice_preset_id);

            if (!$voicePreset) {
                $this->logger->warning('InnerVoice: preset not found', [
                    'voice_preset_id' => $preset->voice_preset_id,
                ]);
                return null;
            }

            if (!$voicePreset->isActive()) {
                $this->logger->warning('InnerVoice: preset inactive', [
                    'voice_preset_id' => $preset->voice_preset_id,
                ]);
                return null;
            }

            $voice = $this->callVoice($voicePreset, $context);

            $this->logger->debug('InnerVoice enrichment', [
                'voice_preset' => $voicePreset->getName(),
                'length'       => $voice ? mb_strlen($voice) : 0,
            ]);

            return $voice;

        } catch (\Throwable $e) {
            // Voice errors must never crash the main agent cycle
            $this->logger->error('InnerVoiceEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id'  => $preset->getId(),
                'voice_preset_id' => $preset->voice_preset_id,
                'trace'           => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Call the voice preset's engine with recent conversation context
     * and return its raw response as the voice content.
     */
    protected function callVoice(AiPreset $voicePreset, array $context): ?string
    {
        try {
            $recentMessages = collect($context)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command']))
                ->values()
                ->slice(-4)  // a bit more context than RAG — voice benefits from more flow
                ->values();

            $this->debugLog('recent messages for voice', ['count' => $recentMessages->count()]);

            if ($recentMessages->isEmpty()) {
                return null;
            }

            $conversationText = $recentMessages
                ->map(fn ($m) => strtoupper($m['role']) . ': ' . mb_substr($m['content'] ?? '', 0, 500))
                ->implode("\n");

            $engine = $this->presetRegistry->createInstance($voicePreset->getId());

            $dto = new ModelRequestDTO(
                preset:                    $voicePreset,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   [
                    ['role' => 'user', 'content' => $conversationText],
                ],
            );

            $response = $engine->generate($dto);

            $this->debugLog('voice response', [
                'is_error' => $response->isError(),
                'response' => $response->getResponse(),
            ]);

            if ($response->isError()) {
                $this->logger->warning('InnerVoice: voice call failed', [
                    'voice_preset' => $voicePreset->getName(),
                    'error'        => $response->getResponse(),
                ]);
                return null;
            }

            $voice = trim(strip_tags($response->getResponse()));

            if (empty($voice)) {
                return null;
            }

            return $this->formatVoice($voice, $voicePreset->getName());

        } catch (\Throwable $e) {
            $this->logger->error('InnerVoiceEnricher::callVoice error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Wrap the voice response in a recognizable block.
     */
    protected function formatVoice(string $voice, string $presetName): string
    {
        return implode("\n", [
            "[INNER VOICE — {$presetName}]",
            '',
            $voice,
            '',
            '[END INNER VOICE]',
        ]);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('InnerVoice: ' . $message, $context);
        }
    }
}

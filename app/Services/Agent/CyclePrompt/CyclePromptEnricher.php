<?php

namespace App\Services\Agent\CyclePrompt;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CyclePrompt\CyclePromptEnricherInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * CyclePromptEnricher
 *
 * Generates a dynamic cycle continuation prompt using a dedicated preset
 * instead of the static "[Continue your thinking cycle]" instruction.
 *
 * The cycle prompt preset receives recent conversation context and produces
 * a message that is injected as the user turn kicking off the next cycle.
 *
 * This breaks the resonance loop that autonomous agents tend to fall into
 * after several identical thinking cycles. The prompt preset can act as:
 *
 *   - Critic:      "You are a harsh critic. Point out one flaw or
 *                   contradiction in the agent's last thought. Be direct."
 *   - Motivator:   "You are an energetic coach. Push the agent to take one
 *                   concrete action it has been avoiding. Be brief."
 *   - Provocateur: "You are a devil's advocate. Challenge the agent's last
 *                   conclusion with a sharp counter-argument. One sentence."
 *   - Questioner:  "You ask one deep, uncomfortable question the agent
 *                   hasn't considered yet. Nothing else."
 *
 * The cycle prompt preset can use any engine — a cheap fast model works
 * well since the output replaces a short static string.
 */
class CyclePromptEnricher implements CyclePromptEnricherInterface
{
    /**
     * Enable verbose debug logging.
     * Set to true temporarily when diagnosing issues.
     */
    private bool $debug = true;

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
            if (!$preset->hasCyclePrompt()) {
                $this->debugLog('skipped — cycle_prompt_preset_id not set', [
                    'preset_id' => $preset->getId(),
                ]);
                return null;
            }

            $cyclePreset = $this->presetService->findById($preset->cycle_prompt_preset_id);

            if (!$cyclePreset) {
                $this->logger->warning('CyclePrompt: preset not found', [
                    'cycle_prompt_preset_id' => $preset->cycle_prompt_preset_id,
                ]);
                return null;
            }

            if (!$cyclePreset->isActive()) {
                $this->logger->warning('CyclePrompt: preset inactive', [
                    'cycle_prompt_preset_id' => $preset->cycle_prompt_preset_id,
                ]);
                return null;
            }

            $prompt = $this->callCyclePromptPreset($cyclePreset, $context);

            $this->logger->debug('CyclePrompt enrichment', [
                'cycle_preset' => $cyclePreset->getName(),
                'length'       => $prompt ? mb_strlen($prompt) : 0,
            ]);

            return $prompt;

        } catch (\Throwable $e) {
            // Errors must never crash the main agent cycle — fall back to static instruction
            $this->logger->error('CyclePromptEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id'         => $preset->getId(),
                'cycle_prompt_preset_id' => $preset->cycle_prompt_preset_id,
                'trace'                  => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Call the cycle prompt preset engine with recent conversation context.
     *
     * @param AiPreset $cyclePreset
     * @param array $context
     * @return string|null
     */
    protected function callCyclePromptPreset(AiPreset $cyclePreset, array $context): ?string
    {
        try {
            $recentMessages = collect($context)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command']))
                ->values()
                ->slice(-6) // a bit more context so the prompter can react meaningfully
                ->values();

            $this->debugLog('recent messages for cycle prompt', [
                'count' => $recentMessages->count(),
            ]);

            if ($recentMessages->isEmpty()) {
                return null;
            }

            $conversationText = $recentMessages
                ->map(fn ($m) => strtoupper($m['role']) . ': ' . mb_substr($m['content'] ?? '', 0, 500))
                ->implode("\n");

            $engine = $this->presetRegistry->createInstance($cyclePreset->getId());

            $dto = new ModelRequestDTO(
                preset:                    $cyclePreset,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   [
                    ['role' => 'user', 'content' => $conversationText],
                ],
            );

            $response = $engine->generate($dto);

            $this->debugLog('cycle prompt response', [
                'is_error' => $response->isError(),
                'response' => $response->getResponse(),
            ]);

            if ($response->isError()) {
                $this->logger->warning('CyclePrompt: generation failed', [
                    'cycle_preset' => $cyclePreset->getName(),
                    'error'        => $response->getResponse(),
                ]);
                return null;
            }

            $prompt = trim(strip_tags($response->getResponse()));

            return empty($prompt) ? null : $prompt;

        } catch (\Throwable $e) {
            $this->logger->error('CyclePromptEnricher::callCyclePromptPreset error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Log for debug if turn on
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('CyclePrompt: ' . $message, $context);
        }
    }
}

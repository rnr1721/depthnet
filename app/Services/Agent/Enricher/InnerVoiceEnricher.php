<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Enricher\InnerVoiceEnricherInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Models\PresetInnerVoiceConfig;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * InnerVoiceEnricher
 *
 * Executes a single inner voice preset and returns its response as a
 * formatted string ready for injection into [[inner_voice]].
 *
 * This class handles one config at a time. The pipeline loop is owned
 * by CycleContextBuilder / SingleContextBuilder, which iterate over all
 * enabled PresetInnerVoiceConfigs ordered by sort_order, call this enricher
 * for each, and concatenate the results into a single [[inner_voice]] block.
 *
 * Example voices:
 *   - Physical model:  builds a physics-inspired representation of the context
 *   - Angel / Demon:   surfaces moral tension or counterarguments
 *   - Noise:           injects creative entropy to break local minima
 *   - Logic layer:     decomposes the situation into structured reasoning
 *
 * Each voice preset defines its own character entirely via its system_prompt.
 * The enricher is character-agnostic — it only handles invocation and formatting.
 *
 * tool_calls mode is not supported for voice presets (synthetic flat context).
 */
class InnerVoiceEnricher implements InnerVoiceEnricherInterface
{
    public function __construct(
        protected PresetServiceInterface             $presetService,
        protected PresetRegistryInterface            $presetRegistry,
        protected MemoryServiceInterface             $memoryService,
        protected CommandResultPoolInterface         $commandResultPool,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginRegistryInterface            $pluginRegistry,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected AgentActionsHandlerInterface       $agentActionsHandler,
        protected ContextBuilderFactoryInterface     $contextBuilderFactory,
        protected CommandPreRunnerInterface          $commandPreRunner,
        protected Message                            $messageModel,
        protected LoggerInterface                    $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function enrich(AiPreset $mainPreset, array $context, PresetInnerVoiceConfig $config): ?string
    {
        try {
            $voicePreset = $this->presetService->findById($config->voice_preset_id);

            if (!$voicePreset) {
                $this->logger->warning('InnerVoiceEnricher: voice preset not found', [
                    'voice_preset_id' => $config->voice_preset_id,
                    'config_id'       => $config->id,
                ]);
                return null;
            }

            if (!$voicePreset->isActive()) {
                $this->logger->warning('InnerVoiceEnricher: voice preset inactive', [
                    'voice_preset' => $voicePreset->getName(),
                    'config_id'    => $config->id,
                ]);
                return null;
            }

            $response = $this->callVoice($voicePreset, $mainPreset, $config);

            if ($response === null) {
                return null;
            }

            return $this->formatBlock($response, $voicePreset, $config);

        } catch (\Throwable $e) {
            // Voice errors must never crash the main agent cycle
            $this->logger->error('InnerVoiceEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id'  => $mainPreset->getId(),
                'config_id'       => $config->id,
                'trace'           => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Call the voice preset engine and return the raw response string.
     *
     * @param  AiPreset                $voicePreset
     * @param  AiPreset                $mainPreset
     * @param  PresetInnerVoiceConfig  $config
     * @return string|null
     */
    private function callVoice(AiPreset $voicePreset, AiPreset $mainPreset, PresetInnerVoiceConfig $config): ?string
    {
        $contextLimit = max(1, $config->context_limit);

        try {
            $this->pluginRegistry->applyPreset($voicePreset);

            if ($voicePreset->getAgentResultMode() === 'internal') {
                $this->shortcodeManagerService->registerShortcodeForPreset(
                    $voicePreset->getId(),
                    'agent_command_results',
                    '',
                    fn () => $this->commandResultPool->getFormatted($voicePreset)
                );
            }

            $preResults = $this->commandPreRunner->run($voicePreset, $voicePreset, $mainPreset);

            $contextBuilder = $this->contextBuilderFactory->getContextBuilder('single');
            $builtContext   = $contextBuilder->build($mainPreset, $voicePreset, $contextLimit);

            // Forward main preset's [[rag_context]] into voice preset scope.
            // SingleContextBuilder already registered [[rag_context]] for the voice preset's
            // own ragConfigs. Here we additionally expose the main preset's RAG result
            // under [[main_rag_context]] so voice prompts can reference either independently.
            $this->shortcodeManagerService->registerShortcodeForPreset(
                $voicePreset->getId(),
                'main_rag_context',
                'RAG context from the main preset',
                fn () => $this->shortcodeManagerService->getShortcodeValue('rag_context', $mainPreset->getId())
            );

            $conversationText = collect($builtContext)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command'], true))
                ->map(fn ($m) => strtoupper($m['role']) . ': ' . mb_substr($m['content'] ?? '', 0, 500))
                ->implode("\n");

            $flatContext = [];

            if (!empty($preResults)) {
                $flatContext[] = ['role' => 'user', 'content' => $preResults, 'from_user_id' => null];
            }

            if (!empty($conversationText)) {
                $flatContext[] = [
                    'role'         => 'user',
                    'content'      => "=== CONVERSATION TO ANALYZE ===\n{$conversationText}\n=== END ===",
                    'from_user_id' => null,
                ];
            }

            if (empty($flatContext)) {
                $flatContext[] = ['role' => 'user', 'content' => 'No recent conversation available.', 'from_user_id' => null];
            }

            $engine   = $this->presetRegistry->createInstance($voicePreset->getId());
            $response = $engine->generate(new ModelRequestDTO(
                preset:                    $voicePreset,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   $flatContext
            ));

            if ($response->isError()) {
                $this->logger->warning('InnerVoiceEnricher: voice call failed', [
                    'voice_preset' => $voicePreset->getName(),
                    'error'        => $response->getResponse(),
                ]);
                return null;
            }

            $voice = trim(strip_tags($response->getResponse()));

            if (!$voice) {
                return null;
            }

            if ($voicePreset->getAgentResultMode() === 'tool_calls') {
                // tool_calls presets receive a synthetic flat context with no tools array,
                // so the model responds with plain text — tool execution is not possible here.
                // Write a visible system notice to the main preset's history.
                $this->messageModel->create([
                    'role'               => 'system',
                    'content'            => "⚠️ Inner voice [{$voicePreset->getName()}] is configured as tool_calls but runs in a flat synthetic context — tools cannot be executed. Switch agent_result_mode to 'separate' or 'internal' for this voice preset.",
                    'from_user_id'       => null,
                    'preset_id'          => $mainPreset->getId(),
                    'is_visible_to_user' => true,
                ]);
                return $voice ?: null;
            }

            $actionResult = $this->agentActionsHandler->handleResponse($response, $voicePreset, $mainPreset);

            return $actionResult->hasCommands()
                ? ($actionResult->getSystemMessage() ?? '')
                : $voice;

        } catch (\Throwable $e) {
            $this->logger->error('InnerVoiceEnricher::callVoice error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        } finally {
            $this->pluginRegistry->applyPreset($mainPreset);
        }
    }

    /**
     * Wrap the voice response in a labeled block for [[inner_voice]] injection.
     *
     * Output example:
     *   [Physical Model]
     *   The tension vector between last two thoughts suggests oscillation...
     *   [END Physical Model]
     *
     * Uses config->label if set, otherwise falls back to voicePreset->getName().
     *
     * @param  string                  $response
     * @param  AiPreset                $voicePreset
     * @param  PresetInnerVoiceConfig  $config
     * @return string
     */
    private function formatBlock(string $response, AiPreset $voicePreset, PresetInnerVoiceConfig $config): string
    {
        $label = !empty($config->label) ? $config->label : $voicePreset->getName();
        $clean = trim($response);

        return "[{$label}]\n{$clean}\n[END {$label}]";
    }
}

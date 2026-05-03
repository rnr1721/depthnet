<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Enricher\CyclePromptEnricherInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * CyclePromptEnricher
 *
 * Anti-loop mechanism for autonomous cycle mode.
 *
 * Calls a dedicated "cycle prompt" preset whose sole job is to inject
 * an impulse — critique, encouragement, redirection, or noise — into
 * the main agent's input pool before each new cycle. This prevents the
 * model from looping on the same thought.
 *
 * Unlike InnerVoiceEnricher, this enricher:
 *   - Is only used in CycleContextBuilder (not in single mode)
 *   - Writes its output to InputPoolService, not to [[inner_voice]]
 *   - Has a single configured preset (cycle_prompt_preset_id on AiPreset)
 *   - Has no pipeline — one preset, one call, done
 *
 * The cycle prompt preset's character is defined by its own system_prompt:
 *   "You are the agent's inner critic. In one short sentence, challenge
 *    the last thought the agent expressed. Be direct."
 */
class CyclePromptEnricher implements CyclePromptEnricherInterface
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
    public function enrich(AiPreset $preset, array $context): ?string
    {
        try {
            $voicePreset = $this->getVoicePreset($preset);

            if (!$voicePreset) {
                return null;
            }

            if (!$voicePreset->isActive()) {
                $this->logger->warning('CyclePromptEnricher: preset inactive', [
                    'voice_preset_id' => $preset->cycle_prompt_preset_id,
                ]);
                return null;
            }

            return $this->callVoice($voicePreset, $preset, $context);

        } catch (\Throwable $e) {
            $this->logger->error('CyclePromptEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id' => $preset->getId(),
                'trace'          => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getVoicePreset(AiPreset $preset): ?AiPreset
    {
        $presetId = $preset->cycle_prompt_preset_id;

        if (!$presetId) {
            return null;
        }

        return $this->presetService->findById($presetId);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Call the cycle prompt preset and return its raw response.
     */
    private function callVoice(AiPreset $voicePreset, AiPreset $mainPreset, array $context): ?string
    {
        $contextLimit = max(1, $mainPreset->getCpContextLimit());

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
                $this->logger->warning('CyclePromptEnricher: voice call failed', [
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
                $this->messageModel->create([
                    'role'               => 'system',
                    'content'            => "⚠️ Cycle prompt [{$voicePreset->getName()}] is configured as tool_calls but runs in a flat synthetic context — tools cannot be executed. Switch agent_result_mode to 'separate' or 'internal' for this voice preset.",
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
            $this->logger->error('CyclePromptEnricher::callVoice error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        } finally {
            $this->pluginRegistry->applyPreset($mainPreset);
        }
    }
}

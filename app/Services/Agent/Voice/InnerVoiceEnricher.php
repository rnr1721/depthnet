<?php

namespace App\Services\Agent\Voice;

use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Voice\InnerVoiceEnricherInterface;
use App\Contracts\Agent\Voice\InnerVoiceResponseInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Voice\DTO\InnerVoiceDTO;
use Nette\InvalidArgumentException;
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

    private const ALLOWED_TARGETS = [
        'single' => [
            'field' => 'voice_preset_id',
            'check_method' => 'hasVoice',
            'context_limit_method' => 'getVoiceContextLimit'
        ],
        'cycle' => [
            'field' => 'cycle_prompt_preset_id',
            'check_method' => 'hasCyclePrompt',
            'context_limit_method' => 'getCpContextLimit'
        ]
    ];

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
        protected LoggerInterface                    $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function enrich(AiPreset $preset, array $context, string $target): InnerVoiceResponseInterface
    {
        try {

            if (!isset(self::ALLOWED_TARGETS[$target])) {
                throw new InvalidArgumentException('Invalid internal voice target');
            }

            $dbField = $this->getDbField($target);

            if (!$this->hasVoice($preset, $target)) {
                $this->debugLog('skipped — '.$dbField.' not set', ['preset_id' => $preset->getId()]);
                return $this->generateEmptyResponse($preset);
            }

            $voicePreset = $this->getVoicePreset($preset, $target);

            if (!$voicePreset) {
                $this->logger->warning('InnerVoice: preset not found (' . $dbField . ')', [
                    $dbField => $preset->$dbField,
                ]);
                return $this->generateEmptyResponse($preset);
            }

            if (!$voicePreset->isActive()) {
                $this->logger->warning('InnerVoice: preset inactive ('.$dbField . ')', [
                    $dbField => $preset->$dbField,
                ]);
                return $this->generateEmptyResponse($preset, $voicePreset);
            }

            $voice = $this->callVoice($voicePreset, $preset, $context, $target);

            $this->logger->debug('InnerVoice enrichment (' . $dbField . ')', [
                'voice_preset' => $voicePreset->getName(),
                'length'       => mb_strlen($voice ?? ''),
            ]);

            return new InnerVoiceDTO($preset, $voicePreset, $voice);

        } catch (\Throwable $e) {
            // Voice errors must never crash the main agent cycle
            $this->logger->error('InnerVoiceEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id'  => $preset->getId(),
                'trace'           => $e->getTraceAsString(),
            ]);
            return $this->generateEmptyResponse($preset);
        }
    }

    /**
     * @inheritDoc
     */
    public function getVoicePreset(AiPreset $mainPreset, string $target): ?AiPreset
    {
        $dbField = $this->getDbField($target);
        return $this->presetService->findById($mainPreset->$dbField);
    }

    /**
     * Get Database preset field for voice preset
     *
     * @param string $target
     * @return string
     */
    protected function getDbField(string $target): string
    {
        return self::ALLOWED_TARGETS[$target]['field'];
    }

    /**
     * Call the voice preset's engine with recent conversation context
     * and return its raw response as the voice content.
     *
     * @param AiPreset $voicePreset Voice preset
     * @param AiPreset $mainPreset Main (source) preset
     * @param array $context
     * @param string $target
     * @return string|null
     */
    protected function callVoice(AiPreset $voicePreset, AiPreset $mainPreset, array $context, string $target): ?string
    {
        $contextLimit = $this->getPresetLimit($mainPreset, $target);
        try {
            $recentMessages = collect($context)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command'], true))
                ->slice(-$contextLimit)
                ->values();

            $this->debugLog('recent messages for voice', ['count' => $recentMessages->count()]);

            if ($recentMessages->isEmpty()) {
                return null;
            }

            $conversationText = $recentMessages
                ->map(fn ($m) => strtoupper($m['role']) . ': ' . mb_substr($m['content'] ?? '', 0, 500))
                ->implode("\n");

            if ($voicePreset->getAgentResultMode() === 'internal') {
                $this->shortcodeManagerService->registerShortcodeForPreset(
                    $voicePreset->getId(),
                    'agent_command_results',
                    '',
                    fn () => $this->commandResultPool->getFormatted($voicePreset)
                );
            }

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

            if (!$voice) {
                return null;
            }

            $this->pluginRegistry->applyPreset($voicePreset);
            try {
                $actionResult = $this->agentActionsHandler->handleResponse($response, $voicePreset);
                $result = $actionResult->hasCommands()
                    ? ($actionResult->getSystemMessage() ?? '')
                    : $voice;
            } finally {
                $this->pluginRegistry->applyPreset($mainPreset);
            }

            $this->debugLog('Internal Voice response:'. $result);

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('InnerVoiceEnricher::callVoice error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get context limit for process
     *
     * @param AiPreset $mainPreset Main (source) preset
     * @param string $target
     * @return int
     */
    private function getPresetLimit(AiPreset $mainPreset, string $target): int
    {
        $method = self::ALLOWED_TARGETS[$target]['context_limit_method'];

        if (!method_exists($mainPreset, $method)) {
            return 1;
        }

        return max(1, $mainPreset->$method());
    }

    /**
     * Has voice?
     *
     * @param AiPreset $mainPreset Main (source) preset
     * @param string $target
     * @return boolean
     */
    private function hasVoice(AiPreset $preset, string $target): bool
    {
        $method = self::ALLOWED_TARGETS[$target]['check_method'];

        if (!method_exists($preset, $method)) {
            return false;
        }

        return (bool) $preset->$method();
    }

    private function generateEmptyResponse(AiPreset $mainPreset, ?AiPreset $voicePreset = null): InnerVoiceResponseInterface
    {
        return new InnerVoiceDTO($mainPreset, $voicePreset);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('InnerVoice: ' . $message, $context);
        }
    }
}

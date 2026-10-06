<?php

namespace App\Services\Agent\Formulation;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Formulation\FormulatorInvokerInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * FormulatorInvoker — turns raw memory intent into knowledge DSL by running a
 * formulator preset.
 *
 * -- Seam cut inside (deliberate) ---------------------------------------------
 * This class holds two layers, kept as two methods so the neutral one can be
 * lifted out cheaply the day a SECOND caller needs it:
 *
 *   runAuxiliaryPreset()  -- NEUTRAL. "Run preset A synchronously with this
 *                            input, restore preset B afterwards, return the raw
 *                            text." Knows nothing about knowledge, DSL, or
 *                            memory. This is the generalized
 *                            InnerVoiceEnricher::callVoice mechanic. When voice /
 *                            rag-formulation / some third consumer wants the same
 *                            call, THIS method moves to a neutral service (e.g.
 *                            AuxiliaryPresetRunner) and both consumers depend on
 *                            it. Until then it stays here -- no speculative
 *                            namespace, no premature abstraction.
 *
 *   translate()           -- KNOWLEDGE-SPECIFIC. Builds the "translate to DSL"
 *                            prompt and hands it to the neutral runner. This is
 *                            the part that must NOT leak into a shared service --
 *                            it's what would make a "universal" formulator start
 *                            knowing about memory DSL.
 *
 * The rule that keeps the neutral layer neutral: runAuxiliaryPreset never learns
 * WHY it's being called. It takes a ready-made input and returns raw text. The
 * moment it would need to know the output is DSL, the seam has been crossed.
 *
 * Honest DI constructor: registries injected normally. This class is not built
 * while constructing the plugin graph (the plugin resolves it lazily), so
 * injecting PluginRegistry here creates no container cycle.
 */
class FormulatorInvoker implements FormulatorInvokerInterface
{
    public function __construct(
        protected PresetRegistryInterface            $presetRegistry,
        protected PluginRegistryInterface            $pluginRegistry,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected LoggerInterface                    $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    //  KNOWLEDGE-SPECIFIC layer -- the DSL prompt wrapper.
    //  (Stays in knowledge forever; never moves to a neutral service.)
    // -------------------------------------------------------------------------

    public function translate(AiPreset $formulator, AiPreset $mainPreset, string $intent): ?string
    {
        $prompt = "Translate this memory intent into the knowledge DSL JSON:\n\n"
            . $intent
            . "\n\nReturn ONLY the JSON object.";

        // The neutral runner does the mechanics; we own only the prompt and the
        // (implicit) contract that the returned text should be DSL JSON. We do
        // NOT parse it here -- the plugin feeds it to KnowledgeDsl::fromJson, so
        // parsing stays at the single validation seam.
        return $this->runAuxiliaryPreset($formulator, $mainPreset, $prompt);
    }

    // -------------------------------------------------------------------------
    //  NEUTRAL layer -- synchronous auxiliary-preset call.
    //  (Lift THIS to a shared service when a second consumer appears.)
    // -------------------------------------------------------------------------

    /**
     * Run $aux synchronously with a single user-turn input, restore $restoreTo's
     * plugin/shortcode environment afterwards, and return the raw response text
     * (tags stripped) -- or null on any failure.
     *
     * Neutral: no knowledge of what the input means or what the output is for.
     * Mirrors InnerVoiceEnricher::callVoice step for step.
     *
     * @param  AiPreset $aux        Preset to run.
     * @param  AiPreset $restoreTo  Preset whose environment to restore in finally.
     * @param  string   $input      Ready-made user-turn content.
     * @return string|null          Raw response text, or null on failure.
     */
    private function runAuxiliaryPreset(AiPreset $aux, AiPreset $restoreTo, string $input): ?string
    {
        try {
            // Apply the auxiliary preset's plugin/shortcode environment.
            $this->pluginRegistry->applyPreset($aux);

            $flatContext = [[
                'role'         => 'user',
                'content'      => $input,
                'from_user_id' => null,
            ]];

            $engine   = $this->presetRegistry->createInstance($aux->getId());
            $response = $engine->generate(new ModelRequestDTO(
                preset:                    $aux,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   $flatContext,
            ));

            if ($response->isError()) {
                $this->logger->warning('FormulatorInvoker: auxiliary preset call failed', [
                    'aux_preset_id' => $aux->getId(),
                    'error'         => $response->getResponse(),
                ]);
                return null;
            }

            $out = trim(strip_tags($response->getResponse()));
            return $out !== '' ? $out : null;

        } catch (\Throwable $e) {
            $this->logger->error('FormulatorInvoker::runAuxiliaryPreset error: ' . $e->getMessage(), [
                'aux_preset_id'     => $aux->getId(),
                'restore_preset_id' => $restoreTo->getId(),
                'trace'             => $e->getTraceAsString(),
            ]);
            return null;
        } finally {
            // Always restore the caller's environment.
            try {
                $this->pluginRegistry->applyPreset($restoreTo);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'FormulatorInvoker: failed to restore preset after auxiliary call: ' . $e->getMessage()
                );
            }
        }
    }
}

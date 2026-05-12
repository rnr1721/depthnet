<?php

namespace App\Services\Agent\Plugins\Traits;

use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Contracts\Agent\Models\PresetServiceInterface;

/**
 * Provides a reusable handoff dispatch helper for plugins that need to
 * transfer control to another preset via execution meta.
 *
 * Requires the consuming class to provide:
 *
 * @property PresetServiceInterface $presetService
 *
 * Requires the consuming class to use:
 * @see PluginExecutionMetaTrait::setPluginExecutionMeta()
 *
 */

trait PluginHandoffTrait
{
    /**
     * Resolve preset by code and set handoff execution meta.
     * Returns success/error string for the agent.
     */
    protected function dispatchHandoff(
        string $presetCode,
        ?string $message,
        PluginExecutionContext $context
    ): string {
        $preset = $this->presetService->findByCode($presetCode);

        if (!$preset) {
            return "Error: Preset '{$presetCode}' not found.";
        }

        if (!$preset->allow_handoff_to) {
            return "Error: Preset '{$presetCode}' does not allow handoff transfers.";
        }

        $this->setPluginExecutionMeta('handoff', [
            'target_preset'   => $presetCode,
            'handoff_message' => $message,
            'error_behavior'  => $preset->error_behavior ?? 'stop',
        ]);

        $messageInfo = $message ? " with message: '{$message}'" : '';
        return "Transferring control to preset: {$presetCode}{$messageInfo}";
    }
}

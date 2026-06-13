<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Optional interface for capability providers that can enumerate the models
 * available to the configured account.
 *
 * Providers implement this only when their API exposes a usable model list
 * (e.g. an OpenAI-compatible /models endpoint). The controller detects support
 * via instanceof and exposes a "load models" endpoint — providers without it
 * simply don't offer the feature, keeping the base contract clean.
 */
interface ListsModelsInterface
{
    /**
     * @return array<int, array<string, string>> Each item: id, title, description.
     */
    public function listModels(): array;
}

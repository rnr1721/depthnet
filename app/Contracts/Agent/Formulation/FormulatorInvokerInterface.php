<?php

namespace App\Contracts\Agent\Formulation;

use App\Models\AiPreset;

/**
 * FormulatorInvokerInterface — isolates the "call another preset to translate
 * raw intent into knowledge DSL" operation.
 *
 * Why this exists as its own service: it needs PresetRegistry + PluginRegistry,
 * and PluginRegistry constructs every plugin (including KnowledgePlugin). If the
 * plugin depended on the registries directly, the container would loop:
 *   build KnowledgePlugin → needs PluginRegistry → builds all plugins →
 *   KnowledgePlugin → …
 *
 * Pulling the registry-touching work into this service, and having the plugin
 * resolve THIS service lazily (only when formulator mode actually fires),
 * breaks that cycle: constructing the plugin no longer reaches the registries.
 *
 * This service itself has a normal DI constructor — the registries are injected
 * honestly here, because nothing constructs *this* service as part of building
 * the plugin graph.
 */
interface FormulatorInvokerInterface
{
    /**
     * Translate a raw natural-language memory intent into a knowledge DSL JSON
     * string, by running the given formulator preset synchronously.
     *
     * Restores the main preset's plugin/shortcode environment afterwards
     * (try/finally), exactly like InnerVoiceEnricher::callVoice.
     *
     * @param  AiPreset $formulator  The preset that does the translation.
     * @param  AiPreset $mainPreset  The preset to restore after the call.
     * @param  string   $intent      Raw natural-language intent.
     * @return string|null           DSL JSON on success, null on any failure
     *                               (caller decides how to surface that).
     */
    public function translate(AiPreset $formulator, AiPreset $mainPreset, string $intent): ?string;
}

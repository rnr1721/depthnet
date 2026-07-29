<?php

namespace App\Contracts\Agent\Exchange;

/**
 * Exports presets and agents into a portable "depthnet.bundle" structure.
 *
 * The bundle carries CONFIGURATION, not lived state: no memory, dialogues,
 * vectors, learning history or secrets. All internal links are expressed as
 * bundle-local `ref` UUIDs, never as autoincrement IDs — so the same bundle
 * imports correctly on any instance.
 *
 * See docs: bundle format specification (format_version: 1).
 */
interface PresetExporterInterface
{
    /**
     * Export a single preset together with its full transitive closure of
     * preset dependencies (cycle_prompt, target, rag_preset, inner_voice
     * voice_preset — recursively), each with its owned collections.
     *
     * @param  int   $presetId  Root preset id.
     * @param  array $options   ['include_skills' => bool].
     * @return array            Ready bundle; agents[] is empty.
     *
     * @throws \App\Exceptions\Exchange\ExportException on missing preset or
     *         an unexportable reference (e.g. a spawned preset in the closure).
     */
    public function exportPreset(int $presetId, array $options = []): array;

    /**
     * Export an agent: its planner, every role preset and every validator
     * preset, plus the full closure those pull in. agents[] contains one agent.
     *
     * @param  int   $agentId  Root agent id.
     * @param  array $options  ['include_skills' => bool].
     * @return array           Ready bundle with presets[] (closure) + agents[] (one).
     *
     * @throws \App\Exceptions\Exchange\ExportException
     */
    public function exportAgent(int $agentId, array $options = []): array;

    /**
     * Core export: build one bundle from any set of preset and agent roots.
     * The transitive closure of all roots is merged and de-duplicated, so a
     * preset referenced by several roots appears exactly once.
     *
     * exportPreset()/exportAgent() are thin facades over this method.
     *
     * @param  int[] $presetIds  Root preset ids (may be empty).
     * @param  int[] $agentIds   Root agent ids (may be empty).
     * @param  array $options    ['include_skills' => bool].
     * @return array             Ready bundle.
     *
     * @throws \App\Exceptions\Exchange\ExportException
     */
    public function exportBundle(array $presetIds, array $agentIds, array $options = []): array;
}

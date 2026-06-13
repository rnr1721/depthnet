<?php

namespace App\Contracts\Agent\Ontology;

use App\Models\AiPreset;
use App\Models\OntologyNode;

/**
 * OntologyServiceInterface
 *
 * Contract for the agent's world-model graph.
 * All operations are preset-scoped and temporally aware.
 */
interface OntologyServiceInterface
{
    /**
     * Find a node by canonical name or alias.
     * Returns null if not found.
     */
    public function findNode(AiPreset $preset, string $name): ?OntologyNode;

    /**
     * Add a new node or return the existing one if a name/alias match is found.
     *
     * @param AiPreset $preset
     * @param  array{
     *     name: string,
     *     class: string,
     *     aliases?: string[],
     * } $params
     * @return array
     */
    public function addNode(AiPreset $preset, array $params): array;

    /**
     * Add a directed edge between two nodes (by canonical name or alias).
     * If a current edge of the same type already exists, its weight is incremented.
     *
     * @param  array{
     *     source: string,
     *     target: string,
     *     relation: string,
     *     valid_from?: string,
     * } $params
     */
    public function addEdge(AiPreset $preset, array $params): array;

    /**
     * Close the current value of a property and set a new one.
     * Preserves history — does not update the existing row.
     *
     * @param AiPreset $preset
     * @param  array{
     *     node: string,
     *     key: string,
     *     value: string,
     * } $params
     * @return array
     */
    public function setProperty(AiPreset $preset, array $params): array;

    /**
     * Return a snapshot of a node and its neighbourhood.
     *
     * @param AiPreset $preset
     * @param  array{
     *     node: string,
     *     depth?: int,
     * } $params
     * @return array
     */
    public function getSnapshot(AiPreset $preset, array $params): array;

    /**
     * Merge source node into target node.
     * All edges and properties are re-pointed to target.
     * Source node is deleted.
     *
     * @param AiPreset $preset
     * @param  array{
     *     source: string,
     *     target: string,
     * } $params
     * @return array
     */
    public function mergeNodes(AiPreset $preset, array $params): array;

    /**
     * Close all current edges/properties for a node,
     * effectively removing it from the active world model.
     * The node record itself is preserved for history.
     *
     * @param AiPreset $preset
     * @param string $name
     * @return array
     */
    public function closeNode(AiPreset $preset, string $name): array;

    /**
     * Find all nodes whose canonical_name or aliases appear in the given text.
     * Used by RAG enricher to build ontology context from retrieved memories.
     *
     * @param AiPreset $preset
     * @param string $text
     * @return OntologyNode[]
     */
    public function findMentionedNodes(AiPreset $preset, string $text): array;

    /**
     * Delete all ontology data for a preset.
     * Removes nodes, properties, and edges completely.
     * Called by the global preset clear routine.
     *
     * @param AiPreset $preset
     * @return array
     */
    public function clear(AiPreset $preset): array;

    /**
     * Update a node's mutable fields. Insert-only design is preserved:
     * the node's identity (id, preset_id) and history (edges, properties)
     * are never touched by this method.
     *
     * Supported fields in $params:
     *   - 'canonical_name' (string): rename the node. Lowercased and trimmed.
     *                                Must be unique within the preset; if
     *                                another node already owns the new name
     *                                (by canonical_name or alias), the update
     *                                fails. To merge two nodes use mergeNodes()
     *                                instead.
     *   - 'class' (string): change the node's class (e.g. "Concept" → "Person").
     *   - 'aliases' (array<string>|null): replace the alias set entirely.
     *                                     Pass [] or null to clear all aliases.
     *                                     Aliases are normalized (trim, lowercase,
     *                                     dedupe) and the canonical_name is
     *                                     stripped from the set if present.
     *   - 'add_aliases' (array<string>): merge these aliases into the existing
     *                                    set. Mutually exclusive with 'aliases'.
     *   - 'remove_aliases' (array<string>): drop these aliases from the existing
     *                                       set. Mutually exclusive with 'aliases'.
     *   - 'weight' (float): set the node's weight to an absolute value.
     *                       Must be >= 0.
     *
     * Any field not present in $params is left unchanged. Passing an empty
     * $params is a no-op and returns success.
     *
     * @param  AiPreset     $preset
     * @param  OntologyNode $node    The node to update. Must belong to $preset.
     * @param  array        $params  Fields to update (see above).
     * @return array{success: bool, message: string, node?: OntologyNode, changed?: array<string>}
     *         On success: 'changed' lists the names of fields that actually changed.
     */
    public function updateNode(AiPreset $preset, OntologyNode $node, array $params): array;

}

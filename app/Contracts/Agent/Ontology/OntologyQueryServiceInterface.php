<?php

namespace App\Contracts\Agent\Ontology;

use App\Models\AiPreset;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use Illuminate\Support\Collection;

interface OntologyQueryServiceInterface
{
    /**
     * Return a paginated, optionally filtered list of nodes for the admin UI.
     *
     * @return array{
     *     nodes: \Illuminate\Support\Collection,
     *     pagination: array,
     *     stats: array{total_nodes: int, total_edges: int, by_class: array},
     *     available_classes: \Illuminate\Support\Collection,
     * }
     */
    public function listForAdmin(
        AiPreset $preset,
        string   $search = '',
        string   $filterClass = '',
        int      $perPage = 25,
    ): array;

    /**
     * Aggregate stats for the header section.
     *
     * @return array{total_nodes: int, total_edges: int, by_class: array<string, int>}
     */
    public function stats(AiPreset $preset): array;

    /**
     * Distinct class values for the filter dropdown.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function availableClasses(AiPreset $preset): Collection;

    /**
     * Format a single node with its current edges and properties.
     *
     * @return array{
     *     id: int,
     *     canonical_name: string,
     *     class: string,
     *     aliases: array,
     *     weight: float,
     *     created_at: \Carbon\Carbon,
     *     edges: array,
     *     properties: array,
     * }
     */
    public function formatNode(OntologyNode $node, int $presetId): array;

    /**
     * Helper to fetch a node or fail (used by the update/delete endpoints).
     *
     * @param integer $nodeId
     * @return OntologyNode
     */
    public function findOrFailNode(int $nodeId): OntologyNode;

    /**
     * Helper to fetch an edge or fail (used by the delete endpoint).
     *
     * @param integer $edgeId
     * @return OntologyEdge
     */
    public function findOrFailEdge(int $edgeId): OntologyEdge;
}

<?php

namespace App\Services\Agent\Ontology;

use App\Contracts\Agent\Ontology\OntologyQueryServiceInterface;
use App\Models\AiPreset;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Models\OntologyNodeProperty;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * OntologyQueryService
 *
 * Read-only query layer for the ontology graph — used by the admin UI.
 * All write operations live in OntologyService.
 *
 * Responsibilities:
 *   - Paginated, filtered node listing for the admin panel
 *   - Per-node detail formatting (edges + properties)
 *   - Aggregate stats (node/edge counts by class)
 *   - Available class list for filter dropdowns
 */
class OntologyQueryService implements OntologyQueryServiceInterface
{
    public function __construct(
        protected OntologyNode $nodeModel,
        protected OntologyEdge $edgeModel,
        protected OntologyNodeProperty $propertyModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function listForAdmin(
        AiPreset $preset,
        string   $search = '',
        string   $filterClass = '',
        int      $perPage = 25,
    ): array {
        $query = $this->nodeModel->forPreset($preset->id)
            ->orderByDesc('weight');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('canonical_name', 'like', "%{$search}%")
                  ->orWhereJsonContains('aliases', $search);
            });
        }

        if ($filterClass !== '') {
            $query->where('class', $filterClass);
        }

        /** @var LengthAwarePaginator $paginated */
        $paginated = $query->paginate($perPage)->withQueryString();

        $nodes = $paginated->getCollection()->map(
            fn (OntologyNode $node) => $this->formatNode($node, $preset->id)
        );

        return [
            'nodes'             => $nodes,
            'pagination'        => $paginated->toArray(),
            'stats'             => $this->stats($preset),
            'available_classes' => $this->availableClasses($preset),
        ];
    }

    /**
     * @inheritDoc
     */
    public function stats(AiPreset $preset): array
    {
        return [
            'total_nodes' => $this->nodeModel->forPreset($preset->id)->count(),
            'total_edges' => $this->edgeModel->forPreset($preset->id)->current()->count(),
            'by_class'    => $this->nodeModel->forPreset($preset->id)
                ->selectRaw('class, count(*) as count')
                ->groupBy('class')
                ->orderByDesc('count')
                ->pluck('count', 'class')
                ->toArray(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function availableClasses(AiPreset $preset): Collection
    {
        return $this->nodeModel->forPreset($preset->id)
            ->distinct()
            ->pluck('class')
            ->sort()
            ->values();
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function formatNode(OntologyNode $node, int $presetId): array
    {
        $edges = $this->edgeModel->forPreset($presetId)
            ->where(fn ($q) => $q->where('source_id', $node->id)->orWhere('target_id', $node->id))
            ->current()
            ->with(['source', 'target'])
            ->orderByDesc('weight')
            ->get()
            ->map(fn (OntologyEdge $e) => [
                'id'            => $e->id,
                'source_id'     => $e->source_id,
                'source_name'   => $e->source?->canonical_name,
                'target_id'     => $e->target_id,
                'target_name'   => $e->target?->canonical_name,
                'relation_type' => $e->relation_type,
                'weight'        => $e->weight,
                'valid_from'    => $e->valid_from,
            ]);

        $properties = $this->propertyModel->where('node_id', $node->id)
            ->current()
            ->get()
            ->map(fn (OntologyNodeProperty $p) => [
                'id'            => $p->id,
                'key'           => $p->key,
                'value_scalar'  => $p->value_scalar,
                'value_node_id' => $p->value_node_id,
                'valid_from'    => $p->valid_from,
            ]);

        return [
            'id'             => $node->id,
            'canonical_name' => $node->canonical_name,
            'class'          => $node->class,
            'aliases'        => $node->aliases ?? [],
            'weight'         => $node->weight,
            'created_at'     => $node->created_at,
            'edges'          => $edges,
            'properties'     => $properties,
        ];
    }

    /**
     * Helper to fetch a node or fail (used by the update/delete endpoints).
     */
    public function findOrFailNode(int $nodeId): OntologyNode
    {
        return $this->nodeModel->findOrFail($nodeId);
    }

    /**
     * Helper to fetch an edge or fail (used by the delete endpoint).
     */
    public function findOrFailEdge(int $edgeId): OntologyEdge
    {
        return $this->edgeModel->findOrFail($edgeId);
    }
}

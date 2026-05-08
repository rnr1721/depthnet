<?php

namespace App\Services\Agent\Ontology;

use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Models\AiPreset;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Models\OntologyNodeProperty;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * OntologyService
 *
 * Agent world-model graph — a temporal property graph stored in MySQL.
 *
 * Design principles:
 *   - Insert-only for edges and properties (history is never lost)
 *   - valid_until = null means "currently valid"
 *   - All operations are preset-scoped
 *   - No enums — class, relation_type, key are free strings
 *   - Node lookup always checks aliases to prevent duplicates
 */
class OntologyService implements OntologyServiceInterface
{
    public function __construct(
        protected OntologyNode         $nodeModel,
        protected OntologyNodeProperty $propertyModel,
        protected OntologyEdge         $edgeModel,
        protected DatabaseManager $db,
        protected LoggerInterface      $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Node operations
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function findNode(AiPreset $preset, string $name): ?OntologyNode
    {
        $name = trim($name);

        // Try exact canonical match first (uses index)
        $node = $this->nodeModel
            ->forPreset($preset->id)
            ->where('canonical_name', mb_strtolower($name))
            ->first();

        if ($node) {
            return $node;
        }

        // Fall back to alias scan — load all nodes for preset and check in PHP
        // Acceptable for typical ontology sizes (hundreds, not millions of nodes)
        return $this->nodeModel
            ->forPreset($preset->id)
            ->get()
            ->first(fn (OntologyNode $n) => $n->matchesName($name));
    }

    /**
     * {@inheritdoc}
     */
    public function addNode(AiPreset $preset, array $params): array
    {
        try {
            $name    = mb_strtolower(trim($params['name'] ?? ''));
            $class   = trim($params['class'] ?? 'Concept');
            $aliases = $params['aliases'] ?? [];

            if (empty($name)) {
                return ['success' => false, 'message' => 'Node name is required.'];
            }

            // Check for existing node by name or any of the provided aliases
            $existing = $this->findNode($preset, $name);

            if (!$existing && !empty($aliases)) {
                foreach ($aliases as $alias) {
                    $existing = $this->findNode($preset, $alias);
                    if ($existing) {
                        break;
                    }
                }
            }

            if ($existing) {
                // Merge any new aliases into the existing node
                foreach ($aliases as $alias) {
                    $existing->addAlias($alias);
                }

                return [
                    'success' => true,
                    'message' => "Node already exists: [{$existing->class}] {$existing->canonical_name} (id:{$existing->id}). Aliases updated.",
                    'node'    => $existing,
                    'created' => false,
                ];
            }

            $node = $this->nodeModel->create([
                'preset_id'      => $preset->id,
                'canonical_name' => $name,
                'class'          => $class,
                'aliases'        => $aliases ?: null,
                'weight'         => 1.0,
            ]);

            return [
                'success' => true,
                'message' => "Node created: [{$node->class}] {$node->canonical_name} (id:{$node->id})",
                'node'    => $node,
                'created' => true,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::addNode error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating node: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Edge operations
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function addEdge(AiPreset $preset, array $params): array
    {
        try {
            $sourceName   = trim($params['source'] ?? '');
            $targetName   = trim($params['target'] ?? '');
            $relationType = trim($params['relation'] ?? '');
            $validFrom    = isset($params['valid_from'])
                ? Carbon::parse($params['valid_from'])
                : now();

            if (empty($sourceName) || empty($targetName) || empty($relationType)) {
                return ['success' => false, 'message' => 'source, target, and relation are required.'];
            }

            $source = $this->findNode($preset, $sourceName);
            $target = $this->findNode($preset, $targetName);

            if (!$source) {
                return ['success' => false, 'message' => "Source node not found: \"{$sourceName}\". Add it first."];
            }

            if (!$target) {
                return ['success' => false, 'message' => "Target node not found: \"{$targetName}\". Add it first."];
            }

            // If a current edge of the same type already exists — increment weight
            $existing = $this->edgeModel
                ->forPreset($preset->id)
                ->where('source_id', $source->id)
                ->where('target_id', $target->id)
                ->where('relation_type', $relationType)
                ->current()
                ->first();

            if ($existing) {
                $existing->increment('weight', 1.0);

                return [
                    'success' => true,
                    'message' => "Edge reinforced: {$source->canonical_name} --[{$relationType}]--> {$target->canonical_name} (weight:{$existing->weight})",
                    'edge'    => $existing,
                    'created' => false,
                ];
            }

            $edge = $this->edgeModel->create([
                'preset_id'     => $preset->id,
                'source_id'     => $source->id,
                'target_id'     => $target->id,
                'relation_type' => $relationType,
                'weight'        => 1.0,
                'valid_from'    => $validFrom,
                'valid_until'   => null,
                'created_at'    => now(),
            ]);

            // Bump node weights to reflect increased centrality
            $source->incrementWeight(0.5);
            $target->incrementWeight(0.5);

            return [
                'success' => true,
                'message' => "Edge created: {$source->canonical_name} --[{$relationType}]--> {$target->canonical_name}",
                'edge'    => $edge,
                'created' => true,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::addEdge error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating edge: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Property operations
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function setProperty(AiPreset $preset, array $params): array
    {
        try {
            $nodeName = trim($params['node'] ?? '');
            $key      = trim($params['key'] ?? '');
            $value    = trim($params['value'] ?? '');

            if (empty($nodeName) || empty($key)) {
                return ['success' => false, 'message' => 'node and key are required.'];
            }

            $node = $this->findNode($preset, $nodeName);
            if (!$node) {
                return ['success' => false, 'message' => "Node not found: \"{$nodeName}\"."];
            }

            // Resolve value: is it a reference to another node or a scalar?
            $valueNodeId  = null;
            $valueScalar  = null;
            $valueNode    = $this->findNode($preset, $value);

            if ($valueNode) {
                $valueNodeId = $valueNode->id;
            } else {
                $valueScalar = $value;
            }

            // Close current property (insert-only, preserve history)
            $this->propertyModel
                ->where('node_id', $node->id)
                ->where('key', $key)
                ->current()
                ->update(['valid_until' => now()]);

            $property = $this->propertyModel->create([
                'node_id'       => $node->id,
                'key'           => $key,
                'value_scalar'  => $valueScalar,
                'value_node_id' => $valueNodeId,
                'valid_from'    => now(),
                'valid_until'   => null,
            ]);

            $displayValue = $valueNode
                ? "{$valueNode->canonical_name} (node ref)"
                : $valueScalar;

            return [
                'success'  => true,
                'message'  => "Property set: {$node->canonical_name}.{$key} = {$displayValue}",
                'property' => $property,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::setProperty error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error setting property: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Snapshot / read
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function getSnapshot(AiPreset $preset, array $params): array
    {
        try {
            $nodeName = trim($params['node'] ?? '');
            $depth    = max(1, min(3, (int) ($params['depth'] ?? 1)));

            if (empty($nodeName)) {
                return ['success' => false, 'message' => 'node is required.'];
            }

            $root = $this->findNode($preset, $nodeName);
            if (!$root) {
                return ['success' => false, 'message' => "Node not found: \"{$nodeName}\"."];
            }

            $visited = [];
            $output  = $this->buildSnapshot($preset, $root, $depth, $visited);

            return [
                'success' => true,
                'message' => $output,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::getSnapshot error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error getting snapshot: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Merge
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function mergeNodes(AiPreset $preset, array $params): array
    {
        try {
            $sourceName = trim($params['source'] ?? '');
            $targetName = trim($params['target'] ?? '');

            if (empty($sourceName) || empty($targetName)) {
                return ['success' => false, 'message' => 'source and target node names are required.'];
            }

            $source = $this->findNode($preset, $sourceName);
            $target = $this->findNode($preset, $targetName);

            if (!$source) {
                return ['success' => false, 'message' => "Source node not found: \"{$sourceName}\"."];
            }

            if (!$target) {
                return ['success' => false, 'message' => "Target node not found: \"{$targetName}\"."];
            }

            if ($source->id === $target->id) {
                return ['success' => false, 'message' => 'Source and target are the same node.'];
            }

            $this->db->transaction(function () use ($source, $target, $preset) {
                // Re-point all outgoing edges from source to target
                $this->edgeModel
                    ->forPreset($preset->id)
                    ->where('source_id', $source->id)
                    ->update(['source_id' => $target->id]);

                // Re-point all incoming edges to target
                $this->edgeModel
                    ->forPreset($preset->id)
                    ->where('target_id', $source->id)
                    ->update(['target_id' => $target->id]);

                // Re-point all properties referencing source as value_node_id
                $this->propertyModel
                    ->where('value_node_id', $source->id)
                    ->update(['value_node_id' => $target->id]);

                // Re-point all properties owned by source to target
                $this->propertyModel
                    ->where('node_id', $source->id)
                    ->update(['node_id' => $target->id]);

                // Merge aliases into target
                $mergedAliases = array_unique(array_merge(
                    $target->aliases ?? [],
                    $source->aliases ?? [],
                    [$source->canonical_name],
                ));
                $target->update([
                    'aliases' => $mergedAliases,
                    'weight'  => $target->weight + $source->weight,
                ]);

                // Delete source node
                $source->delete();
            });

            return [
                'success' => true,
                'message' => "Merged \"{$source->canonical_name}\" into \"{$target->canonical_name}\". Source node deleted, all edges and properties transferred.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::mergeNodes error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error merging nodes: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Close node
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function closeNode(AiPreset $preset, string $name): array
    {
        try {
            $node = $this->findNode($preset, $name);

            if (!$node) {
                return ['success' => false, 'message' => "Node not found: \"{$name}\"."];
            }

            $now = now();

            // Close all current outgoing and incoming edges
            $this->edgeModel
                ->forPreset($preset->id)
                ->where(fn ($q) => $q->where('source_id', $node->id)->orWhere('target_id', $node->id))
                ->current()
                ->update(['valid_until' => $now]);

            // Close all current properties
            $this->propertyModel
                ->where('node_id', $node->id)
                ->current()
                ->update(['valid_until' => $now]);

            return [
                'success' => true,
                'message' => "Node \"{$node->canonical_name}\" closed. All current edges and properties invalidated. History preserved.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::closeNode error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error closing node: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Text mining
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Strategy:
     *   1. Load all canonical_names for this preset in one query (indexed).
     *   2. Do a case-insensitive word-boundary check in PHP against the text.
     *   3. For nodes that don't match by canonical_name, check aliases via JSON_CONTAINS.
     *
     * This avoids loading full node objects until we know there's a hit,
     * keeping the query cost low even for large ontologies.
     *
     * @param AiPreset $preset
     * @param string $text
     * @return array
     */
    public function findMentionedNodes(AiPreset $preset, string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $textLower = mb_strtolower($text);

        // Step 1: get all canonical names + ids for this preset (lightweight)
        $rows = $this->nodeModel
            ->forPreset($preset->id)
            ->select(['id', 'canonical_name', 'aliases'])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $matchedIds = [];

        foreach ($rows as $row) {
            // Check canonical_name as whole word / phrase
            if ($this->textContainsTerm($textLower, $row->canonical_name)) {
                $matchedIds[] = $row->id;
                continue;
            }

            // Check aliases
            foreach ($row->aliases ?? [] as $alias) {
                if ($this->textContainsTerm($textLower, mb_strtolower($alias))) {
                    $matchedIds[] = $row->id;
                    break;
                }
            }
        }

        if (empty($matchedIds)) {
            return [];
        }

        // Load full node objects only for matches
        return $this->nodeModel
            ->whereIn('id', $matchedIds)
            ->get()
            ->all();
    }

    /**
     * Case-insensitive check whether $text contains $term as a meaningful token.
     * Avoids false positives like "trust" matching "trustworthy".
     *
     * @param string $textLower
     * @param string $term
     * @return boolean
     */
    private function textContainsTerm(string $textLower, string $term): bool
    {
        $term = mb_strtolower(trim($term));

        if (empty($term) || mb_strlen($term) < 2) {
            return false;
        }

        // Replace underscores with spaces for snake_case terms
        $termNormalized = str_replace('_', ' ', $term);

        // Word-boundary check via regex (handles unicode)
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote($termNormalized, '/') . '(?![\\p{L}\\p{N}])/ui';

        return (bool) preg_match($pattern, $textLower);
    }

    // -------------------------------------------------------------------------
    // Clear
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function clear(AiPreset $preset): array
    {
        try {
            // Collect all node IDs for this preset
            $nodeIds = $this->nodeModel
                ->forPreset($preset->id)
                ->pluck('id');

            $nodeCount = $nodeIds->count();

            if ($nodeCount === 0) {
                return ['success' => true, 'message' => 'Ontology is already empty.'];
            }

            $this->db->transaction(function () use ($preset, $nodeIds) {
                // Delete properties (FK to nodes)
                $this->propertyModel
                    ->whereIn('node_id', $nodeIds)
                    ->delete();

                // Delete edges (FK to nodes via source_id / target_id)
                $this->edgeModel
                    ->forPreset($preset->id)
                    ->delete();

                // Delete nodes
                $this->nodeModel
                    ->forPreset($preset->id)
                    ->delete();
            });

            return [
                'success' => true,
                'message' => "Ontology cleared. {$nodeCount} nodes and all related edges and properties removed.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::clear error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error clearing ontology: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Snapshot builder
    // -------------------------------------------------------------------------

    /**
     * Recursively build a text snapshot of a node's neighbourhood.
     *
     * @param AiPreset $preset
     * @param OntologyNode $node
     * @param integer $depth
     * @param array $visited
     * @param integer $currentDepth
     * @param string $indent
     * @return string
     */
    private function buildSnapshot(
        AiPreset     $preset,
        OntologyNode $node,
        int          $depth,
        array        &$visited,
        int          $currentDepth = 0,
        string       $indent       = '',
    ): string {
        if (in_array($node->id, $visited, true)) {
            return $indent . "[{$node->class}] {$node->canonical_name} (already expanded)\n";
        }

        $visited[] = $node->id;

        $aliases = !empty($node->aliases) ? ' (' . implode(', ', $node->aliases) . ')' : '';
        $lines   = ["{$indent}[{$node->class}] {$node->canonical_name}{$aliases} weight:{$node->weight}"];

        // Current properties
        $properties = $this->propertyModel
            ->where('node_id', $node->id)
            ->current()
            ->get();

        foreach ($properties as $prop) {
            if ($prop->value_node_id) {
                $valueNode = $this->nodeModel->find($prop->value_node_id);
                $val       = $valueNode ? "[{$valueNode->class}] {$valueNode->canonical_name}" : "node:{$prop->value_node_id}";
            } else {
                $val = $prop->value_scalar;
            }
            $lines[] = "{$indent}  .{$prop->key} = {$val}";
        }

        if ($depth > $currentDepth) {
            // Outgoing edges
            $outgoing = $this->edgeModel
                ->forPreset($preset->id)
                ->where('source_id', $node->id)
                ->current()
                ->orderByDesc('weight')
                ->with('target')
                ->get();

            foreach ($outgoing as $edge) {
                if (!$edge->target) {
                    continue;
                }
                $lines[] = "{$indent}  --[{$edge->relation_type}]--> [{$edge->target->class}] {$edge->target->canonical_name} (w:{$edge->weight})";

                if ($currentDepth + 1 < $depth) {
                    $lines[] = $this->buildSnapshot($preset, $edge->target, $depth, $visited, $currentDepth + 1, $indent . '    ');
                }
            }

            // Incoming edges (show who points to this node)
            $incoming = $this->edgeModel
                ->forPreset($preset->id)
                ->where('target_id', $node->id)
                ->current()
                ->orderByDesc('weight')
                ->with('source')
                ->get();

            foreach ($incoming as $edge) {
                if (!$edge->source) {
                    continue;
                }
                $lines[] = "{$indent}  <--[{$edge->relation_type}]-- [{$edge->source->class}] {$edge->source->canonical_name} (w:{$edge->weight})";
            }
        }

        return implode("\n", $lines) . "\n";
    }
}

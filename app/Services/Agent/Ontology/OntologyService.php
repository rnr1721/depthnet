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
    /**
     * Per-request LRU-ish caches. Bounded to keep long-lived workers safe.
     */
    private const NODE_CACHE_MAX = 2000;
    private const NODE_ID_CACHE_MAX = 2000;
    private const MISSED_CACHE_MAX = 5000;

    /**
     * Marker prefix for explicit node references in property values.
     * Example: setProperty(node="adalia", key="favorite_color", value="@red")
     * forces "red" to be resolved as a node reference rather than scalar.
     */
    private const NODE_REF_PREFIX = '@';

    private array $nodeIdCache = [];
    private array $nodeCache = [];
    private array $missedCache = [];

    public function __construct(
        protected OntologyNode         $nodeModel,
        protected OntologyNodeProperty $propertyModel,
        protected OntologyEdge         $edgeModel,
        protected DatabaseManager      $db,
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
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $presetId = (string) $preset->id;

        if (isset($this->missedCache[$presetId][$name])) {
            return null;
        }

        if (isset($this->nodeCache[$presetId][$name])) {
            return $this->nodeCache[$presetId][$name];
        }

        // 1. Fast indexed search by canonical_name
        $node = $this->nodeModel
            ->forPreset($presetId)
            ->where('canonical_name', $name)
            ->first();

        if ($node) {
            $this->cacheNodeWithAliases($presetId, $node);
            return $node;
        }

        // 2. JSON contains lookup against aliases
        $node = $this->nodeModel
            ->forPreset($presetId)
            ->whereJsonContains('aliases', $name)
            ->first();

        if ($node) {
            $this->cacheNodeWithAliases($presetId, $node);
            return $node;
        }

        // Negative cache — bounded to prevent unbounded growth from typo-heavy input.
        if (!isset($this->missedCache[$presetId])) {
            $this->missedCache[$presetId] = [];
        }
        if (count($this->missedCache[$presetId]) >= self::MISSED_CACHE_MAX) {
            // Drop oldest half (FIFO-ish — array_slice preserves insertion order)
            $this->missedCache[$presetId] = array_slice(
                $this->missedCache[$presetId],
                (int) (self::MISSED_CACHE_MAX / 2),
                null,
                true
            );
        }
        $this->missedCache[$presetId][$name] = true;

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function addNode(AiPreset $preset, array $params): array
    {
        try {
            $name    = mb_strtolower(trim($params['name'] ?? ''));
            $class   = trim($params['class'] ?? 'Concept');
            $aliases = $this->normalizeAliases($params['aliases'] ?? []);
            $presetId = (string) $preset->id;

            if (empty($name)) {
                return ['success' => false, 'message' => 'Node name is required.'];
            }

            // Wrap the entire find-or-create flow in a transaction with row-level lock
            // to eliminate the race window between findNode() and create().
            $result = $this->db->transaction(function () use ($preset, $name, $class, $aliases, $presetId) {
                // Look up by canonical_name with FOR UPDATE
                $existing = $this->nodeModel
                    ->forPreset($presetId)
                    ->where('canonical_name', $name)
                    ->lockForUpdate()
                    ->first();

                // If not found, also check aliases (canonical_name and provided aliases)
                if (!$existing) {
                    $candidates = array_merge([$name], $aliases);
                    foreach ($candidates as $candidate) {
                        $existing = $this->nodeModel
                            ->forPreset($presetId)
                            ->whereJsonContains('aliases', $candidate)
                            ->lockForUpdate()
                            ->first();
                        if ($existing) {
                            break;
                        }
                    }
                }

                if ($existing) {
                    foreach ($aliases as $alias) {
                        $existing->addAlias($alias);
                    }

                    return [
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
                    'node'    => $node,
                    'created' => true,
                ];
            });

            $node = $result['node'];
            $created = $result['created'];

            // Cache update happens outside transaction.
            // For an existing node whose aliases just changed, drop the preset cache
            // so any stale alias→null entries don't linger.
            if (!$created) {
                $this->invalidatePresetCache($presetId);
            }
            // cacheNodeWithAliases also clears matching entries from missedCache,
            // so a name that was previously missed becomes findable immediately.
            $this->cacheNodeWithAliases($presetId, $node);

            $message = $created
                ? "Node created: [{$node->class}] {$node->canonical_name} (id:{$node->id})"
                : "Node already exists: [{$node->class}] {$node->canonical_name} (id:{$node->id}). Aliases updated.";

            return [
                'success' => true,
                'message' => $message,
                'node'    => $node,
                'created' => $created,
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
            $presetId = (string) $preset->id;

            if (empty($sourceName) || empty($targetName) || empty($relationType)) {
                return ['success' => false, 'message' => 'source, target, and relation are required.'];
            }

            // Quick textual self-loop check as optimization (avoids transaction overhead)
            if (mb_strtolower($sourceName) === mb_strtolower($targetName)) {
                return ['success' => false, 'message' => "Self-loop not allowed: \"{$sourceName}\" cannot have an edge to itself."];
            }

            // Sentinel for self-loop after node resolution: thrown to abort transaction cleanly
            $selfLoopError = new \DomainException('__self_loop__');

            try {
                $result = $this->db->transaction(function () use ($preset, $sourceName, $targetName, $relationType, $validFrom, $presetId, $selfLoopError) {
                    $source      = $this->resolveOrCreateNode($preset, $sourceName);
                    $target      = $this->resolveOrCreateNode($preset, $targetName);
                    $autoCreated = [];

                    if ($source['created']) {
                        $autoCreated[] = $source['node']->canonical_name;
                    }
                    if ($target['created']) {
                        $autoCreated[] = $target['node']->canonical_name;
                    }

                    $sourceNode = $source['node'];
                    $targetNode = $target['node'];

                    // Proper self-loop check after alias resolution
                    if ($sourceNode->id === $targetNode->id) {
                        throw $selfLoopError;
                    }

                    // If a current edge of the same type already exists — atomically increment weight
                    $existing = $this->edgeModel
                        ->forPreset($preset->id)
                        ->where('source_id', $sourceNode->id)
                        ->where('target_id', $targetNode->id)
                        ->where('relation_type', $relationType)
                        ->current()
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $existing->increment('weight', 1.0);
                        $existing->refresh();

                        return [
                            'edge'        => $existing,
                            'created'     => false,
                            'source'      => $sourceNode,
                            'target'      => $targetNode,
                            'autoCreated' => $autoCreated,
                        ];
                    }

                    $edge = $this->edgeModel->create([
                        'preset_id'     => $preset->id,
                        'source_id'     => $sourceNode->id,
                        'target_id'     => $targetNode->id,
                        'relation_type' => $relationType,
                        'weight'        => 1.0,
                        'valid_from'    => $validFrom,
                        'valid_until'   => null,
                        'created_at'    => now(),
                    ]);

                    $sourceNode->incrementWeight(0.5);
                    $targetNode->incrementWeight(0.5);

                    return [
                        'edge'        => $edge,
                        'created'     => true,
                        'source'      => $sourceNode,
                        'target'      => $targetNode,
                        'autoCreated' => $autoCreated,
                    ];
                });
            } catch (\DomainException $e) {
                if ($e === $selfLoopError) {
                    return ['success' => false, 'message' => "Self-loop not allowed: \"{$sourceName}\" and \"{$targetName}\" resolve to the same node."];
                }
                throw $e;
            }

            // Cache nodes that were touched during the transaction.
            // cacheNodeWithAliases also clears any stale negative-cache entries.
            $this->cacheNodeWithAliases($presetId, $result['source']);
            $this->cacheNodeWithAliases($presetId, $result['target']);

            $msg = $result['created']
                ? "Edge created: {$result['source']->canonical_name} --[{$relationType}]--> {$result['target']->canonical_name}"
                : "Edge reinforced: {$result['source']->canonical_name} --[{$relationType}]--> {$result['target']->canonical_name} (weight:{$result['edge']->weight})";

            if (!empty($result['autoCreated'])) {
                $msg .= ' (auto-created as Concept: ' . implode(', ', $result['autoCreated']) . ' — update class if needed)';
            }

            return [
                'success' => true,
                'message' => $msg,
                'edge'    => $result['edge'],
                'created' => $result['created'],
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::addEdge error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating edge: ' . $e->getMessage()];
        }
    }

    /**
     * Resolve a node by name (canonical or alias) within an active transaction,
     * creating it as a Concept if not found. Uses FOR UPDATE to prevent races.
     *
     * Must be called inside a DB transaction.
     *
     * @return array{node: OntologyNode, created: bool}
     */
    private function resolveOrCreateNode(AiPreset $preset, string $name): array
    {
        $normalized = mb_strtolower(trim($name));
        $presetId   = $preset->id;

        // 1. Try canonical_name with lock
        $node = $this->nodeModel
            ->forPreset($presetId)
            ->where('canonical_name', $normalized)
            ->lockForUpdate()
            ->first();

        if ($node) {
            return ['node' => $node, 'created' => false];
        }

        // 2. Try aliases with lock
        $node = $this->nodeModel
            ->forPreset($presetId)
            ->whereJsonContains('aliases', $normalized)
            ->lockForUpdate()
            ->first();

        if ($node) {
            return ['node' => $node, 'created' => false];
        }

        // 3. Create new (firstOrCreate handles the unique-index race if another
        //    transaction inserted between our SELECT and INSERT).
        $node = $this->nodeModel->firstOrCreate(
            [
                'preset_id'      => $preset->id,
                'canonical_name' => $normalized,
            ],
            [
                'class'   => 'Concept',
                'aliases' => null,
                'weight'  => 1.0,
            ]
        );

        // wasRecentlyCreated reflects whether INSERT actually happened
        return ['node' => $node, 'created' => $node->wasRecentlyCreated];
    }

    // -------------------------------------------------------------------------
    // Property operations
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Value resolution:
     *   - If value starts with "@" — treated as explicit node reference (the @ is stripped)
     *   - Otherwise — always stored as scalar
     *
     * This avoids the ambiguity where a scalar like "true" or "red" would
     * accidentally be linked to a node with that canonical_name.
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

            // Resolve value: node reference if prefixed with '@', otherwise scalar
            $valueNodeId = null;
            $valueScalar = null;
            $valueNode   = null;

            if (str_starts_with($value, self::NODE_REF_PREFIX)) {
                $referencedName = mb_substr($value, mb_strlen(self::NODE_REF_PREFIX));
                $valueNode = $this->findNode($preset, $referencedName);

                if (!$valueNode) {
                    return ['success' => false, 'message' => "Referenced node not found: \"{$referencedName}\"."];
                }
                $valueNodeId = $valueNode->id;
            } else {
                $valueScalar = $value;
            }

            $property = $this->db->transaction(function () use ($node, $key, $valueScalar, $valueNodeId) {

                $this->propertyModel
                    ->where('node_id', $node->id)
                    ->where('key', $key)
                    ->current()
                    ->update(['valid_until' => now()]);

                return $this->propertyModel->create([
                    'node_id'       => $node->id,
                    'key'           => $key,
                    'value_scalar'  => $valueScalar,
                    'value_node_id' => $valueNodeId,
                    'valid_from'    => now(),
                    'valid_until'   => null,
                ]);
            });

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

            // Reset ephemeral caches so snapshots always reflect current DB state.
            $visited = [];
            $snapshotNodeCache = [];
            $output = $this->buildSnapshot($preset, $root, $depth, $visited, $snapshotNodeCache);

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

            $sourceCanonicalName = $source->canonical_name;
            $presetId = (string) $preset->id;

            $this->logger->info("Merging node {$source->id} ({$sourceCanonicalName}) into {$target->id} ({$target->canonical_name})");

            $this->db->transaction(function () use ($source, $target, $preset) {
                // Re-point edges; deduplicate (source_id, target_id, relation_type, valid_until=null)
                // by summing weights into the existing target-side edge and deleting the moved one.
                $this->mergeEdges($preset, $source, $target);

                // Re-point properties referencing source as value_node_id
                $this->propertyModel
                    ->where('value_node_id', $source->id)
                    ->update(['value_node_id' => $target->id]);

                // Re-point all properties owned by source to target
                $this->propertyModel
                    ->where('node_id', $source->id)
                    ->update(['node_id' => $target->id]);

                // Merge aliases into target (include source's canonical_name as alias)
                $mergedAliases = array_values(array_unique(array_merge(
                    $target->aliases ?? [],
                    $source->aliases ?? [],
                    [$source->canonical_name],
                )));
                $target->update([
                    'aliases' => $mergedAliases,
                    'weight'  => $target->weight + $source->weight,
                ]);

                $source->delete();
            });

            $this->flushAllCaches();
            $this->cacheNodeWithAliases($presetId, $target->fresh());

            return [
                'success' => true,
                'message' => "Merged \"{$sourceCanonicalName}\" into \"{$target->canonical_name}\". Source node deleted, all edges and properties transferred.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('OntologyService::mergeNodes error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error merging nodes: ' . $e->getMessage()];
        }
    }

    /**
     * Re-point all edges from $source to $target, deduplicating current edges
     * by summing weights when a parallel edge already exists on $target.
     *
     * Must be called inside a DB transaction.
     */
    private function mergeEdges(AiPreset $preset, OntologyNode $source, OntologyNode $target): void
    {
        // Outgoing edges: source -> X  becomes  target -> X
        $outgoing = $this->edgeModel
            ->forPreset($preset->id)
            ->where('source_id', $source->id)
            ->get();

        foreach ($outgoing as $edge) {
            // Skip edges that would become self-loops post-merge
            if ($edge->target_id === $target->id) {
                $edge->delete();
                continue;
            }

            // Only dedupe currently-valid edges; historical edges are preserved as-is.
            if ($edge->valid_until === null) {
                $duplicate = $this->edgeModel
                    ->forPreset($preset->id)
                    ->where('source_id', $target->id)
                    ->where('target_id', $edge->target_id)
                    ->where('relation_type', $edge->relation_type)
                    ->current()
                    ->lockForUpdate()
                    ->first();

                if ($duplicate) {
                    $duplicate->increment('weight', $edge->weight);
                    $edge->delete();
                    continue;
                }
            }

            $edge->update(['source_id' => $target->id]);
        }

        // Incoming edges: X -> source  becomes  X -> target
        $incoming = $this->edgeModel
            ->forPreset($preset->id)
            ->where('target_id', $source->id)
            ->get();

        foreach ($incoming as $edge) {
            if ($edge->source_id === $target->id) {
                $edge->delete();
                continue;
            }

            if ($edge->valid_until === null) {
                $duplicate = $this->edgeModel
                    ->forPreset($preset->id)
                    ->where('source_id', $edge->source_id)
                    ->where('target_id', $target->id)
                    ->where('relation_type', $edge->relation_type)
                    ->current()
                    ->lockForUpdate()
                    ->first();

                if ($duplicate) {
                    $duplicate->increment('weight', $edge->weight);
                    $edge->delete();
                    continue;
                }
            }

            $edge->update(['target_id' => $target->id]);
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

            $this->db->transaction(function () use ($preset, $node, $now) {
                // Close all current edges where node is source or target
                $this->edgeModel
                    ->forPreset($preset->id)
                    ->where(fn ($q) => $q->where('source_id', $node->id)->orWhere('target_id', $node->id))
                    ->current()
                    ->update(['valid_until' => $now]);

                // Close all current properties owned by this node
                $this->propertyModel
                    ->where('node_id', $node->id)
                    ->current()
                    ->update(['valid_until' => $now]);

                // Close all current properties on OTHER nodes that reference this node as value
                $this->propertyModel
                    ->where('value_node_id', $node->id)
                    ->current()
                    ->update(['valid_until' => $now]);
            });

            $this->flushAllCaches();

            return [
                'success' => true,
                'message' => "Node \"{$node->canonical_name}\" closed. All current edges and properties (incoming and outgoing) invalidated. History preserved.",
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
     *   1. Load all canonical_names + aliases for this preset in one query (indexed).
     *   2. Word-boundary match for both canonical_names AND aliases (no false positives).
     */
    public function findMentionedNodes(AiPreset $preset, string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $this->logger->warning('findMentionedNodes: Invalid UTF-8 in text');
            return [];
        }

        $textLower = mb_strtolower($text);

        $rows = $this->nodeModel
            ->forPreset($preset->id)
            ->select(['id', 'canonical_name', 'aliases'])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $matchedIds = [];

        foreach ($rows as $row) {
            if ($this->textContainsTerm($textLower, $row->canonical_name)) {
                $matchedIds[] = $row->id;
                continue;
            }

            foreach ($row->aliases ?? [] as $alias) {
                // Use the same word-boundary check as canonical_name to avoid
                // false positives like "ai" matching inside "hair".
                if ($this->textContainsTerm($textLower, $alias)) {
                    $matchedIds[] = $row->id;
                    break;
                }
            }
        }

        if (empty($matchedIds)) {
            return [];
        }

        return $this->nodeModel
            ->whereIn('id', $matchedIds)
            ->get()
            ->all();
    }

    /**
     * Case-insensitive check whether $text contains $term as a meaningful token.
     * Avoids false positives like "trust" matching "trustworthy".
     */
    private function textContainsTerm(string $textLower, string $term): bool
    {
        $term = mb_strtolower(trim($term));

        if (empty($term) || mb_strlen($term) < 2) {
            return false;
        }

        $termNormalized = str_replace('_', ' ', $term);

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
            $nodeIds = $this->nodeModel
                ->forPreset($preset->id)
                ->pluck('id')
                ->all();

            $nodeCount = count($nodeIds);

            if ($nodeCount === 0) {
                return ['success' => true, 'message' => 'Ontology is already empty.'];
            }

            $this->db->transaction(function () use ($preset, $nodeIds) {
                $this->propertyModel
                    ->whereIn('node_id', $nodeIds)
                    ->delete();

                $this->edgeModel
                    ->forPreset($preset->id)
                    ->delete();

                $this->nodeModel
                    ->forPreset($preset->id)
                    ->delete();
            });

            $this->flushAllCaches();

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
     * The $snapshotNodeCache is local to a single getSnapshot() call so we never
     * serve stale data across snapshots.
     */
    private function buildSnapshot(
        AiPreset     $preset,
        OntologyNode $node,
        int          $depth,
        array        &$visited,
        array        &$snapshotNodeCache,
        int          $currentDepth = 0,
        string       $indent       = '',
    ): string {
        if ($currentDepth >= $depth) {
            return $indent . "[{$node->class}] {$node->canonical_name} (max depth reached)\n";
        }

        if (in_array($node->id, $visited, true)) {
            return $indent . "[{$node->class}] {$node->canonical_name} (already expanded)\n";
        }

        $visited[] = $node->id;

        $aliases = !empty($node->aliases) ? ' (' . implode(', ', $node->aliases) . ')' : '';
        $lines   = ["{$indent}[{$node->class}] {$node->canonical_name}{$aliases} weight:{$node->weight}"];

        $properties = $this->propertyModel
            ->where('node_id', $node->id)
            ->current()
            ->get();

        foreach ($properties as $prop) {
            if ($prop->value_node_id) {
                $cacheKey = $prop->value_node_id;
                if (!isset($snapshotNodeCache[$cacheKey])) {
                    $snapshotNodeCache[$cacheKey] = $this->nodeModel->find($prop->value_node_id);
                }

                $valueNode = $snapshotNodeCache[$cacheKey];
                $val       = $valueNode ? "[{$valueNode->class}] {$valueNode->canonical_name}" : "node:{$prop->value_node_id}";
            } else {
                $val = $prop->value_scalar;
            }
            $lines[] = "{$indent}  .{$prop->key} = {$val}";
        }

        if ($depth > $currentDepth) {
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
                    $lines[] = $this->buildSnapshot($preset, $edge->target, $depth, $visited, $snapshotNodeCache, $currentDepth + 1, $indent . '    ');
                }
            }

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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize an aliases array: trim, lowercase, dedupe, drop empties.
     *
     * @param  array<int, string> $aliases
     * @return array<int, string>
     */
    private function normalizeAliases(array $aliases): array
    {
        $normalized = [];
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $clean = mb_strtolower(trim($alias));
            if ($clean !== '') {
                $normalized[$clean] = true;
            }
        }
        return array_keys($normalized);
    }

    // -------------------------------------------------------------------------
    // Cache helpers
    // -------------------------------------------------------------------------

    /**
     * Cache a node by all its lookup keys (canonical_name + all aliases).
     *
     * Also clears any matching entries from the negative-result (missed) cache,
     * so a name that was previously a miss becomes findable as soon as the
     * underlying node exists or its aliases are updated.
     *
     * Bounded to NODE_CACHE_MAX entries per preset to prevent unbounded growth
     * in long-lived processes (queue workers, Octane).
     */
    private function cacheNodeWithAliases(string $presetId, OntologyNode $node): void
    {
        if (!isset($this->nodeCache[$presetId])) {
            $this->nodeCache[$presetId] = [];
        }

        // If we're at the cap, drop the oldest half (cheap LRU-ish behavior).
        if (count($this->nodeCache[$presetId]) >= self::NODE_CACHE_MAX) {
            $this->nodeCache[$presetId] = array_slice(
                $this->nodeCache[$presetId],
                (int) (self::NODE_CACHE_MAX / 2),
                null,
                true
            );
        }

        $this->nodeCache[$presetId][$node->canonical_name] = $node;

        // Drop any stale "miss" entry for canonical_name
        unset($this->missedCache[$presetId][$node->canonical_name]);

        foreach ($node->aliases ?? [] as $alias) {
            $aliasLower = mb_strtolower($alias);
            $this->nodeCache[$presetId][$aliasLower] = $node;
            // Drop stale miss entries for aliases too
            unset($this->missedCache[$presetId][$aliasLower]);
        }
    }

    /**
     * Invalidate all cached data for a specific preset.
     */
    private function invalidatePresetCache(string $presetId): void
    {
        unset($this->nodeCache[$presetId]);
        unset($this->missedCache[$presetId]);

        $prefix = $presetId . ':';
        foreach (array_keys($this->nodeIdCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->nodeIdCache[$key]);
            }
        }
    }

    /**
     * Complete cache flush for major structural changes.
     * Use this when the entire graph topology changes (merge, close, clear).
     */
    private function flushAllCaches(): void
    {
        $this->nodeCache = [];
        $this->nodeIdCache = [];
        $this->missedCache = [];
    }
}

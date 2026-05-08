<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * OntologyPlugin — agent world-model graph.
 *
 * Stores structured, temporal knowledge about entities and their relationships.
 * Unlike vectormemory (associative recall of episodes) and journal (what happened),
 * the ontology answers "what IS" — facts about people, places, concepts,
 * and how they relate to each other over time.
 *
 * Naming contract (enforced in tool description):
 *   canonical_name — one lowercase English noun, snake_case if compound
 *   class          — one English noun, PascalCase: Person, Place, Concept, Emotion, Event, Principle
 *   relation_type  — snake_case verb phrase: lives_in, defines, weakens, part_of
 *   property key   — snake_case English noun: surname, birth_year, location
 *
 * Commands (tag mode):
 *   [ontology add_node]name | class[/ontology]
 *   [ontology add_node]name | class | alias1, alias2[/ontology]
 *   [ontology add_edge]source | relation | target[/ontology]
 *   [ontology add_edge]source | relation | target | 2020-01-01[/ontology]
 *   [ontology set_property]node | key | value[/ontology]
 *   [ontology snapshot]node[/ontology]
 *   [ontology snapshot]node | 2[/ontology]
 *   [ontology find]name[/ontology]
 *   [ontology merge]source | target[/ontology]
 *   [ontology close]node[/ontology]
 */
class OntologyPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected OntologyServiceInterface $ontologyService,
        protected LoggerInterface          $logger,
    ) {
    }

    public function getName(): string
    {
        return 'ontology';
    }

    public function getDescription(array $config = []): string
    {
        return 'World-model graph. Stores structured, temporal knowledge about entities (people, places, concepts, emotions) '
            . 'and their relationships. Use for facts that endure over time — not episodic events (use journal) '
            . 'or associative insights (use vectormemory).';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            '--- NAMING CONTRACT ---',
            'canonical_name: one lowercase English noun, snake_case if compound. Examples: eugeny, trust, kharkiv, home_city',
            'class: one English noun, PascalCase. Recommended: Person, Place, Concept, Emotion, Event, Object, Principle, Value, Goal',
            'relation_type: snake_case verb phrase. Examples: lives_in, has_surname, defines, weakens, part_of, causes, contradicts',
            'property key: snake_case English noun. Examples: surname, birth_year, current_city, native_language',
            'ALWAYS search for a node before adding — use [ontology find] to avoid duplicates.',
            '',
            '--- COMMANDS ---',
            'Find node:        [ontology find]eugeny[/ontology]',
            'Add node:         [ontology add_node]trust | Concept[/ontology]',
            'Add with aliases: [ontology add_node]eugeny | Person | Женя, Евгений, Eugeny Gazzaev[/ontology]',
            'Add edge:         [ontology add_edge]eugeny | lives_in | kharkiv[/ontology]',
            'Add edge + date:  [ontology add_edge]sergey | lives_in | kyiv | 2020-03-01[/ontology]',
            'Set property:     [ontology set_property]eugeny | occupation | software_engineer[/ontology]',
            'Set node-ref prop:[ontology set_property]eugeny | current_city | kharkiv[/ontology]',
            'Snapshot depth 1: [ontology snapshot]eugeny[/ontology]',
            'Snapshot depth 2: [ontology snapshot]eugeny | 2[/ontology]',
            'Merge nodes:      [ontology merge]женя | eugeny[/ontology]',
            'Close node:       [ontology close]old_project[/ontology]',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'ontology',
            'description' => 'World-model graph for structured temporal knowledge. '
                . 'Stores entities (nodes) and their relationships (edges) with full history. '
                . 'Use to record facts about people, places, concepts and how they relate over time. '
                . 'NAMING RULES: canonical_name must be one lowercase English noun (snake_case if compound). '
                . 'class must be PascalCase English noun: Person, Place, Concept, Emotion, Event, Object, Principle, Value, Goal. '
                . 'relation_type must be snake_case verb phrase: lives_in, has_surname, defines, weakens, part_of. '
                . 'ALWAYS call find before add_node to avoid duplicates.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['find', 'add_node', 'add_edge', 'set_property', 'snapshot', 'merge', 'close'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Pipe-separated arguments. Depends on method:',
                            'find: "name" — search by canonical name or alias.',
                            'add_node: "canonical_name | Class" or "canonical_name | Class | alias1, alias2".',
                            'add_edge: "source | relation_type | target" or "source | relation_type | target | YYYY-MM-DD".',
                            'set_property: "node | key | value". Value can be a node name (creates a node-ref) or plain text.',
                            'snapshot: "node" or "node | depth" where depth is 1-3 (default 1).',
                            'merge: "source | target" — merges source into target, source is deleted.',
                            'close: "node" — closes all current edges and properties for the node.',
                        ]),
                    ],
                ],
                'required' => ['method', 'content'],
            ],
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Ontology Plugin',
                'description' => 'World-model graph — structured temporal knowledge about entities and relationships',
                'required'    => false,
            ],
            'snapshot_depth' => [
                'type'        => 'number',
                'label'       => 'Default snapshot depth',
                'description' => 'How many hops to expand by default (1–3)',
                'min'         => 1,
                'max'         => 3,
                'value'       => 1,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['snapshot_depth'])) {
            $d = (int) $config['snapshot_depth'];
            if ($d < 1 || $d > 3) {
                $errors['snapshot_depth'] = 'Depth must be between 1 and 3.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'        => false,
            'snapshot_depth' => 1,
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — alias for find (safe read-only default).
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->find($content, $context);
    }

    /**
     * [ontology find]name[/ontology]
     */
    public function find(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $name = trim($content);
        if (empty($name)) {
            return 'Error: Provide a node name to search.';
        }

        $node = $this->ontologyService->findNode($context->preset, $name);

        if (!$node) {
            return "Node not found: \"{$name}\". You can add it with add_node.";
        }

        // Return a depth-1 snapshot
        $result = $this->ontologyService->getSnapshot($context->preset, [
            'node'  => $node->canonical_name,
            'depth' => 1,
        ]);

        return $result['message'];
    }

    /**
     * [ontology add_node]name | Class | alias1, alias2[/ontology]
     */
    public function add_node(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $parts   = array_map('trim', explode('|', $content));
        $name    = $parts[0] ?? '';
        $class   = $parts[1] ?? 'Concept';
        $aliases = isset($parts[2])
            ? array_filter(array_map('trim', explode(',', $parts[2])))
            : [];

        if (empty($name)) {
            return 'Error: node name is required. Format: name | Class';
        }

        $result = $this->ontologyService->addNode($context->preset, [
            'name'    => $name,
            'class'   => $class,
            'aliases' => array_values($aliases),
        ]);

        return $result['message'];
    }

    /**
     * [ontology add_edge]source | relation | target[/ontology]
     * [ontology add_edge]source | relation | target | 2020-01-01[/ontology]
     */
    public function add_edge(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $parts = array_map('trim', explode('|', $content));

        if (count($parts) < 3) {
            return 'Error: Format: source | relation_type | target [| YYYY-MM-DD]';
        }

        $params = [
            'source'   => $parts[0],
            'relation' => $parts[1],
            'target'   => $parts[2],
        ];

        if (isset($parts[3]) && !empty($parts[3])) {
            $params['valid_from'] = $parts[3];
        }

        $result = $this->ontologyService->addEdge($context->preset, $params);
        return $result['message'];
    }

    /**
     * [ontology set_property]node | key | value[/ontology]
     */
    public function set_property(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $parts = array_map('trim', explode('|', $content));

        if (count($parts) < 3) {
            return 'Error: Format: node | key | value';
        }

        $result = $this->ontologyService->setProperty($context->preset, [
            'node'  => $parts[0],
            'key'   => $parts[1],
            'value' => $parts[2],
        ]);

        return $result['message'];
    }

    /**
     * [ontology snapshot]node[/ontology]
     * [ontology snapshot]node | 2[/ontology]
     */
    public function snapshot(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $parts    = array_map('trim', explode('|', $content));
        $nodeName = $parts[0] ?? '';
        $depth    = isset($parts[1]) ? (int) $parts[1] : $context->get('snapshot_depth', 1);

        if (empty($nodeName)) {
            return 'Error: Provide a node name. Format: node [| depth]';
        }

        $result = $this->ontologyService->getSnapshot($context->preset, [
            'node'  => $nodeName,
            'depth' => $depth,
        ]);

        return $result['message'];
    }

    /**
     * [ontology merge]source | target[/ontology]
     */
    public function merge(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $parts = array_map('trim', explode('|', $content));

        if (count($parts) < 2) {
            return 'Error: Format: source_node | target_node';
        }

        $result = $this->ontologyService->mergeNodes($context->preset, [
            'source' => $parts[0],
            'target' => $parts[1],
        ]);

        return $result['message'];
    }

    /**
     * [ontology close]node[/ontology]
     */
    public function close(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Ontology plugin is disabled.';
        }

        $name = trim($content);
        if (empty($name)) {
            return 'Error: Provide a node name.';
        }

        $result = $this->ontologyService->closeNode($context->preset, $name);
        return $result['message'];
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
    // -------------------------------------------------------------------------

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return [];
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Ontology doesn't inject into context automatically
    }
}

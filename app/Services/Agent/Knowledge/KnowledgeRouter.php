<?php

namespace App\Services\Agent\Knowledge;

use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * KnowledgeRouter — the deterministic half of the facade.
 *
 * WRITE (remember/relate/forget): one nature → exactly one home. No LLM, no
 * guessing. A validated DSL op comes in, a single service call goes out. This
 * is the "one fact, one home" rule made literal — the router never fans a write
 * across subsystems (that would breed the duplicates recall then has to squash).
 *
 * READ (recall): the opposite shape. No nature, no single home — recall fans
 * out across all subsystems' *search primitives* and merges. Critically it calls
 * the services DIRECTLY (searchEntries, searchFacts, searchItems,
 * findMentionedNodes+getSnapshot, searchVectorMemories) and NOT the RAG
 * enricher — RAG is passive/config-driven and may be disabled entirely; recall
 * is active and must work regardless.
 *
 * The router speaks in the services' own return shape (['success','message',...])
 * and hands raw message strings back to the plugin, which decides how to unify
 * them. The router does not format for the model — it routes.
 *
 * Everything here is preset-scoped: $preset is the memory owner. For cross-preset
 * (memory-optimizer / shared-memory) execution, the CALLER passes the effective
 * preset — the router is agnostic to whose memory it is.
 */
class KnowledgeRouter
{
    public function __construct(
        protected JournalServiceInterface       $journalService,
        protected PersonMemoryServiceInterface  $personMemoryService,
        protected OntologyServiceInterface      $ontologyService,
        protected SkillServiceInterface         $skillService,
        protected VectorMemoryFactoryInterface  $vectorMemoryFactory,
        protected LoggerInterface               $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  WRITE — deterministic nature → home
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dispatch a validated DSL op to its single home.
     *
     * @param  array<string,mixed> $dsl   Output of KnowledgeDsl::validate (ok=true)
     * @param  array<string,mixed> $config Plugin config (carries vector engine/mode, domain, etc.)
     * @return array{success: bool, message: string}
     */
    public function dispatchWrite(AiPreset $preset, array $dsl, array $config = []): array
    {
        $op     = $dsl['op'];
        $nature = $dsl['nature'];
        $args   = $dsl['args'];

        try {
            return match ($op) {
                KnowledgeDsl::OP_REMEMBER => $this->remember($preset, $nature, $args, $config),
                KnowledgeDsl::OP_RELATE   => $this->relate($preset, $args),
                KnowledgeDsl::OP_FORGET   => $this->forget($preset, $nature, $args, $config),
                default                   => $this->err("Unroutable op: {$op}"),
            };
        } catch (\Throwable $e) {
            $this->logger->error('KnowledgeRouter::dispatchWrite error: ' . $e->getMessage(), [
                'preset_id' => $preset->getId(),
                'op'        => $op,
                'nature'    => $nature,
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->err('Knowledge write failed: ' . $e->getMessage());
        }
    }

    /**
     * The write routing table. One nature, one home. This is the whole of the
     * "facade" on write — five arms, each a single existing service call.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $config
     * @return array{success: bool, message: string}
     */
    private function remember(AiPreset $preset, string $nature, array $args, array $config): array
    {
        return match ($nature) {

            // Episode → journal. Service takes the pipe format "type | summary | details | outcome:..".
            // We rebuild it from structured args so the model never writes pipes.
            KnowledgeDsl::NATURE_EPISODE => $this->normalize(
                $this->journalService->addEntry(
                    $preset,
                    $this->composeJournalContent($args)
                )
            ),

            // Person fact → person. Service takes (preset, personName, fact).
            KnowledgeDsl::NATURE_PERSON => $this->normalize(
                $this->personMemoryService->addFact(
                    $preset,
                    (string) ($args['person'] ?? ''),
                    (string) ($args['content'] ?? '')
                )
            ),

            // Durable structured fact about an entity → ontology as a node
            // (+ optional property). A bare "remember relation-nature fact" that
            // isn't an edge lands as a node with a property; true edges use OP_RELATE.
            KnowledgeDsl::NATURE_RELATION => $this->rememberOntologyNode($preset, $args),

            // Reusable knowledge → skill. addSkill creates a Skill (title only,
            // NOT vectorized) plus an optional first ITEM (which IS vectorized
            // and is the only thing searchItems can find). So a skill with a
            // title but no item is invisible to recall. Guarantee a searchable
            // item: use content as the item; if only a title was given, seed the
            // item with the title so the skill is findable.
            KnowledgeDsl::NATURE_SKILL => $this->normalize(
                $this->rememberSkill($preset, $args)
            ),

            // Crystallized note → vectormemory. Engine/mode/domain come from config.
            KnowledgeDsl::NATURE_NOTE => $this->normalize(
                $this->vectorService($config)->storeVectorMemory(
                    $preset,
                    (string) ($args['content'] ?? ''),
                    $this->vectorStoreConfig($args, $config)
                )
            ),

            default => $this->err("No home for nature: {$nature}"),
        };
    }

    /**
     * relate → ontology edge. Always a graph relation by definition.
     * args: { source, relation, target, valid_from? }
     *
     * @param array<string,mixed> $args
     * @return array{success: bool, message: string}
     */
    private function relate(AiPreset $preset, array $args): array
    {
        $params = [
            'source'   => (string) ($args['source'] ?? ''),
            'relation' => (string) ($args['relation'] ?? ''),
            'target'   => (string) ($args['target'] ?? ''),
        ];

        if (!empty($args['valid_from'])) {
            $params['valid_from'] = (string) $args['valid_from'];
        }

        return $this->normalize($this->ontologyService->addEdge($preset, $params));
    }

    /**
     * forget — phase 1: vectormemory only. Person/ontology forgetting exists in
     * their services (forgetPerson, closeNode) but routing it needs entity
     * resolution we deliberately deferred. Any other nature returns a soft
     * "not supported yet" so the model learns the boundary instead of erroring.
     *
     * args: { id? , content? } for note; anything else deferred.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $config
     * @return array{success: bool, message: string}
     */
    private function forget(AiPreset $preset, string $nature, array $args, array $config): array
    {
        if ($nature !== KnowledgeDsl::NATURE_NOTE) {
            return $this->err(
                "Forgetting is currently supported only for notes (semantic memory). "
                . "To remove a person, relation, skill or episode, use its own tool for now."
            );
        }

        $service = $this->vectorService($config);
        $id      = $args['id'] ?? null;

        if ($id !== null && is_numeric($id)) {
            return $this->normalize($service->deleteVectorMemory($preset, (int) $id));
        }

        // Content-based: find the single best match, delete it. Mirrors the
        // existing VectorMemoryPlugin::delete convenience path.
        $content = trim((string) ($args['content'] ?? ''));
        if ($content === '') {
            return $this->err('forget note needs an "id" or "content" to match.');
        }

        $search = $service->searchVectorMemories($preset, $content, [
            'search_limit'         => 1,
            'similarity_threshold' => 0.3,
        ]);

        if (empty($search['success']) || empty($search['results'])) {
            return $this->err("No note found matching \"{$content}\".");
        }

        $memory = $search['results'][0]['memory'] ?? ($search['results'][0]['document'] ?? null);
        if ($memory === null) {
            return $this->err('Match found but could not resolve its id.');
        }

        return $this->normalize($service->deleteVectorMemory($preset, $memory->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  READ — fan-out recall, direct to service search primitives (NOT RAG)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recall across all subsystems for a single query and return a merged,
     * deliberately-lean result. Called possibly several times per cycle, so it
     * stays terse: a few lines per source, not a dump.
     *
     * Dedup is WITHIN a single recall only — one call won't list the same item
     * twice across its own sources. It is deliberately NOT carried across calls:
     * a later recall in the same cycle is a different, intentional query (e.g. a
     * narrowed re-search) and must answer fully on its own, not be silenced by
     * what an earlier recall already surfaced.
     *
     * The query keeps its inline "time:/domain:/pulse:" filters verbatim — the
     * services parse those themselves. Recall must not be poorer than the old
     * per-plugin search, so we pass the raw query straight through.
     *
     * $natures is an OPTIONAL narrowing of the fan-out (Ada's request): when
     * empty, recall hits every source (the default, unchanged). When it lists
     * one or more natures, only those sources are queried — so "give me episodes
     * and notes, not the rest" costs nothing extra and cuts token/noise. This
     * does NOT reintroduce read-routing: nature on recall is optional and the
     * default stays wide; it only lets the caller skip sources it knows it
     * doesn't want.
     *
     * @param  string[] $natures   Optional subset of KnowledgeDsl::NATURES.
     * @param  array<string,mixed> $config
     * @return string  Lean, unified recall block (already model-facing text).
     */
    public function recall(
        AiPreset $preset,
        string $query,
        array $natures = [],
        array $config = []
    ): string {
        $query = trim($query);
        // Dedup is WITHIN a single recall (so one call doesn't repeat an item
        // across its own sources) — NOT across calls. A later recall in the same
        // cycle is a deliberate, different query by the agent (e.g. a narrowed
        // re-search) and must answer fully on its own data, not be silenced by
        // what an earlier recall already surfaced.
        $seen = [];
        if ($query === '') {
            return 'Recall needs a query.';
        }

        $perSource = (int) ($config['recall_per_source'] ?? 3);
        $blocks    = [];

        // Which sources to hit. Empty $natures → all. Each nature maps to the
        // one source that owns it, symmetric with the write table.
        $want = $this->sourcesFor($natures);

        // ── notes (vector memory) ────────────────────────────────────────────
        if (isset($want['notes'])) {
            $vm = $this->safe(fn () => $this->vectorService($config)->searchVectorMemories(
                $preset,
                $query,
                ['search_limit' => $perSource] + $config
            ));
            foreach ($this->extractVectorLines($vm, $seen) as $line) {
                $blocks['notes'][] = $line;
            }
        }

        // ── episodes (journal) ───────────────────────────────────────────────
        if (isset($want['episodes'])) {
            $entries = $this->safe(fn () => $this->journalService->searchEntries($preset, $query, $perSource)) ?? [];
            $first   = true;
            foreach ($entries as $entry) {
                $key = 'journal:' . $entry->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                // Show the summary, plus details when present, plus a compact
                // [type/outcome] tag — so the agent can confirm structured fields
                // actually persisted, not just the summary. Details are clipped
                // to keep recall lean when called several times per cycle.
                $line = $this->clip((string) ($entry->summary ?? ''));

                $meta = array_filter([
                    $entry->type ?? null,
                    $entry->outcome ?? null,
                ]);
                if (!empty($meta)) {
                    $line .= ' [' . implode('/', $meta) . ']';
                }

                $details = trim((string) ($entry->details ?? ''));
                if ($details !== '') {
                    $line .= ' — ' . $this->clip($details, 140);
                }

                // searchEntries returns semantic matches ranked first, then a
                // few recent entries blended into the tail — WITHOUT scores, so
                // we can't label each line's relevance. What we CAN state
                // honestly: the first result is the strongest match. Marking it
                // lets the agent trust the head and treat the tail as possibly
                // recency-blended, instead of inferring that by observation.
                $prefix = $first ? '★ ' : '• ';
                $first  = false;

                $blocks['episodes'][] = $prefix . $line;
            }
        }

        // ── people (person facts) ────────────────────────────────────────────
        // PersonMemoryService::searchFacts returns a formatted message; we take
        // it whole rather than re-parsing — it's already lean.
        if (isset($want['people'])) {
            $people = $this->safe(fn () => $this->personMemoryService->searchFacts($preset, $query, $perSource));
            if (!empty($people['success']) && !empty(trim((string) ($people['message'] ?? '')))) {
                $blocks['people'][] = trim((string) $people['message']);
            }
        }

        // ── skills ───────────────────────────────────────────────────────────
        if (isset($want['skills'])) {
            $skills = $this->safe(fn () => $this->skillService->searchItems($preset, $query, $perSource));
            if (!empty($skills['success']) && !empty(trim((string) ($skills['message'] ?? '')))) {
                $blocks['skills'][] = trim((string) $skills['message']);
            }
        }

        // ── relations (ontology) ─────────────────────────────────────────────
        // Ontology has no free-text semantic search — it matches mentioned
        // nodes. We feed the query text and snapshot any node it names.
        if (isset($want['relations'])) {
            $nodes = $this->safe(fn () => $this->ontologyService->findMentionedNodes($preset, $query)) ?? [];
            foreach ($nodes as $node) {
                $key = 'ontology:' . $node->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $snap = $this->safe(fn () => $this->ontologyService->getSnapshot($preset, [
                    'node'  => $node->canonical_name,
                    'depth' => 1,
                ]));
                if (!empty($snap['success'])) {
                    $blocks['relations'][] = trim((string) $snap['message']);
                }
            }
        }

        return $this->composeRecall($blocks, $query, $natures);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * remember skill → a Skill plus at least one searchable item.
     *
     * Why the helper: addSkill vectorizes only ITEMS, and searchItems finds only
     * items. A skill with a title but no item is invisible to recall. So we
     * always seed an item:
     *   - title = args.title, or the content/first line if no title given
     *   - item  = args.content, or the title when no separate content exists
     * That way "remember a skill called X" (title only) still lands a findable
     * item carrying X.
     *
     * @param array<string,mixed> $args
     * @return array{success: bool, message: string}
     */
    private function rememberSkill(AiPreset $preset, array $args): array
    {
        $title   = trim((string) ($args['title'] ?? ''));
        $content = trim((string) ($args['content'] ?? ''));

        // If no explicit title, derive one from the content's first line.
        if ($title === '' && $content !== '') {
            $title = mb_substr(trim(explode("\n", $content)[0]), 0, 80);
        }
        if ($title === '') {
            return $this->err('skill remember needs a title or content.');
        }

        // Guarantee a searchable item: prefer explicit content; otherwise seed
        // the item with the title so the skill isn't invisible to searchItems.
        $firstItem = $content !== '' ? $content : $title;

        return $this->skillService->addSkill(
            $preset,
            $title,
            isset($args['description']) ? (string) $args['description'] : null,
            $firstItem
        );
    }

    /**
     * Build the pipe-format journal content the service expects, from args.
     * args: { type?, summary|content, details?, outcome? }
     *
     * @param array<string,mixed> $args
     */
    private function composeJournalContent(array $args): string
    {
        // Content-first: the model may put the main text in `content` (as it does
        // for note/person) OR in `summary`. Accept either, preferring an explicit
        // summary. `type`/`details`/`outcome` are optional refinements — if the
        // model didn't set a type, don't let a stray word become it; default it.
        $summary = trim((string) ($args['summary'] ?? ($args['content'] ?? '')));
        $details = trim((string) ($args['details'] ?? ''));
        $outcome = trim((string) ($args['outcome'] ?? ''));

        // type defaults to 'observation'. Guard against the model stuffing the
        // whole body into `type` (a past failure mode): if type is long, it's
        // really the body — treat it as summary and reset type.
        $type = trim((string) ($args['type'] ?? 'observation'));
        if ($summary === '' && $type !== '' && mb_strlen($type) > 40) {
            $summary = $type;
            $type    = 'observation';
        }
        if ($type === '') {
            $type = 'observation';
        }

        // addEntry parses on " | " — any literal pipe inside a value would
        // corrupt the split (summary bleeding into details, etc). Neutralize
        // pipes in the values so the format stays well-formed regardless of
        // what the model wrote.
        $clean = static fn (string $s): string => trim(str_replace('|', '/', $s));

        $type    = $clean($type);
        $summary = $clean($summary);
        $details = $clean($details);
        $outcome = $clean($outcome);

        // Guarantee a non-empty summary so an entry is never just its type.
        if ($summary === '') {
            $summary = '(no summary)';
        }

        $parts = [$type, $summary];
        if ($details !== '') {
            $parts[] = $details;
        }
        if ($outcome !== '') {
            $parts[] = 'outcome:' . $outcome;
        }

        return implode(' | ', $parts);
    }

    /**
     * remember relation-nature but not an edge → an ontology node, optionally
     * with one property. args: { name, class?, aliases?[], key?, value? }
     *
     * @param array<string,mixed> $args
     * @return array{success: bool, message: string}
     */
    private function rememberOntologyNode(AiPreset $preset, array $args): array
    {
        // content-first: the entity name may come as `content` (the unified main
        // field) or as an explicit `name`. Prefer an explicit name.
        $name = trim((string) ($args['name'] ?? ($args['content'] ?? '')));
        if ($name === '') {
            return $this->err('relation/entity remember needs a "name".');
        }

        $nodeResult = $this->ontologyService->addNode($preset, [
            'name'    => $name,
            'class'   => (string) ($args['class'] ?? 'Concept'),
            'aliases' => array_values(array_filter((array) ($args['aliases'] ?? []))),
        ]);

        // Optional single property in the same intent.
        if (!empty($args['key']) && isset($args['value'])) {
            $propResult = $this->ontologyService->setProperty($preset, [
                'node'  => $name,
                'key'   => (string) $args['key'],
                'value' => (string) $args['value'],
            ]);
            // Prefer the property message if the node already existed; combine lightly.
            $msg = trim(($nodeResult['message'] ?? '') . ' ' . ($propResult['message'] ?? ''));
            return ['success' => (bool) ($propResult['success'] ?? true), 'message' => $msg];
        }

        return $this->normalize($nodeResult);
    }

    /**
     * Resolve the vector memory service honoring the preset's configured
     * mode/engine — this is where embeddings "just work": if the preset is set
     * to ENGINE_EMBEDDING the factory returns the embedding-backed service (and
     * falls back to tfidf itself when no capability is configured).
     *
     * @param array<string,mixed> $config
     */
    private function vectorService(array $config): \App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface
    {
        $mode   = $config['memory_mode']   ?? VectorMemoryFactoryInterface::MODE_FLAT;
        $engine = $config['memory_engine'] ?? VectorMemoryFactoryInterface::ENGINE_TFIDF;

        return $this->vectorMemoryFactory->make($mode, $engine);
    }

    /**
     * Build the store config for a note write: carry the plugin config, but let
     * an explicit args.domain override the default domain for this record.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function vectorStoreConfig(array $args, array $config): array
    {
        if (!empty($args['domain'])) {
            $config['domain'] = (string) $args['domain'];
        }
        return $config;
    }

    /**
     * Pull a few lean lines out of a vectormemory search result, respecting the
     * cross-call dedup set.
     *
     * @param array<string,mixed>|null $vm
     * @param array<string,true> $seen
     * @return string[]
     */
    private function extractVectorLines(?array $vm, array &$seen): array
    {
        if (empty($vm['success']) || empty($vm['results'])) {
            return [];
        }

        $lines = [];
        foreach ($vm['results'] as $r) {
            $memory = $r['document'] ?? $r['memory'] ?? null;
            if ($memory === null) {
                continue;
            }
            $key = 'vm:' . $memory->id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = '• ' . $this->clip($memory->getTextContent());
        }
        return $lines;
    }

    /**
     * Map an optional list of natures to the set of recall sources to query.
     * Empty → every source (default wide recall). Otherwise only the sources
     * owning the requested natures, symmetric with the write routing table.
     *
     * @param  string[] $natures
     * @return array<string,true>  Source keys to hit, as a set.
     */
    private function sourcesFor(array $natures): array
    {
        // nature → the single source that owns it (same mapping as writes).
        $natureToSource = [
            KnowledgeDsl::NATURE_NOTE     => 'notes',
            KnowledgeDsl::NATURE_EPISODE  => 'episodes',
            KnowledgeDsl::NATURE_PERSON   => 'people',
            KnowledgeDsl::NATURE_SKILL    => 'skills',
            KnowledgeDsl::NATURE_RELATION => 'relations',
        ];

        // No filter → all sources.
        if (empty($natures)) {
            return array_fill_keys(array_values($natureToSource), true);
        }

        $want = [];
        foreach ($natures as $nature) {
            if (isset($natureToSource[$nature])) {
                $want[$natureToSource[$nature]] = true;
            }
        }

        // Unknown/empty-after-filter → fall back to wide rather than silently
        // returning nothing (a bad filter shouldn't blank the recall).
        return $want !== [] ? $want : array_fill_keys(array_values($natureToSource), true);
    }

    /**
     * Assemble the final lean recall text. Empty when nothing surfaced (so the
     * model gets a clear "nothing" rather than empty headers).
     *
     * Each line is tagged with its source (Ada's transparency request): notes,
     * episodes and relations get an inline [src] prefix per line; people/skills
     * come as pre-formatted blocks under a labelled header, so the header IS the
     * per-line attribution for those. A one-line header states which sources
     * were searched, so "nothing found" is unambiguous about scope.
     *
     * @param array<string,string[]> $blocks
     * @param string[] $natures  The filter that was applied (for the scope note).
     */
    private function composeRecall(array $blocks, string $query, array $natures = []): string
    {
        // Short source tags for per-line attribution.
        $tag = [
            'notes'     => 'note',
            'episodes'  => 'episode',
            'people'    => 'person',
            'skills'    => 'skill',
            'relations' => 'relation',
        ];
        $labels = [
            'notes'     => 'Notes',
            'episodes'  => 'Episodes',
            'people'    => 'People',
            'skills'    => 'Skills',
            'relations' => 'Relations',
        ];

        // Header states scope so "nothing found" is never ambiguous about where
        // it looked.
        $scope = empty($natures)
            ? 'all sources'
            : implode(', ', array_map(fn ($n) => $tag[$this->sourceKeyForNature($n)] ?? $n, $natures));

        if (empty($blocks)) {
            return "Recall for \"{$query}\" (searched: {$scope}): nothing found.";
        }

        $out = ["Recall for \"{$query}\" (searched: {$scope}):"];
        foreach ($labels as $key => $label) {
            if (empty($blocks[$key])) {
                continue;
            }
            $out[] = "\n[{$label}]";
            foreach ($blocks[$key] as $line) {
                // Per-line source tag. notes/episodes/relations are bullet lines
                // we own — prefix them. people/skills are pre-formatted blocks;
                // the [People]/[Skills] header already attributes them, so we
                // don't double-tag inside the block.
                if (in_array($key, ['notes', 'episodes', 'relations'], true)) {
                    // Insert the [src] tag after the leading bullet if present.
                    $line = preg_replace('/^•\s*/', "• [{$tag[$key]}] ", $line, 1, $count);
                    if (!$count) {
                        $line = "[{$tag[$key]}] " . $line;
                    }
                }
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /** Reverse lookup: nature → source key, for the scope header. */
    private function sourceKeyForNature(string $nature): string
    {
        return [
            KnowledgeDsl::NATURE_NOTE     => 'notes',
            KnowledgeDsl::NATURE_EPISODE  => 'episodes',
            KnowledgeDsl::NATURE_PERSON   => 'people',
            KnowledgeDsl::NATURE_SKILL    => 'skills',
            KnowledgeDsl::NATURE_RELATION => 'relations',
        ][$nature] ?? $nature;
    }

    /** Normalize any service result to the {success, message} shape. */
    private function normalize(array $result): array
    {
        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /** @return array{success: false, message: string} */
    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    /**
     * Run a service call, swallowing failures to a null/empty so one dead source
     * never kills the whole fan-out recall.
     *
     * @template T
     * @param  callable():T $fn
     * @return T|null
     */
    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->logger->warning('KnowledgeRouter recall source failed: ' . $e->getMessage());
            return null;
        }
    }

    private function clip(string $s, int $len = 200): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return mb_strlen($s) <= $len ? $s : mb_substr($s, 0, $len) . '…';
    }
}

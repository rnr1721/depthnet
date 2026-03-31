<?php

namespace App\Services\Agent\Memory;

use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\AiPreset;
use App\Models\PersonMemory;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * PersonMemoryService
 *
 * Manages structured facts about people.
 *
 * person_name is a slash-separated string of aliases:
 *   "Женя / Жэка / James Kvakiani"
 *
 * Semantic search over fact content uses embedding cosine similarity
 * with automatic TF-IDF fallback — same pattern as JournalService.
 */
class PersonMemoryService implements PersonMemoryServiceInterface
{
    /** Separator used between aliases in person_name */
    public const ALIAS_SEP = ' / ';

    public function __construct(
        protected TfIdfServiceInterface     $tfIdfService,
        protected EmbeddingServiceInterface $embeddingService,
        protected PersonMemory              $personModel,
        protected LoggerInterface           $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Write — facts
    // -------------------------------------------------------------------------

    /**
     * Add a fact about a person.
     * Format: "Name | fact text"
     *
     * If a person with that name (or alias) already exists, the fact is added
     * to their existing record group. Otherwise a new person is created.
     */
    public function addFact(AiPreset $preset, string $personName, string $fact): array
    {
        try {
            $personName = trim($personName);
            $fact       = trim($fact);

            if (empty($personName) || empty($fact)) {
                return ['success' => false, 'message' => 'Person name and fact cannot be empty.'];
            }

            // Resolve canonical name string — find existing person by any alias
            $canonical = $this->resolveCanonicalName($preset, $personName) ?? $personName;

            $exists = $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->whereRaw('LOWER(content) = ?', [strtolower($fact)])
                ->exists();

            if ($exists) {
                return [
                    'success' => false,
                    'message' => "Fact already exists for {$canonical}: \"{$fact}\"",
                ];
            }

            // Next position for this person
            $position = $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->max('position') ?? 0;
            $position++;

            $vector = $this->tfIdfService->vectorize($fact);

            $record = $this->personModel->create([
                'preset_id'    => $preset->id,
                'person_name'  => $canonical,
                'content'      => $fact,
                'position'     => $position,
                'tfidf_vector' => $vector,
            ]);

            $this->attachEmbedding($record, $fact, $preset);

            $primary = $record->getPrimaryName();
            return [
                'success' => true,
                'message' => "Fact #{$record->id} added for {$primary} (position {$position}): {$fact}",
                'record'  => $record,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::addFact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding fact: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Write — aliases
    // -------------------------------------------------------------------------

    /**
     * Add an alias to a person identified by fact ID.
     * Updates person_name on ALL facts belonging to that person.
     *
     * [person alias add]1 | Жэка[/person]
     */
    public function addAlias(AiPreset $preset, int $factId, string $alias): array
    {
        try {
            $alias = trim($alias);
            if (empty($alias)) {
                return ['success' => false, 'message' => 'Alias cannot be empty.'];
            }

            $record = $this->personModel->forPreset($preset->id)->find($factId);
            if (!$record) {
                return ['success' => false, 'message' => "Person fact #{$factId} not found."];
            }

            $names = $record->getAllNames();

            // Already has this alias?
            if (in_array(strtolower($alias), array_map('strtolower', $names), true)) {
                return ['success' => false, 'message' => "Alias \"{$alias}\" already exists for {$record->getPrimaryName()}."];
            }

            // Check if alias already belongs to a different person
            $existing = $this->personModel
                ->forPreset($preset->id)
                ->mentions($alias)
                ->first();

            if ($existing && $existing->person_name !== $record->person_name) {
                return [
                    'success' => false,
                    'message' => "Alias \"{$alias}\" already used by another person: {$existing->getPrimaryName()}.",
                ];
            }

            $names[]      = $alias;
            $newName      = implode(self::ALIAS_SEP, $names);
            $currentName  = $record->person_name;

            // Update all facts for this person atomically
            $this->personModel
                ->forPreset($preset->id)
                ->forPerson($currentName)
                ->update(['person_name' => $newName]);

            return [
                'success' => true,
                'message' => "Alias \"{$alias}\" added. {$record->getPrimaryName()} is now known as: {$newName}",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::addAlias error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding alias: ' . $e->getMessage()];
        }
    }

    /**
     * Remove an alias from a person identified by fact ID.
     * Cannot remove the primary name (first segment).
     *
     * [person alias remove]1 | Жэка[/person]
     */
    public function removeAlias(AiPreset $preset, int $factId, string $alias): array
    {
        try {
            $alias = trim($alias);

            $record = $this->personModel->forPreset($preset->id)->find($factId);
            if (!$record) {
                return ['success' => false, 'message' => "Person fact #{$factId} not found."];
            }

            $names   = $record->getAllNames();
            $primary = $names[0];

            if (strtolower($alias) === strtolower($primary)) {
                return ['success' => false, 'message' => "Cannot remove primary name \"{$primary}\". Use [person alias add] to add a new one first, then remove the old."];
            }

            $filtered = array_values(array_filter(
                $names,
                fn ($n) => strtolower($n) !== strtolower($alias)
            ));

            if (count($filtered) === count($names)) {
                return ['success' => false, 'message' => "Alias \"{$alias}\" not found for {$primary}."];
            }

            $newName     = implode(self::ALIAS_SEP, $filtered);
            $currentName = $record->person_name;

            $this->personModel
                ->forPreset($preset->id)
                ->forPerson($currentName)
                ->update(['person_name' => $newName]);

            return [
                'success' => true,
                'message' => "Alias \"{$alias}\" removed. Now known as: {$newName}",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::removeAlias error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error removing alias: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Recall all facts for a person by name/alias or by fact ID.
     * [person recall]Женя[/person]  or  [person recall]1[/person]
     */
    public function recallPerson(AiPreset $preset, string $nameOrId): array
    {
        try {
            $canonical = $this->resolveByNameOrId($preset, $nameOrId);

            if ($canonical === null) {
                return ['success' => false, 'message' => "Person \"{$nameOrId}\" not found. Use [person list][/person] to see all people."];
            }

            $facts = $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->ordered()
                ->get();

            return ['success' => true, 'message' => $this->formatPerson($canonical, $facts)];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::recallPerson error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error recalling person: ' . $e->getMessage()];
        }
    }

    /**
     * Find persons by mention — searches across all aliases.
     * Returns list of matching persons with their IDs.
     * [person find]Вася[/person]
     */
    public function findByMention(AiPreset $preset, string $term): array
    {
        try {
            $term = trim($term);

            $records = $this->personModel
                ->forPreset($preset->id)
                ->mentions($term)
                ->ordered()
                ->get();

            if ($records->isEmpty()) {
                return ['success' => true, 'message' => "No person matching \"{$term}\" found."];
            }

            // Group by person_name
            $grouped = $records->groupBy('person_name');
            $lines   = ["Persons matching \"{$term}\":"];

            foreach ($grouped as $name => $facts) {
                $firstId = $facts->first()->id;
                $count   = $facts->count();
                $lines[] = "  #{$firstId} {$name} — {$count} fact(s)";
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::findByMention error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error finding person: ' . $e->getMessage()];
        }
    }

    /**
     * Semantic search across all facts for a preset.
     * Uses embedding cosine similarity, falls back to TF-IDF.
     * [person search]punk aesthetic[/person]
     */
    public function searchFacts(AiPreset $preset, string $query, int $limit = 5): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return ['success' => false, 'message' => 'Search query cannot be empty.'];
            }

            $all = $this->personModel
                ->forPreset($preset->id)
                ->get();

            if ($all->isEmpty()) {
                return ['success' => true, 'message' => 'No person facts recorded yet.'];
            }

            $matched = $this->semanticSearch($all, $query, $limit, $preset);

            if (empty($matched)) {
                return ['success' => true, 'message' => "No facts matching \"{$query}\" found."];
            }

            $lines = ["[PERSON FACTS — search: \"{$query}\"]", ''];
            foreach ($matched as $r) {
                $record = $r['record'];
                $score  = round($r['score'] * 100, 1);
                $lines[] = "#{$record->id} [{$record->getPrimaryName()} | {$score}%] {$record->content}";
            }
            $lines[] = '';
            $lines[] = '[END]';

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::searchFacts error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error searching facts: ' . $e->getMessage()];
        }
    }

    /**
     * List all known people with their fact counts and IDs.
     * [person list][/person]
     */
    public function listPeople(AiPreset $preset): array
    {
        try {
            $records = $this->personModel
                ->forPreset($preset->id)
                ->ordered()
                ->get();

            if ($records->isEmpty()) {
                return ['success' => true, 'message' => 'No people in memory yet.'];
            }

            $grouped = $records->groupBy('person_name');
            $lines   = ['[KNOWN PEOPLE]', ''];

            foreach ($grouped as $name => $facts) {
                $firstId = $facts->first()->id;
                $count   = $facts->count();
                $lines[] = "#{$firstId} {$name} ({$count} fact" . ($count !== 1 ? 's' : '') . ')';
            }

            $lines[] = '';
            $lines[] = 'Use [person recall]Name or ID[/person] to see facts.';

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::listPeople error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error listing people: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    /**
     * Delete a specific fact by its ID.
     * [person delete]42[/person]
     */
    public function deleteFact(AiPreset $preset, int $factId): array
    {
        try {
            $record = $this->personModel->forPreset($preset->id)->find($factId);

            if (!$record) {
                return ['success' => false, 'message' => "Fact #{$factId} not found."];
            }

            $name    = $record->getPrimaryName();
            $content = mb_substr($record->content, 0, 80);
            $record->delete();

            return ['success' => true, 'message' => "Fact #{$factId} deleted from {$name}: \"{$content}\""];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::deleteFact error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting fact: ' . $e->getMessage()];
        }
    }

    /**
     * Delete all facts about a person (by name/alias or fact ID).
     * [person forget]Женя[/person]
     */
    public function forgetPerson(AiPreset $preset, string $nameOrId): array
    {
        try {
            $canonical = $this->resolveByNameOrId($preset, $nameOrId);

            if ($canonical === null) {
                return ['success' => false, 'message' => "Person \"{$nameOrId}\" not found."];
            }

            $count = $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->count();

            $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->delete();

            $primary = trim(explode(self::ALIAS_SEP, $canonical)[0]);
            return ['success' => true, 'message' => "Forgot {$primary} — {$count} fact(s) deleted."];

        } catch (\Throwable $e) {
            $this->logger->error('PersonMemoryService::forgetPerson error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error forgetting person: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // RAG enricher support
    // -------------------------------------------------------------------------

    /**
     * Get relevant person facts for RAG context, given a semantic query.
     * Returns raw records grouped by person, up to $factsPerPerson each.
     *
     * Used by PersonContextEnricher.
     *
     * @return array<string, PersonMemory[]>  keyed by person_name
     */
    public function getRelevantFacts(AiPreset $preset, string $query, int $limit = 5, int $factsPerPerson = 5): array
    {
        $all = $this->personModel->forPreset($preset->id)->get();

        if ($all->isEmpty()) {
            return [];
        }

        $matched = $this->semanticSearch($all, $query, $limit * $factsPerPerson, $preset);

        $grouped = [];
        foreach ($matched as $r) {
            $name = $r['record']->person_name;
            if (!isset($grouped[$name])) {
                $grouped[$name] = [];
            }
            if (count($grouped[$name]) < $factsPerPerson) {
                $grouped[$name][] = $r['record'];
            }
        }

        return $grouped;
    }

    /**
     * Get all facts for people currently in Heart focus.
     * Used by PersonContextEnricher when Heart plugin is active.
     *
     * @param  string[] $names  Names from heart_state dominant/connections
     * @return array<string, PersonMemory[]>
     */
    public function getFactsForNames(AiPreset $preset, array $names, int $factsPerPerson = 5): array
    {
        $grouped = [];

        foreach ($names as $name) {
            $canonical = $this->resolveCanonicalName($preset, $name);
            if ($canonical === null) {
                continue;
            }

            $facts = $this->personModel
                ->forPreset($preset->id)
                ->forPerson($canonical)
                ->ordered()
                ->limit($factsPerPerson)
                ->get();

            if ($facts->isNotEmpty()) {
                $grouped[$canonical] = $facts->all();
            }
        }

        return $grouped;
    }

    // -------------------------------------------------------------------------
    // Structured export / clear
    // -------------------------------------------------------------------------

    /**
     * Get all people with their facts as a structured array.
     * Used by UI and export functionality.
     *
     * @return array<array{name: string, primary: string, aliases: string[], facts: PersonMemory[]}>
     */
    public function getStructuredPeople(AiPreset $preset): array
    {
        $records = $this->personModel
            ->forPreset($preset->id)
            ->ordered()
            ->get();

        $result = [];

        foreach ($records->groupBy('person_name') as $name => $facts) {
            $segments = array_map('trim', explode(self::ALIAS_SEP, $name));
            $result[] = [
                'name'    => $name,
                'primary' => $segments[0],
                'aliases' => array_slice($segments, 1),
                'facts'   => $facts->all(),
            ];
        }

        return $result;
    }

    /**
     * Delete all persons and facts for a preset.
     */
    public function clearAll(AiPreset $preset): void
    {
        $this->personModel->forPreset($preset->id)->delete();
    }

    // -------------------------------------------------------------------------
    // Semantic search — same pattern as JournalService
    // -------------------------------------------------------------------------

    /**
     * @param  Collection<PersonMemory> $records
     * @return array<array{record: PersonMemory, score: float}>
     */
    private function semanticSearch(Collection $records, string $query, int $limit, AiPreset $preset): array
    {
        // ── Embedding path ────────────────────────────────────────────────────
        if ($this->embeddingService->isAvailable($preset)) {
            $queryVector = $this->embeddingService->embed($query, $preset);

            if ($queryVector !== null) {
                $withEmbedding    = $records->filter(fn ($r) => !empty($r->embedding));
                $withoutEmbedding = $records->filter(fn ($r) => empty($r->embedding));

                $results = [];

                foreach ($withEmbedding as $record) {
                    $score = $this->cosineSimilarity($queryVector, $record->embedding);
                    if ($score >= 0.3) {
                        $results[] = ['record' => $record, 'score' => $score];
                    }
                }

                usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
                $results = array_slice($results, 0, $limit);

                // TF-IDF supplement for records without embedding
                if ($withoutEmbedding->isNotEmpty() && count($results) < $limit) {
                    $seenIds   = array_map(fn ($r) => $r['record']->id, $results);
                    $remaining = $limit - count($results);

                    $tfidf = $this->tfIdfService->findSimilar(
                        $query,
                        $withoutEmbedding,
                        $remaining,
                        0.05,
                        false
                    );

                    foreach ($tfidf as $r) {
                        if (!in_array($r['document']->id, $seenIds, true)) {
                            $results[] = ['record' => $r['document'], 'score' => $r['similarity']];
                        }
                    }
                }

                if (!empty($results)) {
                    return $results;
                }
            }
        }

        // ── TF-IDF fallback ───────────────────────────────────────────────────
        $tfidf = $this->tfIdfService->findSimilar($query, $records, $limit, 0.05, false);

        return array_map(
            fn ($r) => ['record' => $r['document'], 'score' => $r['similarity']],
            $tfidf
        );
    }

    // -------------------------------------------------------------------------
    // Embedding helpers
    // -------------------------------------------------------------------------

    private function attachEmbedding(PersonMemory $record, string $text, AiPreset $preset): void
    {
        try {
            if (!$this->embeddingService->isAvailable($preset)) {
                return;
            }

            $vector = $this->embeddingService->embed($text, $preset);

            if ($vector === null) {
                return;
            }

            $record->update([
                'embedding'     => $vector,
                'embedding_dim' => count($vector),
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('PersonMemoryService: failed to attach embedding.', [
                'record_id' => $record->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    // -------------------------------------------------------------------------
    // Name resolution helpers
    // -------------------------------------------------------------------------

    /**
     * Find canonical person_name string by searching all aliases.
     * Returns null if nobody found.
     */
    private function resolveCanonicalName(AiPreset $preset, string $term): ?string
    {
        $record = $this->personModel
            ->forPreset($preset->id)
            ->mentions($term)
            ->first();

        return $record?->person_name;
    }

    /**
     * Resolve by name/alias string OR by numeric fact ID.
     */
    private function resolveByNameOrId(AiPreset $preset, string $nameOrId): ?string
    {
        $nameOrId = trim($nameOrId);

        // Numeric — look up by fact ID
        if (ctype_digit($nameOrId)) {
            $record = $this->personModel->forPreset($preset->id)->find((int) $nameOrId);
            return $record?->person_name;
        }

        return $this->resolveCanonicalName($preset, $nameOrId);
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    private function formatPerson(string $canonicalName, Collection $facts): string
    {
        $primary = trim(explode(self::ALIAS_SEP, $canonicalName)[0]);
        $aliases = array_slice(array_map('trim', explode(self::ALIAS_SEP, $canonicalName)), 1);

        $lines = ["[PERSON: {$canonicalName}]", ''];

        if (!empty($aliases)) {
            $lines[] = 'Also known as: ' . implode(', ', $aliases);
            $lines[] = '';
        }

        if ($facts->isEmpty()) {
            $lines[] = 'No facts recorded yet.';
        } else {
            foreach ($facts as $fact) {
                $lines[] = "#{$fact->id} {$fact->content}";
            }
        }

        $lines[] = '';
        $lines[] = "[END {$primary}]";

        return implode("\n", $lines);
    }
}

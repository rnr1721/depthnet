<?php

namespace App\Contracts\Agent\Search;

use App\Services\Agent\Search\ParsedSearchQuery;

/**
 * Parses search queries that may carry a date expression prefix.
 *
 * Supported input shapes (all whitespace-tolerant):
 *
 *   "semantic text"                        → no time filter, query as-is
 *   "today"                                → time filter only, empty query
 *   "today | semantic text"                → time filter + semantic part
 *   "yesterday | meeting notes"            → keyword + semantic
 *   "сегодня | заметки"                    → Russian keyword + semantic
 *   "2026-03-15"                           → single date
 *   "2026-03-15 | release notes"           → single date + semantic
 *   "2026-03-10:2026-03-15"                → date range
 *   "2026-03-10:2026-03-15 | bugfixes"     → range + semantic
 *
 * Multilingual keyword recognition is data-driven: the parser loads a flat
 * hashmap of all keyword variants across all configured languages, so
 * "today" and "сегодня" both resolve correctly without any language hint.
 * ISO date formats are universal and recognised regardless of language config.
 *
 * Behaviour for malformed input:
 *
 *   - If the part before `|` is NOT a recognised date expression, the
 *     ENTIRE original string is returned as the semantic query (the `|`
 *     is treated as literal content, not a separator). This preserves
 *     backward compatibility for callers whose own DSL also uses `|`
 *     (e.g. VectorMemory's "domain:work | query" prefix).
 *
 *   - If the whole string IS a recognised date expression, the semantic
 *     query is empty.
 *
 *   - Empty / whitespace-only input → empty query, no time filter.
 *
 * The parser is stateless and safe to share as a singleton.
 */
interface SearchDateParserInterface
{
    /**
     * Parse a search query, extracting any date expression prefix.
     *
     * @param string $query The raw search query as written by the agent or user.
     * @return ParsedSearchQuery Resolved date bounds + remaining semantic text.
     */
    public function parse(string $query): ParsedSearchQuery;

    /**
     * Try to parse a string as a date expression on its own (no `|` involved).
     *
     * Returns [from, to] tuple where both bounds may be Carbon instances or
     * one may be null (for "from X onwards" / "until Y" style expressions —
     * currently not produced by the default vocabulary, but reserved).
     *
     * Returns null if the expression is not recognised.
     *
     * Exposed publicly so callers that already have a separated date string
     * (e.g. UI date pickers) can reuse the same vocabulary without re-parsing
     * the `|` syntax.
     *
     * @param string $expr A trimmed candidate date expression.
     * @return array{0: \Carbon\Carbon|null, 1: \Carbon\Carbon|null}|null
     */
    public function parseDateExpression(string $expr): ?array;

    /**
     * List all recognised keyword variants across all loaded languages.
     * Useful for the plugin layer when generating dynamic instructions —
     * e.g. "you can write 'yesterday' or 'вчера' here".
     *
     * @return array<string, string[]> Canonical key → list of variants.
     *                                  Example: ['today' => ['today', 'сегодня']]
     */
    public function listKeywords(): array;
}

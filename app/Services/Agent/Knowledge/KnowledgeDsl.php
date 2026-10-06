<?php

namespace App\Services\Agent\Knowledge;

/**
 * KnowledgeDsl — the thin, flat contract between "intent" and "storage".
 *
 * This is the seam we deliberately made visible (the "border A/B" from the v3
 * concept, stripped of its machinery). Whatever produces a knowledge operation
 * — the model directly (direct mode) or the formulator preset (formulator mode)
 * — must express it as one of these ops. The router understands ONLY this
 * vocabulary; anything outside it is rejected at this boundary, never passed
 * down into a service as a malformed array.
 *
 * Design rules (do not grow these without a reason):
 *   - Flat JSON, not a text grammar. No nesting beyond args.
 *   - `op`     — closed set: remember | relate | forget.  (recall is NOT here —
 *                 it never routes to a single home; see KnowledgeRouter::recall)
 *   - `nature` — closed set: the *kind of knowledge*, NOT the subsystem.
 *                 The model/formulator says what KIND of thing this is; the
 *                 router alone knows which subsystem that kind lives in.
 *   - `args`   — operation payload, shape depends on op+nature. Kept as a plain
 *                 associative array so services (which already take arrays) get
 *                 fed with minimal translation.
 *
 * The whole point of `nature` (vs naming a subsystem): the model must never
 * learn that journal / vectormemory / ontology / person / skill exist as
 * separate stores. It reasons about the *nature of the knowledge*. If a value
 * here were ever a subsystem name, the facade would have leaked.
 */
final class KnowledgeDsl
{
    // ── Operations (closed) ───────────────────────────────────────────────────

    public const OP_REMEMBER = 'remember';
    public const OP_RELATE   = 'relate';
    public const OP_FORGET   = 'forget';

    public const OPS = [
        self::OP_REMEMBER,
        self::OP_RELATE,
        self::OP_FORGET,
    ];

    // ── Natures (closed) ──────────────────────────────────────────────────────
    //
    // The "one fact, one home" table lives in KnowledgeRouter. These are the
    // kinds of knowledge the model expresses. Each maps to exactly one home on
    // write (unambiguous routing); read is fan-out and does not use these.

    /** Something that happened — an episode, with a time. → journal */
    public const NATURE_EPISODE = 'episode';

    /** A durable fact about a named person. → person */
    public const NATURE_PERSON = 'person';

    /** A relation/edge or durable structured fact about an entity. → ontology */
    public const NATURE_RELATION = 'relation';

    /** Reusable, teachable knowledge you'll want to apply again. → skill */
    public const NATURE_SKILL = 'skill';

    /** A crystallized insight / semantic note, recalled by meaning. → vectormemory */
    public const NATURE_NOTE = 'note';

    public const NATURES = [
        self::NATURE_EPISODE,
        self::NATURE_PERSON,
        self::NATURE_RELATION,
        self::NATURE_SKILL,
        self::NATURE_NOTE,
    ];

    /**
     * Parse a raw DSL payload (already JSON-decoded to an array) into a
     * validated shape, or return an error describing why it isn't valid DSL.
     *
     * This is where the seam is enforced: unknown op, unknown nature, or
     * missing content is caught HERE, at the border — not deep inside a
     * service. Returns either:
     *   ['ok' => true,  'op' => ..., 'nature' => ..., 'args' => [...]]
     *   ['ok' => false, 'error' => 'human-readable reason']
     *
     * @param  array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    public static function validate(array $decoded): array
    {
        $op = $decoded['op'] ?? null;

        if (!is_string($op) || !in_array($op, self::OPS, true)) {
            return self::fail(sprintf(
                'Unknown or missing op "%s". Allowed: %s.',
                is_string($op) ? $op : gettype($op),
                implode(', ', self::OPS)
            ));
        }

        $args = $decoded['args'] ?? [];
        if (!is_array($args)) {
            return self::fail('"args" must be an object.');
        }

        // forget carries a nature (which home to forget from) + a target.
        // relate is ontology-only by definition, nature is implied.
        // remember requires an explicit nature.
        if ($op === self::OP_RELATE) {
            // relate never needs a nature — it is always a graph edge.
            return [
                'ok'     => true,
                'op'     => self::OP_RELATE,
                'nature' => self::NATURE_RELATION,
                'args'   => $args,
            ];
        }

        $nature = $decoded['nature'] ?? null;

        if (!is_string($nature) || !in_array($nature, self::NATURES, true)) {
            return self::fail(sprintf(
                'Unknown or missing nature "%s". Allowed: %s.',
                is_string($nature) ? $nature : gettype($nature),
                implode(', ', self::NATURES)
            ));
        }

        return [
            'ok'     => true,
            'op'     => $op,
            'nature' => $nature,
            'args'   => $args,
        ];
    }

    /**
     * Decode a raw JSON string into validated DSL. Convenience wrapper used by
     * the plugin's direct mode (model hands JSON) and formulator mode (preset
     * returns JSON).
     *
     * @return array<string,mixed>  Same shape as validate().
     */
    public static function fromJson(string $raw): array
    {
        $raw = trim($raw);

        // Formulators sometimes wrap JSON in ```json fences — strip them.
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $raw = trim((string) $raw);

        if ($raw === '') {
            return self::fail('Empty knowledge payload.');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::fail('Payload is not valid JSON object.');
        }

        return self::validate($decoded);
    }

    /**
     * @return array{ok: false, error: string}
     */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'error' => $reason];
    }
}

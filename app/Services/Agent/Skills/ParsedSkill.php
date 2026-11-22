<?php

namespace App\Services\Agent\Skills;

/**
 * Result of parsing one SKILL.md. Pure value object — no DB, no framework.
 *
 * errors   → the skill must NOT be imported (no description, broken frontmatter …)
 * warnings → importable, but something deviates from the agentskills.io spec
 *            (name ≠ dir name, over-long compatibility …); shown in the library UI.
 */
final class ParsedSkill
{
    /**
     * @param string        $name          Skill identity. Always the directory name (spec: must match).
     * @param string        $declaredName  `name` from frontmatter as written ('' if absent).
     * @param string        $description
     * @param string|null   $license
     * @param string|null   $compatibility
     * @param string|null   $allowedTools  Raw `allowed-tools` string, verbatim. Never mapped to DepthNet tools.
     * @param array<string,string> $metadata
     * @param string[]      $items         Body cut into items (item 1 = preamble, then one per section).
     * @param string[]      $errors
     * @param string[]      $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $declaredName,
        public readonly string $description,
        public readonly ?string $license,
        public readonly ?string $compatibility,
        public readonly ?string $allowedTools,
        public readonly array $metadata,
        public readonly array $items,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}

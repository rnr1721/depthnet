<?php

namespace App\Services\Agent\Skills;

/**
 * Parses an agentskills.io SKILL.md into a ParsedSkill.
 *
 *  1. YAML frontmatter between leading `---` lines. Uses symfony/yaml when it is
 *     installed, otherwise a small built-in reader that covers what SKILL.md
 *     frontmatter uses in practice: plain/quoted scalars, block scalars (| and >),
 *     one level of nested map (metadata:), flow and block lists.
 *  2. Body cut into items:
 *       - the "section level" is the smallest heading level that occurs at least
 *         twice; a lone top heading (typically `# Title`) stays in the preamble;
 *       - text before the first section heading → item 1;
 *       - each section heading starts a new item; deeper headings stay inside it;
 *       - headings are recognised ONLY outside ``` / ~~~ fenced code blocks
 *         (a `# comment` inside a bash block is not a heading);
 *       - a section longer than MAX_ITEM_CHARS is split on blank lines outside
 *         fences; continuation parts are prefixed with "(cont.) <heading>".
 *
 * No framework dependencies — unit-testable in isolation.
 */
class SkillMdParser
{
    public const MAX_NAME          = 64;
    public const MAX_DESCRIPTION   = 1024;
    public const MAX_COMPATIBILITY = 500;

    /** Soft cap for one item. Sections above it are split at paragraph boundaries. */
    public const MAX_ITEM_CHARS = 12000;

    public function parseFile(string $path, ?string $dirName = null): ParsedSkill
    {
        $raw = @file_get_contents($path);
        $dirName ??= basename(dirname($path));

        if ($raw === false) {
            return $this->failed($dirName, ["Cannot read {$path}"]);
        }

        return $this->parse($raw, $dirName);
    }

    public function parse(string $raw, string $dirName): ParsedSkill
    {
        // Normalise: strip BOM, unify newlines.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $errors = [];
        $warnings = [];

        [$frontmatter, $body, $fmError] = $this->splitFrontmatter($raw);
        if ($fmError !== null) {
            return $this->failed($dirName, [$fmError]);
        }

        try {
            $meta = $this->parseYaml($frontmatter);
        } catch (\Throwable $e) {
            return $this->failed($dirName, ['Invalid YAML frontmatter: ' . $e->getMessage()]);
        }

        // ── name ────────────────────────────────────────────────────────────
        $declaredName = $this->str($meta['name'] ?? null) ?? '';
        if ($declaredName === '') {
            $warnings[] = "Frontmatter has no `name`; using directory name \"{$dirName}\".";
        } elseif ($declaredName !== $dirName) {
            $warnings[] = "`name: {$declaredName}` does not match directory \"{$dirName}\"; the directory name is used.";
        }
        if (!self::isValidName($dirName)) {
            $errors[] = "Directory name \"{$dirName}\" is not a valid skill name "
                . "(1-" . self::MAX_NAME . " chars, a-z 0-9 and single hyphens, no leading/trailing hyphen).";
        }

        // ── description ─────────────────────────────────────────────────────
        $description = trim($this->str($meta['description'] ?? null) ?? '');
        if ($description === '') {
            $errors[] = 'Frontmatter `description` is required.';
        } elseif (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $warnings[] = 'Description longer than ' . self::MAX_DESCRIPTION . ' chars; truncated.';
            $description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION - 1)) . '…';
        }

        // ── optional fields ────────────────────────────────────────────────
        $license = $this->str($meta['license'] ?? null);

        $compatibility = $this->str($meta['compatibility'] ?? null);
        if ($compatibility !== null && mb_strlen($compatibility) > self::MAX_COMPATIBILITY) {
            $warnings[] = '`compatibility` longer than ' . self::MAX_COMPATIBILITY . ' chars.';
        }

        // allowed-tools: spec says space-delimited string; tolerate a YAML list. Kept verbatim.
        $allowedRaw = $meta['allowed-tools'] ?? null;
        $allowedTools = is_array($allowedRaw)
            ? implode(' ', array_map('strval', array_filter($allowedRaw, 'is_scalar')))
            : $this->str($allowedRaw);

        $metadata = [];
        if (isset($meta['metadata'])) {
            if (is_array($meta['metadata'])) {
                foreach ($meta['metadata'] as $k => $v) {
                    if (is_scalar($v) || $v === null) {
                        $metadata[(string) $k] = (string) $v;
                    }
                }
            } else {
                $warnings[] = '`metadata` should be a map; ignored.';
            }
        }

        $known = ['name', 'description', 'license', 'compatibility', 'allowed-tools', 'metadata'];
        $unknown = array_diff(array_keys($meta), $known);
        if ($unknown !== []) {
            $warnings[] = 'Unknown frontmatter fields ignored: ' . implode(', ', $unknown) . '.';
        }

        // ── body ────────────────────────────────────────────────────────────
        $items = $this->cutItems($body);
        if ($items === []) {
            $warnings[] = 'SKILL.md body is empty.';
        }

        return new ParsedSkill(
            name: $dirName,
            declaredName: $declaredName,
            description: $description,
            license: $license,
            compatibility: $compatibility,
            allowedTools: $allowedTools,
            metadata: $metadata,
            items: $items,
            errors: $errors,
            warnings: $warnings,
        );
    }

    public static function isValidName(string $name): bool
    {
        return strlen($name) >= 1
            && strlen($name) <= self::MAX_NAME
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    // ── Frontmatter ──────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:?string} [frontmatter, body, error] */
    private function splitFrontmatter(string $raw): array
    {
        if (!preg_match('/^---[ \t]*\n/', $raw)) {
            return ['', $raw, 'SKILL.md must start with YAML frontmatter (`---`).'];
        }

        $rest = substr($raw, strpos($raw, "\n") + 1);
        if (!preg_match('/^(?:---|\.\.\.)[ \t]*$/m', $rest, $m, PREG_OFFSET_CAPTURE)) {
            return ['', $raw, 'Frontmatter is not closed with `---`.'];
        }

        $end = $m[0][1];
        $frontmatter = substr($rest, 0, $end);
        $body = (string) substr($rest, $end + strlen($m[0][0]));

        return [$frontmatter, ltrim($body, "\n"), null];
    }

    private function parseYaml(string $yaml): array
    {
        if (trim($yaml) === '') {
            return [];
        }

        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $data = \Symfony\Component\Yaml\Yaml::parse($yaml);
            if (!is_array($data)) {
                throw new \RuntimeException('frontmatter is not a mapping');
            }
            return $data;
        }

        return $this->miniYaml($yaml);
    }

    /**
     * Minimal YAML reader for SKILL.md frontmatter. Top-level mapping only, with:
     *   key: scalar | "quoted" | 'quoted' | [a, b] | | / > block scalar | nested map / list (one level).
     */
    private function miniYaml(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $n = count($lines);
        $out = [];
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                $i++;
                continue;
            }
            if (preg_match('/^\s/', $line)) {
                throw new \RuntimeException('unexpected indentation at line ' . ($i + 1));
            }
            if (!preg_match('/^([^:#][^:]*?)\s*:(?:\s+(.*))?$/', $line, $m)) {
                throw new \RuntimeException('cannot parse line ' . ($i + 1) . ': ' . $line);
            }

            $key = $this->unquote(trim($m[1]));
            $value = isset($m[2]) ? rtrim($m[2]) : '';
            $i++;

            // Block scalar: | or > with optional chomping indicator.
            if (preg_match('/^([|>])([+-]?)\s*(?:#.*)?$/', $value, $bm)) {
                [$block, $i] = $this->readIndented($lines, $i);
                $out[$key] = $this->foldBlock($block, $bm[1], $bm[2]);
                continue;
            }

            if ($value === '' || str_starts_with($value, '#')) {
                // Nested map / list, or null.
                [$block, $i] = $this->readIndented($lines, $i);
                $out[$key] = $block === [] ? null : $this->nested($block);
                continue;
            }

            // Multi-line plain or quoted scalar: continuation lines are indented.
            [$cont, $i] = $this->readIndented($lines, $i);
            if ($cont !== []) {
                $value .= ' ' . implode(' ', array_map('trim', $cont));
            }

            $out[$key] = $this->scalar($value);
        }

        return $out;
    }

    /** Collect following lines that are indented (or blank), de-indented by the common indent. */
    private function readIndented(array $lines, int $i): array
    {
        $block = [];
        $n = count($lines);
        while ($i < $n && ($lines[$i] === '' || trim($lines[$i]) === '' || preg_match('/^\s/', $lines[$i]))) {
            $block[] = $lines[$i];
            $i++;
        }
        // Trailing blank lines belong to nobody.
        while ($block !== [] && trim(end($block)) === '') {
            array_pop($block);
            $i--;
        }
        $indent = null;
        foreach ($block as $l) {
            if (trim($l) === '') {
                continue;
            }
            $len = strlen($l) - strlen(ltrim($l));
            $indent = $indent === null ? $len : min($indent, $len);
        }
        $indent ??= 0;
        $block = array_map(fn ($l) => trim($l) === '' ? '' : substr($l, $indent), $block);

        return [$block, $i];
    }

    private function foldBlock(array $block, string $style, string $chomp): string
    {
        if ($style === '|') {
            $text = implode("\n", $block);
        } else {
            // Folded: single newlines → spaces, blank lines → newline.
            $text = '';
            foreach ($block as $idx => $l) {
                if ($l === '') {
                    $text .= "\n";
                } elseif ($text === '' || str_ends_with($text, "\n")) {
                    $text .= $l;
                } else {
                    $text .= ' ' . $l;
                }
            }
        }

        return match ($chomp) {
            '-'     => rtrim($text, "\n"),
            '+'     => $text . "\n",
            default => rtrim($text, "\n") . "\n",
        };
    }

    private function nested(array $block): array
    {
        $nonEmpty = array_values(array_filter($block, fn ($l) => trim($l) !== '' && !str_starts_with(ltrim($l), '#')));

        // Block list.
        if ($nonEmpty !== [] && str_starts_with($nonEmpty[0], '- ')) {
            return array_map(fn ($l) => $this->scalar(trim(substr(ltrim($l), 2))), $nonEmpty);
        }

        // One-level map (values are scalars).
        $out = [];
        foreach ($nonEmpty as $l) {
            if (!preg_match('/^([^:]+?)\s*:\s*(.*)$/', $l, $m)) {
                throw new \RuntimeException('cannot parse nested line: ' . $l);
            }
            $out[$this->unquote(trim($m[1]))] = $this->scalar(rtrim($m[2]));
        }
        return $out;
    }

    private function scalar(string $v): mixed
    {
        $v = trim($v);

        if ($v === '' || $v === '~' || $v === 'null') {
            return null;
        }
        if ($v[0] === '"' || $v[0] === "'") {
            return $this->unquote($v);
        }
        if ($v[0] === '[' && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(fn ($p) => $this->scalar($p), str_getcsv($inner, ',', '"', ''));
        }

        // Strip trailing comment " # …" on plain scalars.
        $v = preg_replace('/\s+#.*$/', '', $v);

        return match (strtolower($v)) {
            'true'  => true,
            'false' => false,
            default => $v,
        };
    }

    private function unquote(string $v): string
    {
        $len = strlen($v);
        if ($len >= 2 && $v[0] === '"' && $v[$len - 1] === '"') {
            return stripcslashes(substr($v, 1, -1));
        }
        if ($len >= 2 && $v[0] === "'" && $v[$len - 1] === "'") {
            return str_replace("''", "'", substr($v, 1, -1));
        }
        return $v;
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            $s = trim((string) $v);
            return $s === '' ? null : $s;
        }
        return null;
    }

    // ── Body → items ─────────────────────────────────────────────────────────

    /** @return string[] */
    public function cutItems(string $body): array
    {
        $lines = explode("\n", $body);
        $headings = $this->scanHeadings($lines); // [lineIdx => level]

        if ($headings === []) {
            return $this->finalise([['heading' => null, 'lines' => $lines]]);
        }

        $sectionLevel = $this->sectionLevel($headings);

        $sections = [['heading' => null, 'lines' => []]];
        foreach ($lines as $idx => $line) {
            if (isset($headings[$idx]) && $headings[$idx] === $sectionLevel) {
                $sections[] = ['heading' => trim($line), 'lines' => [$line]];
            } else {
                $sections[count($sections) - 1]['lines'][] = $line;
            }
        }

        return $this->finalise($sections);
    }

    /**
     * Headings outside fenced code. Returns [lineIndex => level].
     * @return array<int,int>
     */
    private function scanHeadings(array $lines): array
    {
        $out = [];
        $fence = null; // ['char' => '`'|'~', 'len' => int]

        foreach ($lines as $idx => $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m)) {
                $char = $m[1][0];
                $len = strlen($m[1]);
                if ($fence === null) {
                    // Backtick fence info string may not contain backticks.
                    if (!($char === '`' && str_contains($m[2], '`'))) {
                        $fence = ['char' => $char, 'len' => $len];
                    }
                    continue;
                }
                if ($char === $fence['char'] && $len >= $fence['len'] && trim($m[2]) === '') {
                    $fence = null;
                }
                continue;
            }

            if ($fence !== null) {
                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+|$)/', $line, $m)) {
                $out[$idx] = strlen($m[1]);
            }
        }

        return $out;
    }

    /** Smallest level that occurs ≥2 times; if none does, the smallest level present. */
    private function sectionLevel(array $headings): int
    {
        $counts = array_count_values($headings);
        ksort($counts);
        foreach ($counts as $level => $count) {
            if ($count >= 2) {
                return $level;
            }
        }
        // All headings unique: a lone `# Title` → cut on the next level down, if any.
        $levels = array_keys($counts);
        return $levels[1] ?? $levels[0];
    }

    /** Trim, drop empty sections, split oversized ones. */
    private function finalise(array $sections): array
    {
        $items = [];
        foreach ($sections as $s) {
            $text = trim(implode("\n", $s['lines']), "\n");
            if (trim($text) === '') {
                continue;
            }
            foreach ($this->splitOversized($text, $s['heading']) as $part) {
                $items[] = $part;
            }
        }
        return $items;
    }

    /** @return string[] */
    private function splitOversized(string $text, ?string $heading): array
    {
        if (mb_strlen($text) <= self::MAX_ITEM_CHARS) {
            return [$text];
        }

        // Paragraph blocks, never breaking inside a fence.
        $blocks = [];
        $cur = [];
        $inFence = false;
        $fenceChar = '';
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceChar = $m[1][0];
                } elseif ($m[1][0] === $fenceChar) {
                    $inFence = false;
                }
            }
            if (!$inFence && trim($line) === '' && $cur !== []) {
                $blocks[] = implode("\n", $cur);
                $cur = [];
                continue;
            }
            $cur[] = $line;
        }
        if ($cur !== []) {
            $blocks[] = implode("\n", $cur);
        }

        $parts = [];
        $buf = '';
        foreach ($blocks as $b) {
            $candidate = $buf === '' ? $b : $buf . "\n\n" . $b;
            if ($buf !== '' && mb_strlen($candidate) > self::MAX_ITEM_CHARS) {
                $parts[] = $buf;
                $buf = $b;
            } else {
                $buf = $candidate;
            }
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }

        // A single block (e.g. one huge code fence) stays whole: correctness over size.
        $label = $heading !== null ? '(cont.) ' . $heading : '(cont.)';
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $parts[$i] = $label . "\n\n" . $p;
            }
        }
        return $parts;
    }

    private function failed(string $dirName, array $errors): ParsedSkill
    {
        return new ParsedSkill($dirName, '', '', null, null, null, [], [], $errors, []);
    }
}

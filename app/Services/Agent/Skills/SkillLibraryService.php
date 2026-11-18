<?php

namespace App\Services\Agent\Skills;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\Skills\SkillLibraryServiceInterface;
use App\Models\AiPreset;
use App\Models\Skill;
use App\Models\SkillItem;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Two library roots, searched in priority order:
 *
 *   user    {shared_path}/skills/{name}   — the user's skills (git-ignored)
 *   bundled resources/skills/{name}       — skills that ship with DepthNet (in git)
 *
 * A skill's identity is its NAME (agent_skills.source_path), never its root, so
 * everything downstream (sandbox sync, `skill read`, hash-based refresh) works
 * through resolveDir() without knowing about roots. A user folder with the same
 * name as a bundled one OVERRIDES it — the same idea as resources/views/vendor
 * overriding a package's views: copy the folder, edit the copy, the original
 * stays untouched for the next update; delete the copy and the bundled one is back.
 *
 * DepthNet tools: `metadata: { depthnet-tools: "code, terminal" }` in SKILL.md
 * seeds the skill's tools on import and keeps following the file on refresh —
 * until the user edits the tools in the UI; from then on the user's choice wins.
 */
class SkillLibraryService implements SkillLibraryServiceInterface
{
    public const ORIGIN_USER    = 'user';
    public const ORIGIN_BUNDLED = 'bundled';

    /** SKILL.md frontmatter key: metadata.depthnet-tools — comma/space-separated plugin names. */
    public const META_TOOLS_KEY = 'depthnet-tools';

    /** Hard limits for one skill folder — a library entry is instructions + small scripts, not a dataset. */
    public const MAX_FILES = 1000;
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** `skill read` refuses files above this size outright (paging is by lines, below). */
    public const MAX_READ_BYTES = 2 * 1024 * 1024;

    /** Never walked, never hashed, never synced. */
    public const SKIP_DIRS = ['.git', '.svn', '.hg', 'node_modules', '__pycache__', '.venv', 'venv'];

    private ?string $root;
    private ?string $bundled;

    public function __construct(
        protected SkillMdParser $parser,
        protected TfIdfServiceInterface $tfIdfService,
        protected Skill $skillModel,
        protected SkillItem $skillItemModel,
        protected LoggerInterface $logger,
        ?string $libraryRoot = null,
        ?string $bundledRoot = null,
    ) {
        $this->root    = $libraryRoot;
        $this->bundled = $bundledRoot;
    }

    public function libraryRoot(): string
    {
        return $this->root ??= rtrim((string) config('sandbox.sandbox.shared_path', '/shared'), '/') . '/skills';
    }

    public function bundledRoot(): string
    {
        return $this->bundled ??= rtrim(resource_path('skills'), '/');
    }

    public function libraryRoots(): array
    {
        return [
            self::ORIGIN_USER    => $this->libraryRoot(),
            self::ORIGIN_BUNDLED => $this->bundledRoot(),
        ];
    }

    // ── Library ──────────────────────────────────────────────────────────────

    public function scanLibrary(): array
    {
        $this->ensureUserRoot();

        /** @var array<string, array> $out name => entry; first root wins */
        $out = [];

        foreach ($this->libraryRoots() as $origin => $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                    continue;
                }
                $dir = $root . '/' . $entry;
                if (is_link($dir) || !is_dir($dir) || !is_file($dir . '/SKILL.md')) {
                    continue;
                }

                // Same name already found in a higher-priority root: that one is
                // what resolveDir() returns — mark it as overriding this one.
                if (isset($out[$entry])) {
                    $out[$entry]['overrides'] = true;
                    continue;
                }

                $info = $this->inspect($dir, $entry);
                unset($info['parsed'], $info['layout']);
                $info['origin']    = $origin;
                $info['overrides'] = false;
                $out[$entry] = $info;
            }
        }

        $out = array_values($out);
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public function listForPreset(AiPreset $preset): array
    {
        $guests = $this->skillModel->newQuery()
            ->where('preset_id', $preset->id)
            ->fromLibrary()
            ->get(['number', 'source_path', 'source_hash'])
            ->keyBy('source_path');

        $skills = [];
        $seen = [];
        foreach ($this->scanLibrary() as $entry) {
            $row = $guests->get($entry['name']);
            $entry['attached'] = $row?->number;
            $entry['stale'] = $row !== null && $entry['hash'] !== null && $row->source_hash !== $entry['hash'];
            $skills[] = $entry;
            $seen[$entry['name']] = true;
        }

        $orphans = [];
        foreach ($guests as $path => $row) {
            if (!isset($seen[$path])) {
                $orphans[] = ['number' => (int) $row->number, 'name' => (string) $path];
            }
        }

        return ['skills' => $skills, 'orphans' => $orphans];
    }

    /**
     * Create the user library folder on first use, with a README saying what goes
     * there — so whoever opens shared/ sees where their skills belong. Not a
     * .gitkeep: shared/ is git-ignored and must hold nothing from the repo.
     * Silent on failure (read-only mount etc.): the library just shows empty.
     */
    private function ensureUserRoot(): void
    {
        $root = $this->libraryRoot();
        if (is_dir($root) || !@mkdir($root, 0775, true)) {
            return;
        }

        @file_put_contents($root . '/README.md', <<<'MD'
# Your skills

One folder per skill: `<name>/SKILL.md` (+ optional `scripts/`, `references/`, `assets/`).
Format: https://agentskills.io — `name` in the frontmatter must match the folder name.

Skills shipped with DepthNet live in `resources/skills/`. To change one, copy its folder
here and edit the copy: a folder here with the same name overrides the shipped one.

DepthNet tools a skill reveals when loaded:
`metadata: { depthnet-tools: "code, terminal" }` in the SKILL.md frontmatter.

MD);
    }

    // ── Attach / refresh ─────────────────────────────────────────────────────

    public function attach(AiPreset $preset, string $name): array
    {
        $name = trim($name);
        $location = $this->resolveLocation($name);
        if ($location === null) {
            return ['success' => false, 'message' => "Library skill \"{$name}\" not found.", 'skill_number' => null];
        }
        $dir = $location['dir'];

        $existing = $this->findGuest($preset, $name);
        if ($existing !== null) {
            $r = $this->refresh($preset, (int) $existing->number);
            return [
                'success'      => $r['success'],
                'message'      => "Already attached as skill #{$existing->number}. " . $r['message'],
                'skill_number' => (int) $existing->number,
            ];
        }

        $info = $this->inspect($dir, $name);
        if (!$info['valid']) {
            return [
                'success'      => false,
                'message'      => "Cannot import \"{$name}\": " . implode(' ', $info['errors']),
                'skill_number' => null,
            ];
        }
        $parsed   = $info['parsed'];
        $declared = $this->declaredTools($parsed);
        $origin   = $location['origin'];

        try {
            $number = DB::transaction(function () use ($preset, $name, $parsed, $info, $declared, $origin) {
                // Serialise number allocation per preset.
                $max = $this->skillModel->newQuery()
                    ->where('preset_id', $preset->id)
                    ->lockForUpdate()
                    ->max('number');
                $number = ((int) $max) + 1;

                $skill = $this->skillModel->newQuery()->create([
                    'preset_id'     => $preset->id,
                    'number'        => $number,
                    'title'         => $parsed->name,
                    'description'   => $parsed->description,
                    'tools'         => $declared,
                    'source'        => Skill::SOURCE_FILE,
                    'source_path'   => $name,
                    'source_hash'   => $info['hash'],
                    'source_meta'   => $this->buildMeta($parsed, $info, $origin, $declared),
                    'is_experience' => false,
                ]);

                $this->writeItems($skill, $parsed->items);

                return $number;
            });
        } catch (\Throwable $e) {
            $this->logger->error("SkillLibraryService::attach({$name}) error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error importing \"{$name}\": " . $e->getMessage(), 'skill_number' => null];
        }

        $msg = "Skill #{$number} imported from library: {$name} (" . count($parsed->items) . ' items)';
        if ($declared !== null) {
            $msg .= '; tools: ' . implode(', ', $declared) . ' (hidden until the skill is loaded)';
        }
        if ($parsed->warnings !== []) {
            $msg .= '. Warnings: ' . implode(' ', $parsed->warnings);
        }

        return ['success' => true, 'message' => $msg, 'skill_number' => $number];
    }

    public function refresh(AiPreset $preset, int $skillNumber, bool $force = false): array
    {
        $skill = $this->skillModel->newQuery()
            ->where('preset_id', $preset->id)
            ->where('number', $skillNumber)
            ->first();

        if ($skill === null) {
            return ['success' => false, 'message' => "Skill #{$skillNumber} not found.", 'changed' => false];
        }
        if (!$skill->isFromFile()) {
            return ['success' => false, 'message' => "Skill #{$skillNumber} is not a library skill.", 'changed' => false];
        }

        $location = is_string($skill->source_path) ? $this->resolveLocation($skill->source_path) : null;
        if ($location === null) {
            return ['success' => false, 'message' => "Library folder \"{$skill->source_path}\" is gone; skill #{$skillNumber} kept as is.", 'changed' => false];
        }
        $dir = $location['dir'];

        $info = $this->inspect($dir, (string) $skill->source_path);
        if (!$force && $info['hash'] !== null && $info['hash'] === $skill->source_hash) {
            return ['success' => true, 'message' => 'Up to date.', 'changed' => false];
        }
        if (!$info['valid']) {
            // Keep the last good import rather than wiping the skill.
            return ['success' => false, 'message' => "SKILL.md is invalid now, kept previous import: " . implode(' ', $info['errors']), 'changed' => false];
        }
        $parsed = $info['parsed'];

        $declared = $this->declaredTools($parsed);
        [$tools, $toolsNote] = $this->reconcileTools($skill, $declared);

        try {
            DB::transaction(function () use ($skill, $parsed, $info, $location, $declared, $tools) {
                // Row (number, is_experience, loaded_N state) stays; content is re-cut.
                // tools follow SKILL.md only while the user hasn't edited them (reconcileTools).
                $update = [
                    'title'       => $parsed->name,
                    'description' => $parsed->description,
                    'source_hash' => $info['hash'],
                    'source_meta' => $this->buildMeta($parsed, $info, $location['origin'], $declared),
                ];
                if ($tools !== false) {
                    $update['tools'] = $tools;
                }
                $skill->update($update);
                $this->skillItemModel->newQuery()->where('skill_id', $skill->id)->delete();
                $this->writeItems($skill, $parsed->items);
            });
        } catch (\Throwable $e) {
            $this->logger->error("SkillLibraryService::refresh(#{$skillNumber}) error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error refreshing: ' . $e->getMessage(), 'changed' => false];
        }

        return [
            'success' => true,
            'message' => 'Re-imported (' . count($parsed->items) . ' items).' . $toolsNote,
            'changed' => true,
        ];
    }

    // ── DepthNet tools from SKILL.md ─────────────────────────────────────────

    /**
     * metadata.depthnet-tools → normalized plugin names, or null when none.
     * Names are not checked against the registry here — SkillLoadService ignores
     * unknown names at read time, so a skill can name a plugin that is installed later.
     *
     * @return string[]|null
     */
    private function declaredTools(ParsedSkill $parsed): ?array
    {
        $raw = trim((string) ($parsed->metadata[self::META_TOOLS_KEY] ?? ''));
        if ($raw === '') {
            return null;
        }

        $names = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
            $n = strtolower($n);
            if (preg_match('/^[a-z0-9_-]+$/', $n)) {
                $names[$n] = true;
            }
        }

        return $names === [] ? null : array_keys($names);
    }

    /**
     * Decide the skill's tools after a refresh.
     *
     * Rule: tools follow SKILL.md until the user edits them. "Edited" = current
     * tools differ from what SKILL.md declared at the previous import
     * (source_meta.declared_tools). Skills imported before this existed have no
     * record and no tools — they count as unedited and pick up the declaration.
     *
     * @param  string[]|null $declared
     * @return array{0: string[]|null|false, 1: string}  [tools to write — false = leave as is, note]
     */
    private function reconcileTools(Skill $skill, ?array $declared): array
    {
        $previous = $skill->source_meta['declared_tools'] ?? null;
        $current  = $skill->getToolNames() ?: null;

        if ($this->sameToolSet($declared, $previous)) {
            return [false, '']; // declaration unchanged — nothing to follow
        }

        if (!$this->sameToolSet($current, $previous)) {
            $now = $declared === null ? 'no tools' : implode(', ', $declared);
            return [false, " SKILL.md now declares {$now}; your edited tools were kept."];
        }

        if ($declared !== null && $skill->isExperience()) {
            return [false, ' SKILL.md declares tools, but this skill is an experience skill; tools were not set.'];
        }

        return [$declared, ' Tools updated from SKILL.md: ' . ($declared === null ? 'none' : implode(', ', $declared)) . '.'];
    }

    private function sameToolSet(?array $a, ?array $b): bool
    {
        $a = $a ?? [];
        $b = $b ?? [];
        sort($a);
        sort($b);
        return $a === $b;
    }

    public function refreshAll(AiPreset $preset): array
    {
        $result = ['refreshed' => [], 'missing' => [], 'failed' => []];

        $guests = $this->skillModel->newQuery()
            ->where('preset_id', $preset->id)
            ->fromLibrary()
            ->orderBy('number')
            ->get();

        foreach ($guests as $skill) {
            $n = (int) $skill->number;
            if ($this->skillDirectory($skill) === null) {
                $result['missing'][] = $n;
                continue;
            }
            $r = $this->refresh($preset, $n);
            if (!$r['success']) {
                $result['failed'][$n] = $r['message'];
            } elseif ($r['changed']) {
                $result['refreshed'][] = $n;
            }
        }

        return $result;
    }

    // ── Paths & hashing ──────────────────────────────────────────────────────

    public function skillDirectory(Skill $skill): ?string
    {
        if (!$skill->isFromFile() || !is_string($skill->source_path)) {
            return null;
        }
        return $this->resolveDir($skill->source_path);
    }

    public function listFiles(Skill $skill): ?array
    {
        $dir = $this->skillDirectory($skill);
        if ($dir === null) {
            return null;
        }
        $files = $this->walk($dir);
        if ($files === null) {
            return null;
        }
        return array_map(fn ($abs) => (int) @filesize($abs), $files);
    }

    public function readFile(Skill $skill, string $relativePath): array
    {
        $dir = $this->skillDirectory($skill);
        if ($dir === null) {
            return ['success' => false, 'message' => "Skill #{$skill->number} has no library folder."];
        }

        $rel = trim(str_replace('\\', '/', $relativePath));
        $rel = preg_replace('#^\./+#', '', $rel);
        if ($rel === '' || str_contains($rel, "\0")) {
            return ['success' => false, 'message' => 'Give a file path relative to the skill folder, e.g. references/forms.md.'];
        }
        if (str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
            return ['success' => false, 'message' => "Path \"{$relativePath}\" leaves the skill folder; use a path relative to it."];
        }

        $rel  = preg_replace('#/+#', '/', $rel);
        $real = realpath($dir . '/' . $rel);

        // SKILL.md authors write FORMS.md for forms.md; on a case-sensitive FS that
        // misses. Fall back to a UNIQUE case-insensitive match among the listed files.
        if ($real === false) {
            $matches = array_values(array_filter(
                array_keys($this->walk($dir) ?? []),
                fn ($f) => strcasecmp($f, rtrim($rel, '/')) === 0
            ));
            if (count($matches) === 1) {
                $rel  = $matches[0];
                $real = realpath($dir . '/' . $rel);
            }
        }
        // realpath must equal the literal path: any symlink on the way (even one that
        // stays inside the folder) is refused — listing and sandbox sync skip them too.
        if ($real === false || $real !== $dir . '/' . rtrim($rel, '/')) {
            return ['success' => false, 'message' => "No file \"{$rel}\" in skill \"{$skill->source_path}\"."];
        }
        if (is_dir($real)) {
            return ['success' => false, 'message' => "\"{$rel}\" is a directory; omit the path to list the skill's files."];
        }
        if (!is_file($real) || !is_readable($real)) {
            return ['success' => false, 'message' => "Cannot read \"{$rel}\"."];
        }

        $size = (int) filesize($real);
        if ($size > self::MAX_READ_BYTES) {
            return ['success' => false, 'message' => "\"{$rel}\" is too large to read here (" . $this->humanSize($size)
                . '); work with it from the sandbox copy instead.'];
        }

        $content = (string) file_get_contents($real);
        if (str_contains(substr($content, 0, 8192), "\0") || !mb_check_encoding($content, 'UTF-8')) {
            return ['success' => false, 'message' => "\"{$rel}\" is a binary file (" . $this->humanSize($size)
                . '); use it from the sandbox copy, not as text.'];
        }

        return [
            'success' => true,
            'message' => 'ok',
            'content' => str_replace(["\r\n", "\r"], "\n", $content),
            'size'    => $size,
            'path'    => substr($real, strlen($dir) + 1),
        ];
    }

    /**
     * Compact top-level picture of a skill folder for the agent:
     *   ["scripts/ (8)", "forms.md", "reference.md", "LICENSE.txt"]
     * Directories first (scripts/ is what tasks mention), then files, license last.
     * SKILL.md itself is left out — it IS the skill body.
     *
     * @param string[] $relPaths
     * @return string[]
     */
    public static function summarizeLayout(array $relPaths): array
    {
        $dirs = [];
        $files = [];
        $legal = [];
        foreach ($relPaths as $rel) {
            if ($rel === 'SKILL.md') {
                continue;
            }
            $slash = strpos($rel, '/');
            if ($slash !== false) {
                $d = substr($rel, 0, $slash);
                $dirs[$d] = ($dirs[$d] ?? 0) + 1;
            } elseif (preg_match('/^(LICEN[CS]E|NOTICE|COPYING)/i', $rel)) {
                $legal[] = $rel;
            } else {
                $files[] = $rel;
            }
        }
        ksort($dirs);
        sort($files);

        $out = [];
        foreach ($dirs as $d => $c) {
            $out[] = "{$d}/ ({$c})";
        }
        return array_merge($out, $files, $legal);
    }

    public function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }

    public function folderHash(string $dir): ?string
    {
        $files = $this->walk($dir);
        return $files === null ? null : $this->hashFiles($files);
    }

    /** @param array<string,string> $files rel => abs, already sorted */
    private function hashFiles(array $files): ?string
    {
        $ctx = hash_init('sha256');
        foreach ($files as $rel => $abs) {
            $h = @hash_file('sha256', $abs);
            if ($h === false) {
                return null;
            }
            hash_update($ctx, $rel . "\0" . $h . "\n");
        }
        return hash_final($ctx);
    }

    /**
     * Name → absolute dir inside the library, or null. Guards against `..`, absolute
     * names and symlinked skill folders pointing outside the library.
     */
    private function resolveDir(string $name): ?string
    {
        return $this->resolveLocation($name)['dir'] ?? null;
    }

    /**
     * Name → [dir, origin] from the first root that has it, or null. Every root
     * gets the full guard on its own: `..`, absolute names and symlinked skill
     * folders pointing outside THAT root are refused.
     *
     * @return array{dir: string, origin: string}|null
     */
    private function resolveLocation(string $name): ?array
    {
        if (!SkillMdParser::isValidName($name)) {
            return null;
        }

        foreach ($this->libraryRoots() as $origin => $rootPath) {
            $root = realpath($rootPath);
            if ($root === false) {
                continue;
            }

            $candidate = $root . '/' . $name;
            if (is_link($candidate)) {
                // Never followed. Skipped like scanLibrary() skips it, so the list
                // and resolution agree (a bundled skill of that name still resolves).
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || !is_dir($real) || !str_starts_with($real, $root . '/')) {
                continue;
            }
            if (!is_file($real . '/SKILL.md')) {
                continue;
            }
            return ['dir' => $real, 'origin' => $origin];
        }

        return null;
    }

    /**
     * Relative path → absolute path for every regular file, sorted. Symlinks and
     * SKIP_DIRS are skipped. Null if the folder exceeds MAX_FILES / MAX_BYTES.
     *
     * @return array<string,string>|null
     */
    private function walk(string $dir): ?array
    {
        $dir = rtrim($dir, '/');
        $out = [];
        $bytes = 0;
        $stack = [''];

        while ($stack !== []) {
            $rel = array_pop($stack);
            $abs = $rel === '' ? $dir : $dir . '/' . $rel;
            $entries = @scandir($abs);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $r = $rel === '' ? $e : $rel . '/' . $e;
                $a = $dir . '/' . $r;
                if (is_link($a)) {
                    continue;
                }
                if (is_dir($a)) {
                    if (!in_array($e, self::SKIP_DIRS, true)) {
                        $stack[] = $r;
                    }
                    continue;
                }
                if (!is_file($a)) {
                    continue;
                }
                $out[$r] = $a;
                $bytes += (int) @filesize($a);
                if (count($out) > self::MAX_FILES || $bytes > self::MAX_BYTES) {
                    return null;
                }
            }
        }

        ksort($out, SORT_STRING);
        return $out;
    }

    /** Parse + hash + file facts for one library folder. */
    private function inspect(string $dir, string $name): array
    {
        $parsed = $this->parser->parseFile($dir . '/SKILL.md', $name);
        $files = $this->walk($dir);

        $errors = $parsed->errors;
        if ($files === null) {
            $errors[] = 'Folder too large (max ' . self::MAX_FILES . ' files / ' . (self::MAX_BYTES >> 20) . ' MB).';
        }

        $files ??= [];
        $others = array_filter(array_keys($files), fn ($r) => $r !== 'SKILL.md');
        $hasScripts = (bool) array_filter($others, fn ($r) => str_starts_with($r, 'scripts/'));

        return [
            'name'        => $name,
            'valid'       => $errors === [],
            'description' => $parsed->description,
            'errors'      => $errors,
            'warnings'    => $parsed->warnings,
            'has_files'   => $others !== [],
            'has_scripts' => $hasScripts,
            'file_count'  => count($files),
            'hash'        => $files === [] ? null : $this->hashFiles($files),
            'tools'       => $this->declaredTools($parsed) ?? [],
            // Internal: scanLibrary() strips these; attach()/refresh() use them.
            // layout comes from THIS folder's walk — the old code re-walked
            // libraryRoot()/name, which is the wrong folder for a bundled skill.
            'layout'      => self::summarizeLayout(array_keys($files)),
            'parsed'      => $parsed,
        ];
    }

    /** @param string[]|null $declaredTools */
    private function buildMeta(ParsedSkill $parsed, array $info, string $origin, ?array $declaredTools): array
    {
        return [
            'name'           => $parsed->declaredName !== '' ? $parsed->declaredName : $parsed->name,
            'origin'         => $origin,
            'license'        => $parsed->license,
            'compatibility'  => $parsed->compatibility,
            'allowed_tools'  => $parsed->allowedTools, // verbatim, never mapped to DepthNet tools
            'metadata'       => $parsed->metadata,
            // What SKILL.md declared at this import — reconcileTools() compares the
            // skill's current tools to it to tell "user edited" from "follows file".
            'declared_tools' => $declaredTools,
            'has_files'      => $info['has_files'],
            'has_scripts'    => $info['has_scripts'],
            'file_count'     => $info['file_count'],
            'layout'         => $info['layout'],
            'warnings'       => $parsed->warnings,
            'imported_at'    => now()->toIso8601String(),
        ];
    }

    /** @param string[] $items */
    private function writeItems(Skill $skill, array $items): void
    {
        foreach (array_values($items) as $i => $content) {
            $this->skillItemModel->newQuery()->create([
                'skill_id'     => $skill->id,
                'number'       => $i + 1,
                'content'      => $content,
                'tfidf_vector' => $this->tfIdfService->vectorize($content),
            ]);
        }
    }

    private function findGuest(AiPreset $preset, string $name): ?Skill
    {
        return $this->skillModel->newQuery()
            ->where('preset_id', $preset->id)
            ->where('source_path', $name)
            ->first();
    }
}

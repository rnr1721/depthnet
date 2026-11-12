<?php

namespace App\Services\Agent\Skills;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\Skills\SkillLibraryServiceInterface;
use App\Models\AiPreset;
use App\Models\Skill;
use App\Models\SkillItem;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SkillLibraryService implements SkillLibraryServiceInterface
{
    /** Hard limits for one skill folder — a library entry is instructions + small scripts, not a dataset. */
    public const MAX_FILES = 1000;
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** `skill read` refuses files above this size outright (paging is by lines, below). */
    public const MAX_READ_BYTES = 2 * 1024 * 1024;

    /** Never walked, never hashed, never synced. */
    public const SKIP_DIRS = ['.git', '.svn', '.hg', 'node_modules', '__pycache__', '.venv', 'venv'];

    private ?string $root;

    public function __construct(
        protected SkillMdParser $parser,
        protected TfIdfServiceInterface $tfIdfService,
        protected Skill $skillModel,
        protected SkillItem $skillItemModel,
        protected LoggerInterface $logger,
        ?string $libraryRoot = null,
    ) {
        $this->root = $libraryRoot;
    }

    public function libraryRoot(): string
    {
        return $this->root ??= rtrim((string) config('sandbox.sandbox.shared_path', '/shared'), '/') . '/skills';
    }

    // ── Library ──────────────────────────────────────────────────────────────

    public function scanLibrary(): array
    {
        $root = $this->libraryRoot();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $dir = $root . '/' . $entry;
            if (is_link($dir) || !is_dir($dir) || !is_file($dir . '/SKILL.md')) {
                continue;
            }
            $info = $this->inspect($dir, $entry);
            unset($info['parsed']);
            $out[] = $info;
        }

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

    // ── Attach / refresh ─────────────────────────────────────────────────────

    public function attach(AiPreset $preset, string $name): array
    {
        $name = trim($name);
        $dir = $this->resolveDir($name);
        if ($dir === null) {
            return ['success' => false, 'message' => "Library skill \"{$name}\" not found.", 'skill_number' => null];
        }

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
        $parsed = $info['parsed'];

        try {
            $number = DB::transaction(function () use ($preset, $name, $parsed, $info) {
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
                    'tools'         => null,
                    'source'        => Skill::SOURCE_FILE,
                    'source_path'   => $name,
                    'source_hash'   => $info['hash'],
                    'source_meta'   => $this->buildMeta($parsed, $info),
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

        $dir = $this->skillDirectory($skill);
        if ($dir === null) {
            return ['success' => false, 'message' => "Library folder \"{$skill->source_path}\" is gone; skill #{$skillNumber} kept as is.", 'changed' => false];
        }

        $info = $this->inspect($dir, (string) $skill->source_path);
        if (!$force && $info['hash'] !== null && $info['hash'] === $skill->source_hash) {
            return ['success' => true, 'message' => 'Up to date.', 'changed' => false];
        }
        if (!$info['valid']) {
            // Keep the last good import rather than wiping the skill.
            return ['success' => false, 'message' => "SKILL.md is invalid now, kept previous import: " . implode(' ', $info['errors']), 'changed' => false];
        }
        $parsed = $info['parsed'];

        try {
            DB::transaction(function () use ($skill, $parsed, $info) {
                // Row (number, tools, is_experience, loaded_N state) stays; content is re-cut.
                $skill->update([
                    'title'       => $parsed->name,
                    'description' => $parsed->description,
                    'source_hash' => $info['hash'],
                    'source_meta' => $this->buildMeta($parsed, $info),
                ]);
                $this->skillItemModel->newQuery()->where('skill_id', $skill->id)->delete();
                $this->writeItems($skill, $parsed->items);
            });
        } catch (\Throwable $e) {
            $this->logger->error("SkillLibraryService::refresh(#{$skillNumber}) error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error refreshing: ' . $e->getMessage(), 'changed' => false];
        }

        return ['success' => true, 'message' => 'Re-imported (' . count($parsed->items) . ' items).', 'changed' => true];
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
        if (!SkillMdParser::isValidName($name)) {
            return null;
        }

        $root = realpath($this->libraryRoot());
        if ($root === false) {
            return null;
        }

        $candidate = $root . '/' . $name;
        if (is_link($candidate)) {
            return null;
        }
        $real = realpath($candidate);
        if ($real === false || !is_dir($real) || !str_starts_with($real, $root . '/')) {
            return null;
        }
        if (!is_file($real . '/SKILL.md')) {
            return null;
        }
        return $real;
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
            // Internal: scanLibrary() strips it; attach()/refresh() use it.
            'parsed'      => $parsed,
        ];
    }

    private function buildMeta(ParsedSkill $parsed, array $info): array
    {
        return [
            'name'          => $parsed->declaredName !== '' ? $parsed->declaredName : $parsed->name,
            'license'       => $parsed->license,
            'compatibility' => $parsed->compatibility,
            'allowed_tools' => $parsed->allowedTools, // verbatim, never mapped to DepthNet tools
            'metadata'      => $parsed->metadata,
            'has_files'     => $info['has_files'],
            'has_scripts'   => $info['has_scripts'],
            'file_count'    => $info['file_count'],
            'layout'        => self::summarizeLayout(array_keys($this->walk($this->libraryRoot() . '/' . $parsed->name) ?? [])),
            'warnings'      => $parsed->warnings,
            'imported_at'   => now()->toIso8601String(),
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

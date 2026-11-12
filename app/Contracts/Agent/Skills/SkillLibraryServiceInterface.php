<?php

namespace App\Contracts\Agent\Skills;

use App\Models\AiPreset;
use App\Models\Skill;

/**
 * The shared skill library: {sandbox.sandbox.shared_path}/skills/{name}/SKILL.md (+ scripts/, references/, assets/ …).
 *
 * Importing ("attaching") a library skill into a preset creates a regular agent_skills
 * row with source='file'. Its title/description/items mirror SKILL.md and are re-cut on
 * refresh when the folder hash changes; skill number, tools, is_experience and the
 * loaded_{N} state survive a refresh because the row itself is kept.
 *
 * Detaching is just deleting the row (SkillService::deleteSkill) — the library folder
 * is never touched by DepthNet.
 */
interface SkillLibraryServiceInterface
{
    /** Absolute path of the library root (may not exist yet). */
    public function libraryRoot(): string;

    /**
     * Every folder in the library that has a SKILL.md, parsed and hashed.
     *
     * @return array<int, array{
     *     name: string,
     *     valid: bool,
     *     description: string,
     *     errors: string[],
     *     warnings: string[],
     *     has_files: bool,
     *     has_scripts: bool,
     *     file_count: int,
     *     hash: string|null,
     * }>
     */
    public function scanLibrary(): array;

    /**
     * scanLibrary() annotated with this preset's state:
     *   attached: skill number or null; stale: attached but the folder changed since import;
     *   plus `orphans`: guest skills whose library folder is gone.
     *
     * @return array{skills: array<int, array>, orphans: array<int, array{number:int, name:string}>}
     */
    public function listForPreset(AiPreset $preset): array;

    /**
     * Import a library skill into the preset. If it is already attached, refreshes it instead.
     *
     * @return array{success: bool, message: string, skill_number: int|null}
     */
    public function attach(AiPreset $preset, string $name): array;

    /**
     * Re-import an attached guest skill if its folder changed (or always, with $force).
     *
     * @return array{success: bool, message: string, changed: bool}
     */
    public function refresh(AiPreset $preset, int $skillNumber, bool $force = false): array;

    /**
     * Refresh every guest skill of the preset whose folder changed.
     *
     * @return array{refreshed: int[], missing: int[], failed: array<int,string>}
     */
    public function refreshAll(AiPreset $preset): array;

    /**
     * Absolute, realpath-checked library folder of a guest skill, or null
     * (native skill, folder removed, or path escapes the library).
     */
    public function skillDirectory(Skill $skill): ?string;

    /**
     * Files of a guest skill folder: relative path => size in bytes, sorted.
     * Symlinks and junk dirs (.git, node_modules, __pycache__ …) are skipped.
     * Null when the skill has no library folder.
     *
     * @return array<string,int>|null
     */
    public function listFiles(Skill $skill): ?array;

    /**
     * Read one file inside a guest skill folder. The path is relative to the skill
     * root; `..`, absolute paths and symlinks escaping the folder are refused
     * (realpath + prefix guard). Binary files are refused, not dumped.
     *
     * @return array{success: bool, message: string, content?: string, size?: int, path?: string}
     */
    public function readFile(Skill $skill, string $relativePath): array;

    /**
     * Human-readable size string for a byte count (e.g. 1234567 → "1.18 MB").
     *
     * @param int $bytes
     * @return string
     */
    public function humanSize(int $bytes): string;

    /**
     * Content hash of a library folder (relative paths + file hashes, symlinks skipped).
     * The same value is stored as agent_skills.source_hash and later used for sandbox sync.
     */
    public function folderHash(string $dir): ?string;
}

<?php

namespace App\Services\Agent\Skills;

use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Contracts\Agent\Skills\SkillLibraryServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Models\AiPreset;
use App\Models\Skill;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Delivers a loaded standard skill's folder into the preset's sandbox and writes
 * the header the agent reads above the skill body.
 *
 * ── When ─────────────────────────────────────────────────────────────────────
 * Called from ContextInjectionService for every LOADED guest skill, every cycle.
 * The hot path is cheap: one metadata read + one file_exists. Docker is touched
 * only when something changed (first load, library refresh, other sandbox,
 * folder deleted by the agent) and once per sandbox for the environment probe.
 *
 * ── State ────────────────────────────────────────────────────────────────────
 *   plugins.skills.synced_{N} = "{sandbox_id}:{name}:{hash}"   (preset metadata)
 *   cache  depthnet:skills:env:{sandbox_id}:{type}             (probe result, 1 day)
 *
 * The probe result lives in Cache, not on the manager: DockerSandboxManager is
 * bound with bind(), a fresh instance per resolve, so it cannot keep state.
 *
 * ── What it never does ───────────────────────────────────────────────────────
 * Install dependencies, or offer the host `shell` plugin. Scripts run in the
 * sandbox through `terminal`; if they fail on a missing package, the agent
 * installs it with the command the header names.
 */
class SkillSandboxSync
{
    private const NS         = 'skills';
    private const KEY_SYNCED = 'synced_';

    /** Folder inside the sandbox home that holds skill copies. */
    public const REMOTE_ROOT = '.skills';

    private const ENV_TTL      = 86400;
    private const ENV_FAIL_TTL = 60;

    /** Per-request memo so several loaded skills share one environment lookup. */
    private array $envMemo = [];

    public function __construct(
        protected SandboxManagerInterface $sandboxes,
        protected PresetMetadataServiceInterface $presetMeta,
        protected PluginMetadataServiceInterface $meta,
        protected SkillLibraryServiceInterface $library,
        protected CacheRepository $cache,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    /** The preset's assigned sandbox id, straight from metadata (no docker call). */
    public function currentSandboxId(AiPreset $preset): ?string
    {
        $id = $this->presetMeta->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Make sure the skill's folder is present and current in the sandbox.
     *
     * @return array{status: string, sandbox_id: ?string, dir: ?string, message: string}
     *   status: no_files | no_sandbox | missing_library | synced | updated | error
     */
    public function ensureSynced(AiPreset $preset, Skill $skill): array
    {
        $r = ['status' => 'no_files', 'sandbox_id' => null, 'dir' => null, 'message' => ''];

        if (!$skill->hasBundledFiles()) {
            return $r;
        }

        $sid = $this->currentSandboxId($preset);
        if ($sid === null) {
            return ['status' => 'no_sandbox'] + $r;
        }
        $r['sandbox_id'] = $sid;

        $localDir = $this->library->skillDirectory($skill);
        if ($localDir === null) {
            return ['status' => 'missing_library', 'message' => "library folder \"{$skill->source_path}\" is missing"] + $r;
        }

        $name   = (string) $skill->source_path;
        $remote = self::REMOTE_ROOT . '/' . $name;
        $r['dir'] = rtrim($this->sandboxes->getHomeDirectory($sid), '/') . '/' . $remote;

        $marker = "{$sid}:{$name}:{$skill->source_hash}";
        $key    = self::KEY_SYNCED . (int) $skill->number;

        try {
            if ($this->meta->get($preset, self::NS, $key) === $marker
                && $this->sandboxes->pathExists($sid, $remote . '/SKILL.md')) {
                return ['status' => 'synced'] + $r;
            }

            $this->sandboxes->putDirectory($sid, $localDir, $remote, SkillLibraryService::SKIP_DIRS);
            $this->meta->set($preset, self::NS, $key, $marker);

            $this->logger->info('SkillSandboxSync: skill delivered', [
                'preset_id' => $preset->getId(),
                'skill'     => $skill->number,
                'name'      => $name,
                'sandbox'   => $sid,
            ]);

            return ['status' => 'updated'] + $r;
        } catch (\Throwable $e) {
            $this->logger->warning('SkillSandboxSync: delivery failed', [
                'preset_id' => $preset->getId(),
                'skill'     => $skill->number,
                'sandbox'   => $sid,
                'error'     => $e->getMessage(),
            ]);
            return ['status' => 'error', 'message' => $e->getMessage()] + $r;
        }
    }

    // ── Environment ──────────────────────────────────────────────────────────

    /**
     * Probe result for the sandbox (cached per sandbox id + type). On the first
     * miss also bootstraps the home once: ~/.tmp, a .bashrc restored from the
     * image skeleton if the bind mount hid it, and TMPDIR pointing at ~/.tmp
     * (the container's /tmp is a noexec tmpfs that breaks pip builds).
     *
     * @return array<string,string>|null  null when the sandbox can't be reached
     */
    public function environment(AiPreset $preset, string $sandboxId): ?array
    {
        $type = (string) $this->presetMeta->getPluginMetadata($preset, 'sandbox', 'sandbox_type', '');
        $key  = "depthnet:skills:env:{$sandboxId}:{$type}";

        if (array_key_exists($key, $this->envMemo)) {
            return $this->envMemo[$key];
        }

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $this->envMemo[$key] = ($cached['ok'] ?? false) ? $cached['env'] : null;
        }

        try {
            $env = $this->sandboxes->probe($sandboxId);
            $this->bootstrapHome($sandboxId);
            $this->cache->put($key, ['ok' => true, 'env' => $env], self::ENV_TTL);
            return $this->envMemo[$key] = $env;
        } catch (\Throwable $e) {
            // Stopped container etc. Short negative cache: don't docker-exec every cycle.
            $this->cache->put($key, ['ok' => false], self::ENV_FAIL_TTL);
            $this->logger->warning('SkillSandboxSync: probe failed', ['sandbox' => $sandboxId, 'error' => $e->getMessage(), 'class' => get_class($e), 'user' => get_current_user() . '/' . (function_exists('posix_geteuid') ? posix_geteuid() : '?')]);
            return $this->envMemo[$key] = null;
        }
    }

    /** Forget the cached probe (e.g. after a sandbox reset with another image). */
    public function forgetEnvironment(AiPreset $preset, string $sandboxId): void
    {
        $type = (string) $this->presetMeta->getPluginMetadata($preset, 'sandbox', 'sandbox_type', '');
        $this->cache->forget("depthnet:skills:env:{$sandboxId}:{$type}");
    }

    private function bootstrapHome(string $sandboxId): void
    {
        $script = <<<'SH'
mkdir -p "$HOME/.tmp"
if [ ! -f "$HOME/.bashrc" ]; then
  for s in /opt/home-skel/.bashrc /etc/skel/.bashrc; do [ -f "$s" ] && cp "$s" "$HOME/.bashrc" && break; done
fi
grep -qs 'DepthNet: TMPDIR' "$HOME/.bashrc" || printf '\n# DepthNet: TMPDIR (sandbox /tmp is noexec)\nexport TMPDIR="$HOME/.tmp"\n' >> "$HOME/.bashrc"
SH;
        try {
            $r = $this->sandboxes->executeCommand($sandboxId, $script, 'sandbox-user', 15);
            if ($r->exitCode !== 0) {
                $this->logger->warning('SkillSandboxSync: bootstrap exited non-zero', [
                    'sandbox' => $sandboxId, 'exit' => $r->exitCode, 'error' => trim($r->error),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SkillSandboxSync: bootstrap failed', ['sandbox' => $sandboxId, 'error' => $e->getMessage(), 'class' => get_class($e), 'user' => get_current_user() . '/' . (function_exists('posix_geteuid') ? posix_geteuid() : '?')]);
        }
    }

    // ── Header ───────────────────────────────────────────────────────────────

    /**
     * The block the agent reads between the description and the items of a
     * loaded standard skill. Null for a guest skill that ships nothing but SKILL.md.
     */
    public function describe(AiPreset $preset, Skill $skill): ?string
    {
        if (!$skill->hasBundledFiles()) {
            return null;
        }

        $name = (string) $skill->source_path;
        $sync = $this->ensureSynced($preset, $skill);
        $read = "`skill read {$name} <path>`";

        $lines = ['── Standard skill: files ──'];

        switch ($sync['status']) {
            case 'no_sandbox':
                $lines[] = "No sandbox is assigned to you, so this skill's scripts cannot run here. "
                    . "Read its files with {$read}; do not search the disk for them.";
                return implode("\n", $lines);

            case 'missing_library':
                $lines[] = "The skill's files are unavailable ({$sync['message']}). Work from the text below only.";
                return implode("\n", $lines);

            case 'error':
                $lines[] = "Could not copy the skill's files into your sandbox ({$sync['message']}). "
                    . "Read them with {$read}; scripts cannot run until this is fixed — tell the user.";
                return implode("\n", $lines);
        }

        $dir = $sync['dir'];
        $lines[] = "Folder in your sandbox: {$dir} — a copy of the library, replaced when the skill is updated; "
            . 'keep your own files outside it.';
        $lines[] = "Paths in this skill (scripts/…, other .md files, \"this file's directory\") are relative to that folder: "
            . "cd {$dir} before running its scripts. Without the sandbox: {$read}.";

        $env = $sync['sandbox_id'] !== null ? $this->environment($preset, $sync['sandbox_id']) : null;
        $lines[] = $env !== null
            ? 'Sandbox: ' . $this->environmentLine($env)
            : 'Sandbox: not reachable right now (stopped?) — scripts may fail until it runs.';

        $lines[] = 'Tools: ' . $this->toolsLine($preset, $dir);

        return implode("\n", $lines);
    }

    /** "Ubuntu 22.04.5 LTS · python 3.10 · install: pip3 install --user <pkg> · node v18 · no sudo · TMPDIR=~/.tmp …" */
    public function environmentLine(array $env): string
    {
        $parts = [];
        if (($env['os'] ?? '') !== '') {
            $parts[] = $env['os'];
        }

        $sudo   = ($env['sudo'] ?? '0') === '1';
        $python = $env['python'] ?? '';
        $venv   = $env['venv'] ?? '';
        $pip    = $env['pip'] ?? 'none';

        if ($python === '') {
            $parts[] = 'no python3';
        } else {
            $parts[] = "python {$python}";
            if ($venv !== '' && ($env['venv_writable'] ?? '0') === '1') {
                $parts[] = "install: pip install <pkg> (venv {$venv})";
            } elseif ($venv !== '' && $sudo) {
                $parts[] = "install: sudo {$venv}/bin/pip install <pkg> (venv is root-owned)";
            } elseif ($pip === 'pip3') {
                $parts[] = 'install: pip3 install --user <pkg>';
            } elseif ($pip === 'module') {
                $parts[] = 'install: python3 -m pip install --user <pkg>';
            } elseif ($sudo) {
                $parts[] = 'no pip: sudo apt-get install -y python3-pip first';
            } else {
                $parts[] = 'no pip and no sudo: Python packages cannot be installed here';
            }
        }

        if (($env['node'] ?? '') !== '') {
            $parts[] = "node {$env['node']}";
        }

        if ($sudo) {
            $parts[] = 'sudo available (apt installs are lost on sandbox reset; ~ is kept)';
        } elseif (!str_contains(end($parts) ?: '', 'no sudo')) {
            $parts[] = 'no sudo';
        }

        if (($env['tmp_noexec'] ?? '0') === '1') {
            $parts[] = '/tmp is noexec — TMPDIR=~/.tmp (set in ~/.bashrc; export it in an older shell)';
        }

        $mem = $env['mem_limit'] ?? '';
        if (ctype_digit($mem) && (int) $mem > 0 && (int) $mem < 1 << 40) {
            $parts[] = 'memory ' . round((int) $mem / 1048576) . ' MB';
        }

        return implode(' · ', $parts);
    }

    /**
     * Map the skill's generic tool vocabulary onto what THIS preset actually has.
     * Never mentions `shell` (host execution) — skill scripts belong in the sandbox.
     */
    private function toolsLine(AiPreset $preset, string $dir): string
    {
        $terminal = $this->toolState($preset, 'terminal');
        $code     = $this->toolState($preset, 'code');

        $parts = [];
        $parts[] = match (true) {
            $terminal['state'] === 'on'
                => 'the skill\'s "bash"/shell commands → the terminal tool; long commands keep running, check the terminal screen before assuming they finished',
            $terminal['state'] === 'hidden'
                => 'the terminal tool is hidden right now — `skill load ' . $terminal['via'] . '` first to run the skill\'s commands',
            default
            => 'no terminal tool is enabled for you, so the skill\'s scripts cannot run — tell the user if the task needs them',
        };

        if ($code['state'] === 'on') {
            $parts[] = "reading/editing files → the code tool with absolute paths ({$dir}/…)";
        } elseif ($code['state'] === 'hidden') {
            $parts[] = 'file editing (code tool) is hidden — `skill load ' . $code['via'] . '` for it';
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * @return array{state: string, via: string}  state: on | hidden | off; via: "3" or "3 or 5"
     */
    private function toolState(AiPreset $preset, string $plugin): array
    {
        try {
            // Lazy: registry-dependent services must not be constructor-injected (container cycle).
            /** @var \App\Contracts\Agent\PluginRegistryInterface $registry */
            $registry = app(\App\Contracts\Agent\PluginRegistryInterface::class);
            $instance = $registry->allRegistered()[$plugin] ?? null;
            if ($instance === null) {
                return ['state' => 'off', 'via' => ''];
            }

            $disabled = $preset->getPluginsDisabled();
            $disabled = is_string($disabled) ? array_map('trim', explode(',', $disabled)) : (array) $disabled;
            if (in_array($plugin, $disabled, true)) {
                return ['state' => 'off', 'via' => ''];
            }

            $ctx = app(\App\Contracts\Agent\PluginExecutionContextBuilderInterface::class)->build($instance, $preset);
            if (!$ctx->enabled) {
                return ['state' => 'off', 'via' => ''];
            }

            /** @var SkillLoadService $load */
            $load = app(SkillLoadService::class);
            if (!in_array($plugin, $load->hiddenToolNames($preset), true)) {
                return ['state' => 'on', 'via' => ''];
            }

            $owners = Skill::query()
                ->where('preset_id', $preset->getId())
                ->orderBy('number')
                ->get()
                ->filter(fn (Skill $s) => in_array($plugin, $s->getToolNames(), true))
                ->pluck('number')
                ->all();

            return ['state' => 'hidden', 'via' => implode(' or ', $owners)];
        } catch (\Throwable $e) {
            $this->logger->info('SkillSandboxSync: tool state unknown', ['plugin' => $plugin, 'error' => $e->getMessage()]);
            return ['state' => 'on', 'via' => '']; // don't block the agent on our own failure
        }
    }
}

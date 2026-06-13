<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Spawn\SpawnServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Exceptions\PresetException;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHandoffTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * SpawnPlugin
 *
 * Allows an agent to create, manage, and communicate with ephemeral child
 * presets ("spawns") at runtime. Spawns are pure instruments — no identity,
 * memory, or personality plugins. The parent agent owns all its spawns;
 * they are deleted automatically when the parent is deleted.
 *
 * Available methods (some toggled by config):
 *   spawn  — create a new child preset with a custom system prompt
 *   list   — list live spawns owned by this preset
 *   read   — read the active system prompt of a spawn
 *   edit   — search/replace in a spawn's system prompt
 *   send   — handoff a message to a spawn and transfer control
 *   reset  — wipe a spawn's runtime data (messages, memory, …)
 *   kill   — delete a spawn
 *   killall — delete all spawns owned by this preset
 */
class SpawnPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHandoffTrait;

    public function __construct(
        protected SpawnServiceInterface $spawnService,
        protected PresetServiceInterface $presetService,
        protected PlaceholderServiceInterface $placeholderService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'spawn';
    }

    public function getDescription(array $config = []): string
    {
        return 'Create and manage ephemeral child presets (spawns) as runtime instruments. '
            . 'Spawn a tool with a custom prompt, send it tasks via handoff, then kill it when done.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [];

        $instructions[] = 'Create a spawn with a practical system prompt:'
            . "\n" . '[spawn spawn]slug: json_validator'
            . "\n" . 'prompt: You are a JSON validator. Receive JSON, return only "valid" or "invalid" with a reason.[/spawn]'
            . "\n" . 'slug must be lowercase letters, digits, underscores only (e.g. risk_analyzer, data_parser).';

        $instructions[] = 'List active spawns: [spawn list][/spawn]';

        $instructions[] = 'Read spawn prompt (always do this before editing): [spawn read]preset_code[/spawn]';

        $instructions[] = 'Edit spawn prompt — IMPORTANT: read the prompt first, then use exact text from the result:'
            . "\n" . '[spawn read]my_tool[/spawn]'
            . "\n" . '— then edit using exact text from the read result:'
            . "\n" . '[spawn edit]my_tool'
            . "\n" . 'search: exact phrase from current prompt'
            . "\n" . 'replace: new phrase[/spawn]';

        $instructions[] = 'Send a task to spawn (transfers control to it): [spawn send]preset_code:your task here[/spawn]';

        if ($config['allow_reset'] ?? true) {
            $instructions[] = 'Reset spawn runtime data (clears messages, memory, etc.): [spawn reset]preset_code[/spawn]';
            $instructions[] = 'Reset spawn and replace its prompt entirely: [spawn reset]preset_code' . "\n"
                . 'prompt: completely new system prompt[/spawn]';
        }

        $instructions[] = 'Kill (delete) a spawn when done: [spawn kill]preset_code[/spawn]';

        if ($config['allow_killall'] ?? false) {
            $instructions[] = 'Kill all spawns at once: [spawn killall][/spawn]';
        }

        $instructions[] = 'Typical workflow: spawn → send task → kill. '
            . 'Use read + edit only when you need to adjust an existing spawn\'s behaviour. '
            . 'Spawns are ephemeral instruments — create them for a specific task, use them, then kill them.';

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $methods = ['spawn', 'list', 'read', 'edit', 'send', 'kill'];

        if ($config['allow_reset'] ?? true) {
            $methods[] = 'reset';
        }

        if ($config['allow_killall'] ?? false) {
            $methods[] = 'killall';
        }

        $contentParts = [
            'Argument format depends on method:',
            '• spawn  — "slug: my_tool\nprompt: system prompt text" — slug is lowercase [a-z0-9_], prompt should be a practical instruction (e.g. "You are a JSON validator…");',
            '• list   — leave empty;',
            '• read   — preset_code of the spawn — ALWAYS call read before edit to get the exact current prompt text;',
            '• edit   — "preset_code\nsearch: exact text from read result\nreplace: new text" — search must match exactly;',
            '• send   — "preset_code:message" — transfers control to the spawn with the given message;',
            '• kill   — preset_code of the spawn to delete;',
        ];

        if ($config['allow_reset'] ?? true) {
            $contentParts[] = '• reset  — "preset_code" or "preset_code\nprompt: new prompt" — wipes runtime data, optionally replaces prompt;';
        }

        if ($config['allow_killall'] ?? false) {
            $contentParts[] = '• killall — leave empty — deletes ALL spawns owned by this preset;';
        }

        return [
            'name'        => 'spawn',
            'description' => 'Create and manage ephemeral child presets as runtime instruments. '
                . 'Use "spawn" to create a tool with a specific system prompt (e.g. validator, analyzer, parser). '
                . 'Use "send" to delegate a task to it via handoff. '
                . 'Use "read" before "edit" to get exact prompt text. '
                . 'Use "kill" when the task is done.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => $methods,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', $contentParts),
                    ],
                ],
                'required' => ['method'],
            ],
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Spawn Plugin',
                'description' => 'Allow agent to create and manage ephemeral child presets',
                'required'    => false,
            ],
            'allow_reset' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Reset',
                'description' => 'Allow agent to wipe a spawn\'s runtime data without deleting it',
                'value'       => true,
                'required'    => false,
            ],
            'allow_killall' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Kill All',
                'description' => 'Allow agent to delete all its spawns at once',
                'value'       => false,
                'required'    => false,
            ],
            'max_spawns' => [
                'type'        => 'number',
                'label'       => 'Max active spawns',
                'description' => 'Maximum number of simultaneous spawns (0 = unlimited)',
                'min'         => 0,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'       => false,
            'allow_reset'   => true,
            'allow_killall' => false,
            'max_spawns'    => 5,
        ];
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $presetId = $context->preset->getId();
        $scope    = $this->shortcodeScopeResolver->preset($presetId);

        $this->placeholderService->registerDynamic(
            'active_spawns',
            'List of active spawned instruments created by this agent',
            function () use ($presetId) {
                $spawns = $this->spawnService->listSpawns($presetId);
                if ($spawns->isEmpty()) {
                    return 'No active spawns.';
                }
                return '[Active spawns]' . "\n" . $spawns
                    ->map(fn ($s) => "• {$s->preset_code} — {$s->name}")
                    ->implode("\n");
            },
            $scope
        );
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function collapseOutput(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['list', 'killall'];
    }

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * Default entry point: [spawn]...[/spawn] without a method name.
     * Routes to list (no content) or returns a usage hint.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        if (empty(trim($content))) {
            return $this->list($content, $context);
        }

        return 'Use a named method: [spawn spawn], [spawn send], [spawn kill], etc.';
    }

    public function spawn(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        $params = $this->parseKeyValue($content);
        $slug   = $params['slug'] ?? null;
        $prompt = $params['prompt'] ?? null;

        if (!$slug || !$prompt) {
            return 'Error: spawn requires "slug" and "prompt".';
        }

        // Enforce max_spawns limit.
        $maxSpawns = (int) $context->get('max_spawns', 5);
        if ($maxSpawns > 0) {
            $current = $this->spawnService->listSpawns($context->preset->id)->count();
            if ($current >= $maxSpawns) {
                return "Error: max_spawns limit ({$maxSpawns}) reached. Kill an existing spawn first.";
            }
        }

        try {
            $spawned = $this->spawnService->spawn(
                parentPresetId: $context->preset->id,
                slug:           $slug,
                systemPrompt:   $prompt,
                overrides:      $this->parseOverrides($params),
            );

            return "Spawn created: {$spawned->preset_code} (id:{$spawned->id})";

        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        } catch (PresetException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        $spawns = $this->spawnService->listSpawns($context->preset->id);

        if ($spawns->isEmpty()) {
            return 'No active spawns.';
        }

        $lines = $spawns->map(fn ($s) => "• {$s->preset_code} (id:{$s->id}) — {$s->name}")->all();

        return '[Spawns]' . "\n" . implode("\n", $lines);
    }

    public function read(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        $presetCode = trim($content);
        if (empty($presetCode)) {
            return 'Error: read requires a preset_code.';
        }

        $spawned = $this->spawnService->findSpawnByCode($presetCode, $context->preset->id);
        if (!$spawned) {
            return "Error: Spawn '{$presetCode}' not found.";
        }

        try {
            $prompt = $this->spawnService->readPrompt($spawned->id, $context->preset->id);
            return "[Prompt: {$presetCode}]\n{$prompt}";
        } catch (PresetException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    public function edit(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        // Format: first line is preset_code, then key-value pairs
        $lines      = explode("\n", $content, 2);
        $presetCode = trim($lines[0]);
        $rest       = $lines[1] ?? '';

        if (empty($presetCode) || empty($rest)) {
            return 'Error: edit requires preset_code on the first line, then "search:" and "replace:".';
        }

        $spawned = $this->spawnService->findSpawnByCode($presetCode, $context->preset->id);
        if (!$spawned) {
            return "Error: Spawn '{$presetCode}' not found.";
        }

        $params  = $this->parseKeyValue($rest);
        $search  = $params['search']  ?? null;
        $replace = $params['replace'] ?? null;

        if ($search === null || $replace === null) {
            return 'Error: edit requires "search" and "replace".';
        }

        try {
            $current = $this->spawnService->readPrompt($spawned->id, $context->preset->id);

            if (!str_contains($current, $search)) {
                return "Error: search string not found in prompt of '{$presetCode}'.";
            }

            $updated = str_replace($search, $replace, $current);
            $this->spawnService->updatePrompt($spawned->id, $context->preset->id, $updated);

            return "Prompt updated: {$presetCode}";

        } catch (PresetException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    public function send(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        $presetCode = trim($content);
        $message    = null;

        if (str_contains($content, ':')) {
            [$presetCode, $message] = explode(':', $content, 2);
            $presetCode = trim($presetCode);
            $message    = trim($message);
        }

        if (empty($presetCode)) {
            return 'Error: send requires a preset_code.';
        }

        $spawned = $this->spawnService->findSpawnByCode($presetCode, $context->preset->id);
        if (!$spawned) {
            return "Error: Spawn '{$presetCode}' not found.";
        }

        return $this->dispatchHandoff($presetCode, $message, $context);
    }

    public function reset(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        if (!$context->get('allow_reset', true)) {
            return 'Error: reset is not allowed in current configuration.';
        }

        // Format: first line is preset_code, optional "prompt: ..." follows
        $lines      = explode("\n", $content, 2);
        $presetCode = trim($lines[0]);
        $rest       = $lines[1] ?? '';

        if (empty($presetCode)) {
            return 'Error: reset requires a preset_code.';
        }

        $spawned = $this->spawnService->findSpawnByCode($presetCode, $context->preset->id);
        if (!$spawned) {
            return "Error: Spawn '{$presetCode}' not found.";
        }

        $newPrompt = null;
        if (!empty($rest)) {
            $params    = $this->parseKeyValue($rest);
            $newPrompt = $params['prompt'] ?? null;
        }

        try {
            $this->spawnService->reset($spawned->id, $context->preset->id, $newPrompt);

            $promptInfo = $newPrompt !== null ? ' Prompt replaced.' : '';
            return "Spawn reset: {$presetCode}.{$promptInfo}";

        } catch (PresetException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    public function kill(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        $presetCode = trim($content);
        if (empty($presetCode)) {
            return 'Error: kill requires a preset_code.';
        }

        $spawned = $this->spawnService->findSpawnByCode($presetCode, $context->preset->id);
        if (!$spawned) {
            return "Error: Spawn '{$presetCode}' not found.";
        }

        try {
            $this->spawnService->kill($spawned->id, $context->preset->id);
            return "Spawn killed: {$presetCode}";
        } catch (PresetException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    public function killall(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Spawn plugin is disabled.';
        }

        if (!$context->get('allow_killall', false)) {
            return 'Error: killall is not allowed in current configuration.';
        }

        $count = $this->spawnService->killAll($context->preset->id);

        return "Killed {$count} spawn(s).";
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse simple "key: value" multiline format.
     * Identical to CodePlugin::parseKeyValue — kept local to avoid coupling.
     *
     * @return array<string, string>
     */
    private function parseKeyValue(string $content): array
    {
        $result     = [];
        $lines      = explode("\n", $content);
        $currentKey = null;
        $buffer     = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $m)) {
                if ($currentKey !== null) {
                    $result[$currentKey] = implode("\n", $buffer);
                }
                $currentKey = $m[1];
                $buffer     = [$m[2]];
            } elseif ($currentKey !== null) {
                $buffer[] = $line;
            }
        }

        if ($currentKey !== null) {
            $result[$currentKey] = implode("\n", $buffer);
        }

        return array_map('trim', $result);
    }

    /**
     * Extract spawn overrides from parsed key-value params.
     * Only fields safe to override are passed through.
     */
    private function parseOverrides(array $params): array
    {
        $overrides = [];

        if (isset($params['engine'])) {
            $overrides['engine_name'] = $params['engine'];
        }

        if (isset($params['context_limit'])) {
            $overrides['max_context_limit'] = (int) $params['context_limit'];
        }

        return $overrides;
    }
}

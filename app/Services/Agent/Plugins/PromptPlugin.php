<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Prompt\PresetPromptServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\PresetPromptVersion;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Related\Prompt\AnnotationError;
use App\Services\Agent\Plugins\Support\CommandHint;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * PromptPlugin — lets the agent work with its own prompt (its "mode").
 *
 * Two layers of capability, each independently switchable via config so the
 * same plugin serves very different setups:
 *
 *   • Switching   — a preset may hold several prompts; the agent can list them
 *                   and switch the active one. (Ada: OFF — she has one prompt.)
 *   • Self-editing — the agent can edit / rewrite its OWN currently-active
 *                    prompt, with an immutable version history it can inspect
 *                    and revert. This is editing one's own substrate, so it is
 *                    gated carefully: rewrite (full overwrite) is off by default.
 *
 * Design principle: the agent only ever sees ONE prompt — the active one.
 * Multiple prompts are a preset-level infrastructure detail; surfacing them to
 * the model is needless cognitive load. To edit a different prompt, switch to
 * it first (if switching is allowed), then edit. One prompt in focus, always.
 *
 * getInstructions() and getToolSchema() are built from enabledCapabilities():
 * a disabled capability is invisible to the model — not in the instructions,
 * not in the method enum. Less surface, less confusion.
 *
 * Usability notes (the "user" here is an LLM):
 *   - The model normally sees its prompt with placeholders already rendered.
 *     `show` reveals the RAW source that edit/search actually work against, and
 *     a failed `edit` search points the model to `show` — that mismatch is the
 *     single most confusing trap otherwise.
 *   - Confirmations are self-contained (what changed + version), because the
 *     model has no memory between cycles.
 *   - Every error ends with a next step (a verb), not just a diagnosis.
 */
class PromptPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    public function __construct(
        protected PresetPromptServiceInterface $promptService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return 'mode';
    }

    public function getDescription(array $config = []): string
    {
        $caps = $this->enabledCapabilities($config);

        // Description adapts to what's actually on, so the model's mental model
        // of the tool matches its real powers.
        if (in_array('edit', $caps, true) && in_array('switch', $caps, true)) {
            return 'Work with your active prompt: switch between modes and edit your own prompt (with version history).';
        }
        if (in_array('edit', $caps, true)) {
            return 'Edit your own active prompt. Your changes are versioned — you can review history and revert.';
        }
        if (in_array('switch', $caps, true)) {
            return 'Switch thinking mode (active prompt). Change personality, focus, or reasoning style mid-session.';
        }

        return 'Inspect your active prompt.';
    }

    public function getInstructions(array $config = []): array
    {
        $caps  = $this->enabledCapabilities($config);
        $lines = [];

        if (in_array('switch', $caps, true)) {
            $lines[] = 'Switch to a different mode: [mode]code[/mode]';
            $lines[] = 'List available modes: [mode list][/mode]';
            $lines[] = 'Show current mode: [mode current][/mode]';
        }

        if (in_array('edit', $caps, true)) {
            $lines[] = 'View your RAW prompt with line numbers (this is the exact text to edit against): [mode show][/mode]';
            $summaryHint = $this->requiresAnnotation($config)
                ? "\nsummary: what and why (REQUIRED)"
                : "\nsummary: what and why (optional)";
            $lines[] = 'Edit your active prompt (find & replace): '
                . '[mode edit]search: old text' . "\n" . 'replace: new text' . $summaryHint . '[/mode]';
            $lines[] = 'If your search or replace text spans MULTIPLE LINES or contains colons '
                . '(e.g. "memory: mine", "format: ..."), use fenced blocks so the text is taken '
                . 'verbatim: [mode edit]search: <<<' . "\n" . 'line one' . "\n" . 'key: value inside content' . "\n"
                . '>>>' . "\n" . 'replace: <<<' . "\n" . 'new line one' . "\n" . 'new key: value' . "\n" . '>>>[/mode]';
            $lines[] = 'Tip: call [mode show][/mode] first, then copy your search text from there — '
                . 'what you normally see has placeholders already filled in.';
        }

        if (in_array('rewrite', $caps, true)) {
            $summaryHint = $this->requiresAnnotation($config)
                ? "\nsummary: what and why (REQUIRED)"
                : "\nsummary: what and why (optional)";
            $lines[] = 'Rewrite your active prompt completely: '
                . '[mode rewrite]content: <full new prompt text>' . $summaryHint . '[/mode]';
            $lines[] = '⚠️ Rewrite replaces your ENTIRE active prompt. Use edit for small changes.';
        }

        if (in_array('versioning', $caps, true)) {
            $lines[] = 'Review your prompt history: [mode history][/mode]';
            $lines[] = 'See what changed in a version: [mode diff]3[/mode] (version N vs current)';
            $lines[] = 'Revert your prompt to an earlier version: [mode revert]3[/mode]';
        }

        $warning = $this->buildLanguageWarning($config, 'prompt_language', 'prompt edits');
        if ($warning) {
            array_unshift($lines, $warning);
        }

        if (empty($lines)) {
            $lines[] = 'Show current mode: [mode current][/mode]';
        }

        return $lines;
    }

    public function getToolSchema(array $config = []): array
    {
        $caps    = $this->enabledCapabilities($config);
        $methods = ['current']; // always available: knowing your own mode is free

        if (in_array('switch', $caps, true)) {
            $methods = array_merge($methods, ['execute', 'list']);
        }
        if (in_array('edit', $caps, true)) {
            $methods[] = 'show';
            $methods[] = 'edit';
        }
        if (in_array('rewrite', $caps, true)) {
            $methods[] = 'rewrite';
        }
        if (in_array('versioning', $caps, true)) {
            $methods = array_merge($methods, ['history', 'diff', 'revert']);
        }

        $methods = array_values(array_unique($methods));

        $langInstruction = $this->buildLanguageInstruction($config, 'prompt_language');
        $annotation = $this->requiresAnnotation($config)
            ? 'A "summary" field describing the edit is REQUIRED for edit and rewrite. '
            : '';

        return [
            'name'        => 'mode',
            'description' => 'Work with your own active prompt. '
                . 'Current mode is visible via the current_mode placeholder. '
                . 'Edits take effect from the next cycle. '
                . $annotation
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => $methods,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => $this->buildContentDescription($caps, $config),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    /**
     * Build the "content" argument description from enabled capabilities only.
     * Mirrors getToolSchema's method set so the two never drift.
     */
    private function buildContentDescription(array $caps, array $config): string
    {
        $parts = ['Argument depends on method:'];

        $parts[] = '• current: leave empty — shows your active prompt\'s mode.';

        if (in_array('switch', $caps, true)) {
            $parts[] = '• execute: mode code to switch to.';
            $parts[] = '• list: leave empty — lists available modes.';
        }
        if (in_array('edit', $caps, true)) {
            $parts[] = '• show: leave empty — shows your RAW prompt (placeholders NOT expanded) with line numbers. '
                . 'This is the exact source that edit/search works against; what you normally see has placeholders already filled in.';
            $parts[] = '• edit: "search: <old>\nreplace: <new>". '
                . 'Optionally add "\nlimit: 1" to replace only the first match'
                . ($this->requiresAnnotation($config) ? ', and "\nsummary: <why>" (required).' : ', and "\nsummary: <why>".')
                . ' IMPORTANT: if the old/new text spans multiple lines OR contains colon lines '
                . '(like "memory: mine"), wrap each in a fenced block so it is read verbatim: '
                . 'search: <<<\\n...text...\\n>>> and replace: <<<\\n...text...\\n>>>. '
                . 'Only search/replace/content/text/limit/summary are treated as fields; '
                . 'other "word:" lines are part of the value.';
        }
        if (in_array('rewrite', $caps, true)) {
            $parts[] = '• rewrite: "content: <full new prompt text>"'
                . ($this->requiresAnnotation($config) ? ' plus "\nsummary: <why>" (required).' : '.');
        }
        if (in_array('versioning', $caps, true)) {
            $parts[] = '• history: leave empty — lists your prompt versions.';
            $parts[] = '• diff: version number, e.g. "3" — shows that version vs current content.';
            $parts[] = '• revert: version number, e.g. "3" — restores your prompt to that version.';
        }

        return implode(' ', $parts);
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Prompt Plugin',
                'description' => 'Master switch. When off, the agent cannot use the mode command at all.',
                'required'    => false,
            ],
            'allow_switch' => [
                'type'        => 'checkbox',
                'label'       => 'Allow mode switching',
                'description' => 'Let the agent list prompts and switch the active one. '
                    . 'Turn off for single-prompt presets (removes list/switch from the tool).',
                'value'       => true,
                'required'    => false,
            ],
            'allow_edit' => [
                'type'        => 'checkbox',
                'label'       => 'Allow self-editing (find & replace)',
                'description' => 'Let the agent edit its own active prompt with targeted find/replace. '
                    . 'Changes are versioned.',
                'value'       => false,
                'required'    => false,
            ],
            'allow_rewrite' => [
                'type'        => 'checkbox',
                'label'       => 'Allow full rewrite',
                'description' => 'Let the agent overwrite its ENTIRE active prompt at once. '
                    . 'Off by default — a safeguard so a single bad generation cannot wipe the prompt. '
                    . 'Requires self-editing to be meaningful.',
                'value'       => false,
                'required'    => false,
            ],
            'allow_versioning' => [
                'type'        => 'checkbox',
                'label'       => 'Allow version history & revert',
                'description' => 'Let the agent review its prompt history, diff versions, and revert. '
                    . 'History is recorded regardless; this exposes it to the agent.',
                'value'       => false,
                'required'    => false,
            ],
            'require_annotation' => [
                'type'        => 'checkbox',
                'label'       => 'Require edit annotation',
                'description' => 'Force the agent to attach a "summary" explaining every edit/rewrite. '
                    . 'Off by default. Useful when you want deliberate, self-reflective changes.',
                'value'       => false,
                'required'    => false,
            ],
            'log_switches' => [
                'type'        => 'checkbox',
                'label'       => 'Log Mode Switches',
                'description' => 'Write a log entry each time the agent switches mode',
                'value'       => true,
                'required'    => false,
            ],
            'prompt_language' => $this->getLanguageConfigField(
                'Prompt Language',
                'Force the language the agent writes prompt edits in. '
                    . 'Prevents language drift (e.g. editing in one language, searching in another).'
            ),
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['prompt_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['prompt_language'], $valid, true)) {
                $errors['prompt_language'] = 'Invalid language selection.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge([
            'enabled'            => false,
            'allow_switch'       => true,
            'allow_edit'         => false,
            'allow_rewrite'      => false,
            'allow_versioning'   => false,
            'require_annotation' => false,
            'log_switches'       => true,
        ], $this->getDefaultLanguageConfig('prompt_language'));
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    private function enabledCapabilities(array $config): array
    {
        // NOTE: we do NOT gate on $config['enabled'] here. That field is a
        // seed/default ("should this plugin start enabled when a preset is
        // created"), not a runtime switch. Whether the plugin is actually active
        // is decided by the system before a method runs (surfaced as
        // $context->enabled in the execution methods). At schema-build and
        // instruction-build time the plugin is being offered, so we just report
        // which sub-capabilities are on.
        $caps = [];

        if ($config['allow_switch'] ?? true) {
            $caps[] = 'switch';
        }
        if ($config['allow_edit'] ?? false) {
            $caps[] = 'edit';
        }
        if ($config['allow_rewrite'] ?? false) {
            $caps[] = 'rewrite';
        }
        if ($config['allow_versioning'] ?? false) {
            $caps[] = 'versioning';
        }

        return $caps;
    }

    private function requiresAnnotation(array $config): bool
    {
        return (bool) ($config['require_annotation'] ?? false);
    }

    // ── Switching commands ──────────────────────────────────────────────────────

    /**
     * Default execute — switch to the given mode code.
     * [mode]critic[/mode]
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('switch', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Mode switching is not enabled for this preset.';
        }

        $code = trim($content);

        if ($code === '') {
            return 'Error: Mode code is required. Switch with ' . $this->hint($context, 'execute', 'code') . '. Available: '
                . implode(', ', $context->preset->getAvailablePromptCodes());
        }

        $current = $this->currentCode($context);
        if ($current === $code) {
            return "Already in '{$code}' mode.";
        }

        try {
            $prompt = $this->promptService->setActiveByCode($context->preset, $code);

            if ($context->get('log_switches', true)) {
                $this->logger->info('PromptPlugin: mode switched', [
                    'preset_id' => $context->preset->id,
                    'from'      => $current,
                    'to'        => $code,
                ]);
            }

            $desc = $prompt->getDescription() ? " ({$prompt->getDescription()})" : '';
            return "Mode switched to '{$code}'{$desc}. New prompt takes effect from the next cycle.";

        } catch (\RuntimeException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * List all available modes for this preset.
     * [mode list][/mode]
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('switch', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Mode switching is not enabled for this preset.';
        }

        $prompts = $this->promptService->getAll($context->preset);

        if ($prompts->isEmpty()) {
            return 'No modes available for this preset.';
        }

        $currentCode = $this->currentCode($context);
        $lines = ['Available modes:'];

        foreach ($prompts as $prompt) {
            $active = $prompt->getCode() === $currentCode ? ' ← current' : '';
            $desc   = $prompt->getDescription() ? " — {$prompt->getDescription()}" : '';
            $lines[] = "  • {$prompt->getCode()}{$desc}{$active}";
        }

        return implode("\n", $lines);
    }

    /**
     * Show the current mode code.
     * [mode current][/mode]
     */
    public function current(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        $code = $this->currentCode($context);
        $prompt = $this->promptService->findByCode($context->preset, $code);
        $desc = $prompt?->getDescription() ? " — {$prompt->getDescription()}" : '';

        return "Current mode: '{$code}'{$desc}";
    }

    // ── Editing commands ────────────────────────────────────────────────────────

    /**
     * Show the RAW active prompt with line numbers.
     *
     * Crucial for editing: the model normally sees its prompt with placeholders
     * already expanded ([[current_mode]] -> "critic", etc.). But edit/search
     * operates on the RAW source where the placeholder literal still stands.
     * show() reveals that raw source so search strings actually match.
     *
     * [mode show][/mode]
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('edit', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Viewing the raw prompt requires self-editing to be enabled.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: no active prompt.';
        }

        $raw = $active->getContent();

        if ($raw === '') {
            return "Your active prompt ('{$active->getCode()}') is empty.";
        }

        $numbered = $this->withLineNumbers($raw);

        return "Your RAW active prompt ('{$active->getCode()}') — "
            . "placeholders are shown literally; this is the text edit/search works against:\n\n"
            . $numbered;
    }

    /**
     * Targeted find & replace on the ACTIVE prompt.
     * [mode edit]search: old
     * replace: new
     * summary: why[/mode]
     */
    public function edit(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('edit', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Self-editing is not enabled for this preset.';
        }

        $params  = $this->parseKeyValue($content);
        $search  = $params['search'] ?? null;
        $replace = $params['replace'] ?? null;
        $limit   = isset($params['limit']) ? (int) $params['limit'] : null;
        $summary = $this->resolveSummary($params, $context);

        if ($summary instanceof AnnotationError) {
            return $summary->message;
        }

        if ($search === null || $search === '' || $replace === null) {
            return 'Error: edit requires "search" and "replace" fields. '
                . 'Write ' . $this->hint($context, 'edit', "search: <old>\nreplace: <new>") . '. '
                . 'Call ' . $this->hint($context, 'show') . ' first to copy the exact text to search for.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: no active prompt to edit.';
        }

        $currentContent = $active->getContent();

        if (!str_contains($currentContent, $search)) {
            return "Error: that search text wasn't found in your prompt's RAW source. "
                . 'Most likely you\'re quoting the prompt as you see it — with placeholders '
                . 'already filled in (e.g. [[current_mode]] shown as its value). Edits work '
                . 'against the raw source where the placeholder literal still stands. '
                . 'Call ' . $this->hint($context, 'show') . ' to see the exact raw text, then copy your search from there.';
        }

        $count = substr_count($currentContent, $search);
        if ($limit === null && $count > 1) {
            return "Your search text appears {$count} times, so it's ambiguous which one to change. "
                . 'Either add "limit: 1" to change only the first occurrence, or extend your search '
                . 'text with surrounding context so it matches exactly one place.';
        }

        if ($limit === 1) {
            $pos = strpos($currentContent, $search);
            $updated = substr($currentContent, 0, $pos) . $replace . substr($currentContent, $pos + strlen($search));
            $applied = 1;
        } else {
            $updated = str_replace($search, $replace, $currentContent);
            $applied = $count;
        }

        if ($updated === $currentContent) {
            return 'No changes (content identical after replace).';
        }

        // Build a short, self-contained description of the change so the
        // confirmation is meaningful even when re-read in a later cycle
        // (the model does not carry memory between cycles).
        $changeDetail = $this->describeReplacement($search, $replace, $applied);

        return $this->applyContentChange($context, $active->getId(), $updated, $summary, 'edit', $changeDetail);
    }

    /**
     * Full overwrite of the ACTIVE prompt.
     * [mode rewrite]content: <full new text>
     * summary: why[/mode]
     */
    public function rewrite(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('rewrite', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Full rewrite is not enabled for this preset. Use ' . $this->hint($context, 'edit') . ' for targeted changes.';
        }

        $params  = $this->parseKeyValue($content);
        $newText = $params['content'] ?? $params['text'] ?? null;
        $summary = $this->resolveSummary($params, $context);

        if ($summary instanceof AnnotationError) {
            return $summary->message;
        }

        // If the model passed no "content:" key, treat the whole payload as the
        // new text — but only if it doesn't look like key-value noise.
        if ($newText === null) {
            $trimmed = trim($content);
            $looksKeyed = preg_match('/^\s*(summary|content|text)\s*:/mi', $trimmed);
            $newText = $looksKeyed ? null : $trimmed;
        }

        if ($newText === null || trim($newText) === '') {
            return 'Error: rewrite needs the full new prompt text in a "content:" field. '
                . 'Write ' . $this->hint($context, 'rewrite', 'content: <your full new prompt>') . '. '
                . 'For a small change, use ' . $this->hint($context, 'edit') . ' instead — it is safer.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: no active prompt to rewrite.';
        }

        if ($newText === $active->getContent()) {
            return 'No changes (new content identical to current prompt).';
        }

        $oldLen = mb_strlen($active->getContent());
        $newLen = mb_strlen($newText);
        $changeDetail = "replaced the entire prompt ({$oldLen} -> {$newLen} characters)";

        return $this->applyContentChange($context, $active->getId(), $newText, $summary, 'rewrite', $changeDetail);
    }

    // ── Versioning commands ─────────────────────────────────────────────────────

    /**
     * List version history of the ACTIVE prompt.
     * [mode history][/mode]
     */
    public function history(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('versioning', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Version history is not enabled for this preset.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: no active prompt.';
        }

        $versions = $this->promptService->getHistory($context->preset, $active->getId());

        if ($versions->isEmpty()) {
            return 'No version history yet for your current prompt.';
        }

        $currentContent = $active->getContent();
        $lines = ['Prompt history (newest first):'];
        $markedCurrent = false;

        foreach ($versions as $v) {
            // Several versions can hold the same content (a revert re-creates an
            // older one). Only the newest of them IS the current state — marking
            // all of them would make "which one am I on" ambiguous.
            $isCurrent = '';
            if (!$markedCurrent && $v->getContent() === $currentContent) {
                $isCurrent     = ' ← current';
                $markedCurrent = true;
            }
            $when      = $this->relativeTime($v->created_at);
            $who       = $this->actorLabel($v->getEditedBy());
            $sum       = $v->getEditSummary() ? " — {$v->getEditSummary()}" : '';
            $lines[]   = "  v{$v->getVersion()} ({$when}, {$who}){$sum}{$isCurrent}";
        }

        $lines[] = '';
        $lines[] = 'Use ' . $this->hint($context, 'diff', 'N') . ' to see a version\'s changes, or ' . $this->hint($context, 'revert', 'N') . ' to restore one.';

        return implode("\n", $lines);
    }

    /**
     * Diff a version against the current prompt content.
     * [mode diff]3[/mode]
     */
    public function diff(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('versioning', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Version history is not enabled for this preset.';
        }

        $versionNum = $this->extractVersionNumber($content);
        if ($versionNum === null) {
            return 'Error: tell me which version to compare, e.g. ' . $this->hint($context, 'diff', '3') . '. '
                . 'Call ' . $this->hint($context, 'history') . ' to see the version numbers.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: no active prompt.';
        }

        $version = $this->promptService->getVersion($context->preset, $active->getId(), $versionNum);
        if (!$version) {
            return "Error: version {$versionNum} doesn't exist for your prompt. "
                . 'Call ' . $this->hint($context, 'history') . ' to see the available versions.';
        }

        $diff = $this->textDiff($version->getContent(), $active->getContent());
        if ($diff === '') {
            return "Version {$versionNum} is identical to your current prompt.";
        }

        return "Diff — v{$versionNum} vs current:\n{$diff}";
    }

    /**
     * Revert the ACTIVE prompt to an earlier version (appends a new version).
     * [mode revert]3[/mode]
     */
    public function revert(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Prompt plugin is disabled.';
        }

        if (!in_array('versioning', $this->enabledCapabilities($context->config), true)) {
            return 'Error: Version history is not enabled for this preset.';
        }

        $versionNum = $this->extractVersionNumber($content);
        if ($versionNum === null) {
            return 'Error: tell me which version to revert to, e.g. ' . $this->hint($context, 'revert', '3') . '. '
                . 'Call ' . $this->hint($context, 'history') . ' to see the available version numbers.';
        }

        $active = $this->promptService->getActive($context->preset);
        if (!$active) {
            return 'Error: there is no active prompt to revert.';
        }

        try {
            $reverted = $this->promptService->revertToVersion(
                $context->preset,
                $active->getId(),
                $versionNum,
                PresetPromptVersion::BY_AGENT
            );

            $newVersion  = $reverted->latestVersionNumber();
            $versionNote = $newVersion > 0 ? " Recorded as version {$newVersion}." : '';

            return "Reverted your prompt to the content of version {$versionNum}.{$versionNote} "
                . 'It takes effect from the next cycle. '
                . 'Call ' . $this->hint($context, 'show') . ' to confirm the restored text.';

        } catch (\RuntimeException $e) {
            // Service throws when the version is missing or already current.
            return 'Error: ' . $e->getMessage()
                . ' — call ' . $this->hint($context, 'history') . ' to see which versions you can revert to.';
        }
    }

    // ── Shared edit application ─────────────────────────────────────────────────

    /**
     * Push a content change through the service as an AGENT edit, then report.
     * Centralises the "takes effect next cycle" contract and logging.
     *
     * The confirmation is self-contained (what changed + version number) because
     * the model has no memory between cycles — a bare "edited" would be useless
     * when re-read next cycle.
     */
    private function applyContentChange(
        PluginExecutionContext $context,
        int $promptId,
        string $newContent,
        ?string $summary,
        string $op,
        ?string $changeDetail = null
    ): string {
        try {
            $updated = $this->promptService->update(
                $context->preset,
                $promptId,
                [
                    'content'      => $newContent,
                    'edit_summary' => $summary,
                ],
                PresetPromptVersion::BY_AGENT
            );

            $this->logger->info('PromptPlugin: self-edit applied', [
                'preset_id' => $context->preset->id,
                'prompt_id' => $promptId,
                'op'        => $op,
                'annotated' => $summary !== null,
            ]);

            $newVersion  = $updated->latestVersionNumber();
            $label       = $op === 'rewrite' ? 'Rewrote' : 'Edited';
            $detail      = $changeDetail ? " — {$changeDetail}" : '';
            $versionNote = $newVersion > 0 ? " Saved as version {$newVersion}." : ' A new version was saved.';

            return "{$label} your active prompt{$detail}.{$versionNote} "
                . 'It takes effect from the next cycle. '
                . 'If this went wrong, use ' . $this->hint($context, 'show') . ' to check, or ' . $this->hint($context, 'revert', 'N') . ' to undo.';

        } catch (\RuntimeException $e) {
            return 'Error: ' . $e->getMessage()
                . ' — your prompt was not changed. Use ' . $this->hint($context, 'show') . ' to review the current text.';
        }
    }

    /**
     * Resolve the edit summary, enforcing require_annotation.
     * Returns a string (or null when optional & absent), or an AnnotationError
     * sentinel when annotation is required but missing.
     */
    private function resolveSummary(array $params, PluginExecutionContext $context): string|null|AnnotationError
    {
        $summary = isset($params['summary']) ? trim($params['summary']) : '';

        if ($summary === '') {
            if ($this->requiresAnnotation($context->config)) {
                return new AnnotationError(
                    'Error: this preset requires an annotation. '
                    . 'Add a "summary:" line describing what you changed and why, then send the edit again.'
                );
            }
            return null; // optional and absent
        }

        return $summary;
    }

    // ── Placeholder registration ──────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        // [[current_mode]] — code of the currently active prompt
        $this->placeholderService->registerDynamic(
            'current_mode',
            'Current thinking mode (active prompt code)',
            function () use ($context) {
                return $this->currentCode($context);
            },
            $scope,
            false,
            $this->getName()
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A "how to call this" hint in the syntax the model uses now (tags or
     * tool_calls). Every hint inside a result or an error goes through here.
     */
    private function hint(PluginExecutionContext $context, string $method, string $content = ''): string
    {
        return CommandHint::method($context->preset, $this->getName(), $method, $content);
    }

    /**
     * Get the code of the currently active prompt. Falls back to 'default'.
     */
    private function currentCode(PluginExecutionContext $context): string
    {
        $active = $this->promptService->getActive($context->preset);
        return $active?->getCode() ?? 'default';
    }

    /**
     * Keys this plugin recognises in edit/rewrite payloads. ONLY these start a
     * new field; any other "word:" line is treated as part of the current
     * value. This is essential because prompt content is often colon-dense
     * pseudocode (e.g. "memory: mine", "format: ...", "language: ...") — a
     * naive "any word:" parser would shred a multi-line search/replace at every
     * such line. See parseKeyValue().
     */
    private const EDIT_KEYS = ['search', 'replace', 'content', 'text', 'limit', 'summary'];

    /**
     * Parse the edit/rewrite payload into fields.
     *
     * Two supported forms:
     *
     * 1) HEREDOC (most robust for colon-dense content). Use fenced blocks so the
     *    body is taken verbatim, free of any key interpretation:
     *
     *        search: <<<
     *        continuity_model: ...
     *        memory: mine
     *        >>>
     *        replace: <<<
     *        ...new text with any colons...
     *        >>>
     *        limit: 1
     *
     * 2) KEY-VALUE (backward compatible). A line starts a new field ONLY if it
     *    begins with one of EDIT_KEYS followed by a colon. Every other line —
     *    including lines like "memory: mine" or "format: ..." — belongs to the
     *    current field's value. Values span multiple lines until the next
     *    recognised key.
     *
     * @return array<string, string>
     */
    private function parseKeyValue(string $content): array
    {
        // Try heredoc form first if any "<<<" fence is present.
        if (str_contains($content, '<<<')) {
            $viaHeredoc = $this->parseHeredoc($content);
            if ($viaHeredoc !== null) {
                return $viaHeredoc;
            }
            // Malformed fences → fall through to key-value parsing.
        }

        $keyPattern = '/^\s*(' . implode('|', self::EDIT_KEYS) . ')\s*:\s*(.*)$/i';

        $result     = [];
        $lines      = explode("\n", $content);
        $currentKey = null;
        $buffer     = [];

        foreach ($lines as $line) {
            if (preg_match($keyPattern, $line, $m)) {
                if ($currentKey !== null) {
                    $result[$currentKey] = implode("\n", $buffer);
                }
                $currentKey = strtolower($m[1]);
                $buffer     = [$m[2]];
            } elseif ($currentKey !== null) {
                // Any other line (including "word: ..." that isn't an edit key)
                // is part of the current value — NOT a new field.
                $buffer[] = $line;
            }
        }

        if ($currentKey !== null) {
            $result[$currentKey] = implode("\n", $buffer);
        }

        // Trim only the outer whitespace of each field; internal newlines and
        // colons in the value are preserved exactly.
        return array_map('trim', $result);
    }

    /**
     * Parse the heredoc form: "key: <<<" opens a verbatim block that runs until
     * a line containing only ">>>". Keys outside blocks still use EDIT_KEYS.
     *
     * Returns null if fences are unbalanced (caller falls back to key-value).
     *
     * @return array<string, string>|null
     */
    private function parseHeredoc(string $content): ?array
    {
        $result   = [];
        $lines    = explode("\n", $content);
        $i        = 0;
        $n        = count($lines);
        $keyOpen  = '/^\s*(' . implode('|', self::EDIT_KEYS) . ')\s*:\s*<<<\s*$/i';
        $keyInline = '/^\s*(' . implode('|', self::EDIT_KEYS) . ')\s*:\s*(.*)$/i';

        while ($i < $n) {
            $line = $lines[$i];

            // key: <<<  → collect verbatim until a line that is exactly >>>
            if (preg_match($keyOpen, $line, $m)) {
                $key    = strtolower($m[1]);
                $body   = [];
                $i++;
                $closed = false;
                while ($i < $n) {
                    if (preg_match('/^\s*>>>\s*$/', $lines[$i])) {
                        $closed = true;
                        $i++;
                        break;
                    }
                    $body[] = $lines[$i];
                    $i++;
                }
                if (!$closed) {
                    return null; // unbalanced fence
                }
                // Heredoc body is verbatim — do not trim internal content,
                // only strip a single trailing newline artifact if present.
                $result[$key] = implode("\n", $body);
                continue;
            }

            // Plain "key: value" line outside a block.
            if (preg_match($keyInline, $line, $m)) {
                $result[strtolower($m[1])] = trim($m[2]);
                $i++;
                continue;
            }

            // Stray line outside any block — ignore.
            $i++;
        }

        return $result === [] ? null : $result;
    }

    /**
     * Extract a bare version number from content, e.g. "3" or "v3".
     */
    private function extractVersionNumber(string $content): ?int
    {
        $content = trim($content);
        if (preg_match('/^v?(\d+)$/i', $content, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Prefix each line with a right-aligned line number: "  1 | text".
     * Width adapts to the line count so the text column stays aligned.
     */
    private function withLineNumbers(string $text): string
    {
        $lines = explode("\n", $text);
        $width = strlen((string) count($lines));

        $out = [];
        foreach ($lines as $i => $line) {
            $num = str_pad((string) ($i + 1), $width, ' ', STR_PAD_LEFT);
            $out[] = "{$num} | {$line}";
        }

        return implode("\n", $out);
    }

    /**
     * A short, human-readable description of a find/replace, for the
     * confirmation message. Truncates long snippets so the line stays compact.
     */
    private function describeReplacement(string $search, string $replace, int $applied): string
    {
        $s = $this->snippet($search);
        $r = $this->snippet($replace);
        $times = $applied === 1 ? 'one place' : "{$applied} places";

        if ($replace === '') {
            return "removed \"{$s}\" ({$times})";
        }
        return "replaced \"{$s}\" with \"{$r}\" ({$times})";
    }

    /**
     * Clip a string to a short single-line snippet for messages.
     */
    private function snippet(string $text, int $max = 40): string
    {
        $text = str_replace("\n", ' ', trim($text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * A compact, dependency-free line diff for prompt-sized texts.
     *
     * Aligns the two versions by their longest common subsequence of lines, so
     * an inserted or removed line shows as ONE "+"/"-" line instead of shifting
     * everything below it into a wall of false changes (which a position-by-
     * position comparison does). Unchanged lines are shown only as context:
     * up to CONTEXT lines around each change, runs between them collapsed to
     * "… N unchanged lines". Blank-line-only changes are still shown.
     *
     * O(n·m) memory/time — fine for prompts (hundreds of lines). For very
     * large inputs falls back to a plain "-old / +new" listing.
     */
    private function textDiff(string $old, string $new): string
    {
        if ($old === $new) {
            return '';
        }

        $a = explode("\n", $old);
        $b = explode("\n", $new);
        $n = count($a);
        $m = count($b);

        if ($n * $m > 1_000_000) {
            return implode("\n", array_merge(
                array_map(fn ($l) => "- {$l}", $a),
                array_map(fn ($l) => "+ {$l}", $b)
            ));
        }

        // LCS length table, built from the end so the walk below goes forward.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // Walk: ops as [type, line] with type ' ', '-', '+'.
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }

        // Keep context lines near changes; collapse long unchanged runs.
        $context = 2;
        $keep    = [];
        foreach ($ops as $k => [$type]) {
            if ($type !== ' ') {
                for ($c = max(0, $k - $context); $c <= min(count($ops) - 1, $k + $context); $c++) {
                    $keep[$c] = true;
                }
            }
        }

        // A collapsed run of a single line saves nothing — show it instead.
        $total = count($ops);
        for ($k = 0; $k < $total; $k++) {
            if (isset($keep[$k]) || $ops[$k][0] !== ' ') {
                continue;
            }
            $runEnd = $k;
            while ($runEnd + 1 < $total && $ops[$runEnd + 1][0] === ' ' && !isset($keep[$runEnd + 1])) {
                $runEnd++;
            }
            if ($runEnd === $k) {
                $keep[$k] = true;
            }
            $k = $runEnd;
        }

        $out     = [];
        $skipped = 0;
        foreach ($ops as $k => [$type, $line]) {
            if ($type === ' ' && !isset($keep[$k])) {
                $skipped++;
                continue;
            }
            if ($skipped > 0) {
                $out[]   = "  … {$skipped} unchanged line" . ($skipped === 1 ? '' : 's');
                $skipped = 0;
            }
            $out[] = "{$type} {$line}";
        }
        if ($skipped > 0) {
            $out[] = "  … {$skipped} unchanged line" . ($skipped === 1 ? '' : 's');
        }

        return implode("\n", $out);
    }

    /**
     * Human-friendly relative time for the version list the model sees.
     * Uses app timezone (config/app.php TZ), consistent with other agent-facing dates.
     */
    private function relativeTime(?\DateTimeInterface $dt): string
    {
        if ($dt === null) {
            return 'unknown time';
        }

        $now  = new \DateTimeImmutable('now');
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return "{$m}m ago";
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return "{$h}h ago";
        }
        if ($diff < 7 * 86400) {
            $d = (int) floor($diff / 86400);
            return "{$d}d ago";
        }

        return $dt->format('d M Y');
    }

    private function actorLabel(string $editedBy): string
    {
        return match ($editedBy) {
            PresetPromptVersion::BY_AGENT  => 'by you',
            PresetPromptVersion::BY_HUMAN  => 'by human',
            PresetPromptVersion::BY_SYSTEM => 'by system',
            default                        => $editedBy,
        };
    }

    // ── Interface stubs ───────────────────────────────────────────────────────

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['list', 'current', 'history', 'show'];
    }
}

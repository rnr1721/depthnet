<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Formulation\FormulatorInvokerInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Knowledge\KnowledgeDsl;
use App\Services\Agent\Knowledge\KnowledgeRouter;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * KnowledgePlugin — one door to all memory.
 *
 * Replaces the need to enable ontology + person + vectormemory + journal +
 * skill separately and carry five instruction blocks in the prompt. The model
 * expresses an intent; a deterministic router (KnowledgeRouter) sends it to
 * exactly one home on write, and fans out across all homes on read.
 *
 * Works for instrumental agents (dumb, reliable remember/recall) and subjective
 * agents alike — the difference is prompt and RAG config, not this plugin.
 *
 * ── Two input modes (config: input_mode) ─────────────────────────────────────
 *
 *   direct     — the model itself emits the KnowledgeDsl JSON. The tool schema
 *                teaches it the DSL. Cheapest, fully deterministic, no extra
 *                model call. Good default for capable models and instrumental
 *                agents.
 *
 *   formulator — the model emits RAW natural-language intent. A separate
 *                formulator preset translates it into the same DSL, which then
 *                goes through the identical router. The model never learns the
 *                DSL. One extra (cheap) model call per write. Modeled on
 *                InnerVoiceEnricher::callVoice — a synchronous auxiliary-preset
 *                call with applyPreset() restore in finally.
 *
 * Both modes converge on KnowledgeRouter::dispatchWrite — one receiver of DSL,
 * two ways to reach it. The formulator does NOT replace the direct path; it
 * PRECEDES it, turning raw intent into what the direct path already accepts.
 *
 * ── Formulator preset lives on the preset, not in this config ────────────────
 * The formulator preset is chosen the same way as the compressor / cycle-prompt
 * / voice presets: a nullable FK column on ai_presets
 * (knowledge_formulator_preset_id), selected in the preset modal from the
 * available-presets list. It is NOT a field in getConfigFields() — the plugin
 * config form has no access to the preset list, so a preset picker cannot live
 * there. The plugin reads it off $context->preset.
 *
 * ── Read is mode-independent ─────────────────────────────────────────────────
 * recall always fans out directly across the subsystems' search primitives,
 * bypassing RAG (which may be disabled and is passive/pre-assembled anyway).
 *
 * NOTE (formulator prompt): the auxiliary call scaffold below is wired to the
 * real mechanism, but the formulator's SYSTEM PROMPT and its golden set are
 * yours to author against live data — deliberately NOT invented here. When no
 * formulator preset is configured (or it's unusable), the plugin fails safe to
 * direct DSL parsing with a visible note.
 */
class KnowledgePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    public const MODE_DIRECT     = 'direct';
    public const MODE_FORMULATOR = 'formulator';

    /**
     * Constructor deliberately holds NO registries and no formulator-only
     * services. Those live in FormulatorInvoker, which the plugin resolves
     * lazily (see resolveRawDsl) only when formulator mode fires. Keeping them
     * out of here is what breaks the container cycle:
     *   PluginRegistry builds every plugin (incl. this one), so a plugin that
     *   depended on PluginRegistry at construction time would loop.
     * KnowledgeRouter, PresetService and the logger are cycle-free.
     */
    public function __construct(
        protected KnowledgeRouter            $router,
        protected PresetServiceInterface     $presetService,
        protected LoggerInterface            $logger,
    ) {
    }

    public function getName(): string
    {
        return 'knowledge';
    }

    public function getDescription(array $config = []): string
    {
        return 'Unified memory. One place to remember things, recall them, relate them, '
            . 'and forget them — the system decides where each piece of knowledge lives. '
            . 'Use instead of separate memory tools.';
    }

    // ── Instructions — opposite copy per mode ─────────────────────────────────

    public function getInstructions(array $config = []): array
    {
        return $this->isFormulatorMode($config)
            ? $this->rawIntentInstructions($config)
            : $this->dslInstructions($config);
    }

    /**
     * direct mode: teach the DSL. The model produces the JSON itself.
     */
    private function dslInstructions(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'knowledge_language');

        return [
            'Unified memory. Emit a single JSON object. "nature" (the KIND of',
            'knowledge) always sits at the top level, beside "op" — never inside args. ',
            'Put the main text in "content" everywhere; other fields are optional refinements.',
            $langInstruction,
            '',
            'REMEMBER — {"op":"remember","nature":<kind>,"args":{"content":"...",...}}',
            '  episode  — something that happened. content is the summary.',
            '     {"op":"remember","nature":"episode","args":{"content":"refactored the memory router; tests passed","type":"action","outcome":"success"}}',
            '     (type: action|observation|decision|error; outcome: success|failure — both optional)',
            '  person   — a fact about someone. name the person.',
            '     {"op":"remember","nature":"person","args":{"person":"Женя","content":"loves punk aesthetic"}}',
            '  relation — a durable entity or a fact ABOUT one (a property).',
            '     {"op":"remember","nature":"relation","args":{"content":"eugeny","class":"Person","key":"current_city","value":"kharkiv"}}',
            '  skill    — reusable know-how you will apply again.',
            '     {"op":"remember","nature":"skill","args":{"content":"Use EXPLAIN ANALYZE to inspect query plans","title":"PostgreSQL"}}',
            '  note     — an insight, recalled later by meaning.',
            '     {"op":"remember","nature":"note","args":{"content":"Eugeny prefers concise responses"}}',
            '',
            'RELATE — a link BETWEEN two named things (a graph edge). Use this when',
            'you are connecting two entities; use remember/relation when recording a',
            'single entity or a property of one.',
            '  {"op":"relate","args":{"source":"eugeny","relation":"lives_in","target":"kharkiv"}}',
            '',
            'RECALL — searches everything by meaning; call several times if useful.',
            '  {"op":"recall","args":{"query":"database optimization"}}',
            '  query may carry filters: "time:yesterday | domain:work | optimization"',
            '  narrow to specific kinds when you know where to look (nature at top level):',
            '  {"op":"recall","nature":"episode|note","args":{"query":"the argument we had"}}',
            '  omit nature to search all sources. Each result is tagged with its source.',
            '  Note: episode results lead with the best semantic matches, but a few',
            '  recent entries may be blended into the tail regardless of the query —',
            '  the ★-marked first hit is the strongest match; the top hits are the relevant ones.',
            '',
            'FORGET — supported for notes only right now; other kinds are not yet removable here.',
            '  {"op":"forget","nature":"note","args":{"content":"the outdated fact to drop"}}',
            '',
            'The kinds: episode (what happened), person (about someone), relation (an',
            'entity or its property), skill (reusable know-how), note (an insight by',
            'meaning). You name the kind; the system decides where it lives.',
            'One quirk: recalling a relation matches by the ENTITY NAME appearing in',
            'your query, not by meaning — search for the thing\'s name, not a description.',
        ];
    }

    /**
     * formulator mode: forbid structure. The model just talks.
     */
    private function rawIntentInstructions(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'knowledge_language');
        return [
            'Unified memory tool. Say plainly, in your own words, what you want done with memory. ',
            'Do NOT format anything. No JSON, no fields, no pipes. Just natural language intent. ',
            $langInstruction .
            '',
            'Examples:',
            '  "remember that Женя loves punk aesthetic and travel"',
            '  "note that Eugeny prefers concise responses"',
            '  "recall what I know about database optimization from last week"',
            '  "record that I refactored the memory plugin today and it worked"',
            '  "eugeny lives in kharkiv"',
            '',
            'The system understands the intent and stores or retrieves accordingly. '
            . 'You do not need to know how memory is organized.',
        ];
    }

    // ── Tool schema — opposite shape per mode ─────────────────────────────────

    public function getToolSchema(array $config = []): array
    {
        return $this->isFormulatorMode($config)
            ? $this->formulatorSchema($config)
            : $this->dslSchema($config);
    }

    private function dslSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'knowledge_language');
        return [
            'name'        => 'knowledge',
            'description' => 'Unified memory. Emit ONE JSON object with "op" and (for remember/forget) '
                . '"nature", plus "args". op ∈ {remember, recall, relate, forget}. '
                . 'nature ∈ {episode, person, relation, skill, note} — the KIND of knowledge, '
                . 'never a storage name. The system routes it. recall searches everything; '
                . 'call it multiple times per turn if useful.'
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'op' => [
                        'type'        => 'string',
                        'enum'        => ['remember', 'recall', 'relate', 'forget'],
                        'description' => 'What to do with memory.',
                    ],
                    'nature' => [
                        'type'        => 'string',
                        'enum'        => KnowledgeDsl::NATURES,
                        'description' => 'Kind of knowledge (required for remember; forget uses "note"). '
                            . 'episode=what happened, person=about someone, relation=entity/edge, '
                            . 'skill=reusable know-how, note=insight recalled by meaning.',
                    ],
                    'args' => [
                        'type'        => 'object',
                        'description' => 'Payload — put the main text in "content" everywhere. '
                            . 'remember/episode: {content, type?, details?, outcome?} '
                            . '(type: action|observation|decision|error; outcome: success|failure). '
                            . 'remember/person: {person, content}. '
                            . 'remember/relation: {content (the entity name), class?, aliases?, key?, value?}. '
                            . 'remember/skill: {content, title?}. '
                            . 'remember/note: {content, domain?}. '
                            . 'relate: {source, relation, target, valid_from?}. '
                            . 'recall: {query} (may carry "time:/domain:/pulse:" filters); put optional '
                            . 'nature at the TOP level (sibling of op) to narrow sources. '
                            . 'forget: {content} or {id}.',
                    ],
                ],
                'required'   => ['op'],
            ],
        ];
    }

    private function formulatorSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'knowledge_language');
        return [
            'name'        => 'knowledge',
            'description' => 'Unified memory. Provide your intent in plain natural language — '
                . 'what you want to remember, recall, relate, or forget. No structure required; '
                . 'the system interprets and routes it.'
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'intent' => [
                        'type'        => 'string',
                        'description' => 'Plain-language memory intent, e.g. '
                            . '"remember that Женя loves travel" or "recall what I know about X".',
                    ],
                ],
                'required'   => ['intent'],
            ],
        ];
    }

    // ── Config ────────────────────────────────────────────────────────────────
    //
    // NOTE: the formulator PRESET is NOT selected here — it's a column on
    // ai_presets (knowledge_formulator_preset_id), picked in the preset modal
    // like the compressor/cycle/voice presets. This config only carries the
    // plugin's own knobs.

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Knowledge (unified memory)',
                'description' => 'Single interface over journal, vector memory, ontology, person and skill. '
                    . 'When on, you can disable those tools individually and let the model drive memory from one place.',
                'required'    => false,
            ],
            'knowledge_language' => $this->getLanguageConfigField(
                'Knowledge Language',
                'Force language for knowledge entries. Model will be instructed accordingly.'
            ),
            'input_mode' => [
                'type'        => 'select',
                'label'       => 'Input mode',
                'description' => 'direct: the model writes the memory DSL itself (cheapest, deterministic). '
                    . 'formulator: the model speaks plainly and a formulator preset translates intent to DSL '
                    . '(one extra cheap model call; lowest cognitive load on the main model). '
                    . 'Formulator mode also needs a formulator preset selected on the preset itself.',
                'options'     => [
                    self::MODE_DIRECT     => 'Direct — model emits the DSL',
                    self::MODE_FORMULATOR => 'Formulator — a preset translates plain intent',
                ],
                'value'       => self::MODE_DIRECT,
                'required'    => false,
            ],
            'memory_mode' => [
                'type'        => 'select',
                'label'       => 'Note search mode',
                'description' => 'How semantic-note recall behaves (flat top-K or associative chain).',
                'options'     => [
                    'flat'        => 'Flat — top-K',
                    'associative' => 'Associative — chain traversal',
                ],
                'value'       => 'flat',
                'required'    => false,
            ],
            'memory_engine' => [
                'type'        => 'select',
                'label'       => 'Note similarity engine',
                'description' => 'TF-IDF (no API) or embedding (semantic; needs an embedding capability). '
                    . 'Embeddings work transparently through the same interface.',
                'options'     => [
                    'tfidf'     => 'TF-IDF',
                    'embedding' => 'Embedding',
                ],
                'value'       => 'tfidf',
                'required'    => false,
            ],
            'recall_per_source' => [
                'type'        => 'number',
                'label'       => 'Recall results per source',
                'description' => 'How many items each subsystem contributes to a recall. Keep small — recall '
                    . 'may be called several times per cycle and should stay lean.',
                'min'         => 1,
                'max'         => 10,
                'value'       => 3,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['knowledge_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['knowledge_language'], $valid, true)) {
                $errors['knowledge_language'] = 'Invalid language selection.';
            }
        }

        if (isset($config['input_mode'])
            && !in_array($config['input_mode'], [self::MODE_DIRECT, self::MODE_FORMULATOR], true)) {
            $errors['input_mode'] = 'Input mode must be direct or formulator.';
        }

        // NOTE: "formulator mode requires a formulator preset" is a CROSS-FIELD
        // rule between this config and the preset column — it can't be enforced
        // here (no preset access). It's enforced softly at runtime (fail-safe to
        // direct) and can be enforced hard in PresetService::validatePresetData.

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'           => false,
            'input_mode'        => self::MODE_DIRECT,
            'memory_mode'       => 'flat',
            'memory_engine'     => 'tfidf',
            'recall_per_source' => 3,
            'knowledge_language' => 'en',
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Single entry point.
     *
     * Order matters (fixed): recall is detected and handled BEFORE any DSL
     * validation, because KnowledgeDsl::validate() intentionally rejects
     * op:recall (recall has no routed "home"). If we validated first, a recall
     * would be bounced as "unknown op" before we ever reached the fan-out read.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Knowledge plugin is disabled.';
        }

        $content = trim($content);
        if ($content === '') {
            return 'Error: empty knowledge request.';
        }

        // 1) Resolve to a raw DSL string — direct (model gave JSON) or via the
        //    formulator preset (raw intent → DSL). modeNote carries any fallback
        //    explanation to surface to the model.
        [$rawDsl, $modeNote] = $this->resolveRawDsl($content, $context);

        if ($rawDsl === null) {
            return 'Knowledge error: could not obtain a usable request.' . $modeNote;
        }

        // 2) FAST recall detection BEFORE full validation. A cheap decode: if the
        //    payload's op is recall, run the fan-out read and return — never let
        //    KnowledgeDsl::validate() reject it as an unknown op.
        $peek = json_decode($this->stripFences($rawDsl), true);
        if (is_array($peek) && ($peek['op'] ?? null) === 'recall') {
            $query = (string) ($peek['args']['query'] ?? '');
            if (trim($query) === '') {
                return 'Knowledge error: recall needs a "query".' . $modeNote;
            }
            // Optional nature filter. The schema declares `nature` at the top
            // level (sibling of op/args), same as writes — so read it there
            // first. Fall back to args.nature in case the model nests it.
            $rawNature = $peek['nature'] ?? ($peek['args']['nature'] ?? null);
            $natures = $this->parseRecallNatures($rawNature);
            return $this->router->recall(
                $context->preset,
                $query,
                $natures,
                $context->config
            ) . $modeNote;
        }

        // 3) Everything else: validate as a write op, then route deterministically.
        $dsl = KnowledgeDsl::validate(is_array($peek) ? $peek : []);
        if (!($dsl['ok'] ?? false)) {
            return 'Knowledge error: ' . ($dsl['error'] ?? 'invalid request.') . $modeNote;
        }

        $result = $this->router->dispatchWrite($context->preset, $dsl, $context->config);
        return ($result['message'] ?? '') . $modeNote;
    }

    /**
     * Obtain the raw DSL string for this request.
     *
     * direct mode: the content already IS the DSL JSON.
     * formulator mode: extract raw intent, run the formulator preset, take its
     *                  JSON output. Falls back to treating content as direct DSL
     *                  if no usable formulator preset is configured.
     *
     * @return array{0: ?string, 1: string}  [rawDslJson|null, modeNote]
     */
    private function resolveRawDsl(string $content, PluginExecutionContext $context): array
    {
        if (!$this->isFormulatorMode($context->config)) {
            return [$content, ''];
        }

        $intent = $this->extractIntent($content);
        if ($intent === '') {
            return [null, ''];
        }

        $formulator = $this->resolveFormulatorPreset($context->preset);
        if ($formulator === null) {
            // Fail safe: treat the content as direct DSL, tell the model why.
            return [
                $content,
                "\n(note: formulator mode is on but no usable formulator preset is set on this "
                . "preset — parsed the input as direct DSL instead)",
            ];
        }

        // Resolve the invoker LAZILY — only now, when formulator mode actually
        // fires. This is the one point of container resolution, and it's what
        // keeps the registries out of this plugin's construction graph (cycle
        // break). Everywhere else the plugin is plain constructor DI.
        $invoker = app(FormulatorInvokerInterface::class);
        $rawDsl  = $invoker->translate($formulator, $context->preset, $intent);

        if ($rawDsl === null) {
            return [null, "\n(note: the formulator preset did not return a usable translation)"];
        }

        return [$rawDsl, ''];
    }

    /**
     * Resolve the formulator preset from the ai_presets column
     * knowledge_formulator_preset_id. Returns null when unset or unusable, so
     * the caller can fail safe to direct DSL. Usability check mirrors the
     * voice/rag pattern: found + active.
     */
    private function resolveFormulatorPreset(AiPreset $preset): ?AiPreset
    {
        $id = $preset->getKnowledgeFormulatorPresetId();
        if (empty($id)) {
            return null;
        }

        $formulator = $this->presetService->findById((int) $id);
        return ($formulator && $formulator->isActive()) ? $formulator : null;
    }

    private function extractIntent(string $content): string
    {
        // Formulator schema wraps intent as {"intent":"..."} in tool_calls mode;
        // in tag mode the whole content IS the intent.
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded) && isset($decoded['intent']) && is_string($decoded['intent'])) {
            return trim($decoded['intent']);
        }
        return trim($content);
    }

    private function isFormulatorMode(array $config): bool
    {
        return ($config['input_mode'] ?? self::MODE_DIRECT) === self::MODE_FORMULATOR;
    }

    /**
     * Normalize the optional recall nature filter into a clean string[] of valid
     * natures. Accepts either an array (["episode","note"]) or a pipe string
     * ("episode|note"). Unknown natures are dropped; empty → wide recall.
     *
     * @param  mixed $raw
     * @return string[]
     */
    private function parseRecallNatures($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $list = is_array($raw)
            ? $raw
            : explode('|', (string) $raw);

        $clean = [];
        foreach ($list as $n) {
            $n = trim((string) $n);
            if ($n !== '' && in_array($n, KnowledgeDsl::NATURES, true)) {
                $clean[] = $n;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Strip ```json fences a formulator might wrap around its output, so the
     * recall peek and validation see clean JSON. (KnowledgeDsl::fromJson does
     * the same internally; we replicate the minimal bit here for the peek.)
     */
    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
        return trim((string) $raw);
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

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
        return [];
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Knowledge doesn't inject into context automatically — recall is pull, not push.
    }

    /**
     * Cross-preset execution: essential. Memory-optimizer / continuous-existence
     * patterns run knowledge in another preset's memory space, exactly like the
     * five underlying memory plugins do.
     */
    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }
}

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\ToolCallParserInterface;
use App\Services\Agent\Plugins\DTO\ParsedCommand;
use Psr\Log\LoggerInterface;

/**
 * Parses native tool_calls from an AI model response into ParsedCommand objects.
 *
 * This class is the tool_calls-mode alternative to CommandParserSmart.
 * Both produce identical ParsedCommand[] output, so CommandExecutor and all
 * downstream pipeline stages are completely unaware of which parser was used.
 *
 * The parser handles three provider response formats:
 *
 * 1. Anthropic — content block array with type=tool_use:
 *    [{"type":"tool_use","id":"toolu_abc","name":"memory","input":{"method":"show"}}]
 *
 * 2. OpenAI / DeepSeek / compatible — tool_calls wrapper:
 *    {"tool_calls":[{"id":"call_abc","type":"function","function":{"name":"memory","arguments":"{}"}}]}
 *    or a bare array:
 *    [{"id":"call_abc","type":"function","function":{"name":"memory","arguments":"{}"}}]
 *
 * 3. Single call — flat object without array wrapper:
 *    {"name":"memory","id":"call_abc","input":{"method":"show"}}
 *
 * Mixed output handling:
 *   Some models (e.g. DeepSeek) prepend reasoning text or state markers before
 *   the JSON block, producing output like:
 *     "@T(now:2026-04-14) HEART(...)\n{"tool_calls":[...]}"
 *   The parser handles this by scanning for balanced JSON structures anywhere
 *   in the output, not relying on the entire string being valid JSON.
 *
 * Mapping convention (tool input → ParsedCommand fields):
 *   tool.id          → toolCallId  (provider-issued, preserved for tool_result turn)
 *   tool.name        → plugin
 *   input.method     → method      (default: 'execute')
 *   input.content    → content     (default: '')
 *   If input has no 'content' key, remaining fields (excluding 'method') are
 *   JSON-encoded into content so structured plugins (e.g. McpPlugin) can parse them.
 *
 * @implements ToolCallParserInterface
 */
class ToolCallParser implements ToolCallParserInterface
{
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Parse tool_calls from a model response string into ParsedCommand objects.
     *
     * @param  string          $output Raw model response
     * @return ParsedCommand[]
     */
    public function parse(string $output): array
    {
        $toolCalls = $this->extractToolCalls($output);

        if (empty($toolCalls)) {
            return [];
        }

        $commands = [];

        foreach ($toolCalls as $index => $toolCall) {
            $command = $this->toolCallToCommand($toolCall, $index);
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * Extract and normalize tool_calls from the raw response string.
     *
     * Extraction strategy (in order):
     *   1. Entire output is valid JSON
     *   2. JSON block inside markdown code fences (```json ... ```)
     *   3. Balanced JSON structures found anywhere in the string — handles
     *      mixed output where models prepend reasoning text before the JSON.
     *      Uses bracket-balanced extraction instead of regex to correctly
     *      handle deeply nested structures with escaped quotes.
     *
     * @param  string $output
     * @return array  Normalized tool_calls: [['id' => ?string, 'name' => string, 'input' => array], ...]
     */
    protected function extractToolCalls(string $output): array
    {
        $output = trim($output);

        // Pass 1: entire output is JSON
        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $result = $this->normalizeToolCalls($decoded);
            if (!empty($result)) {
                return $result;
            }
        }

        // Pass 2: JSON block inside markdown code fences
        if (preg_match('/```(?:json)?\s*([\[\{].*?[\]\}])\s*```/s', $output, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $result = $this->normalizeToolCalls($decoded);
                if (!empty($result)) {
                    return $result;
                }
            }
        }

        // Pass 3: scan for balanced JSON structures anywhere in the string.
        // This handles models that prepend state markers or reasoning text
        // before the actual tool_calls JSON, e.g.:
        //   "@T(now:...) HEART(...)\n{"tool_calls":[...]}"
        // We try all top-level { and [ positions, largest structures first,
        // to find the one that contains actual tool_calls.
        $candidates = $this->extractBalancedJsonCandidates($output);

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }
            $result = $this->normalizeToolCalls($decoded);
            if (!empty($result)) {
                return $result;
            }
        }

        $this->logger->debug('ToolCallParser: no tool_calls found in output', [
            'output_preview' => substr($output, 0, 300),
        ]);

        return [];
    }

    /**
     * Extract all balanced JSON structures (objects and arrays) from a string.
     *
     * Scans the string for { and [ characters, then walks forward counting
     * open/close brackets to find the matching end — correctly handling
     * nested structures and escaped quotes inside string values.
     *
     * Returns candidates sorted by length descending so we try the largest
     * (most likely to be the full tool_calls payload) first.
     *
     * @param  string   $input
     * @return string[] Balanced JSON candidate strings, longest first
     */
    protected function extractBalancedJsonCandidates(string $input): array
    {
        $candidates = [];
        $length     = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($char !== '{' && $char !== '[') {
                continue;
            }

            $open   = $char === '{' ? '{' : '[';
            $close  = $char === '{' ? '}' : ']';
            $depth  = 0;
            $inStr  = false;
            $escape = false;

            for ($j = $i; $j < $length; $j++) {
                $c = $input[$j];

                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($c === '\\' && $inStr) {
                    $escape = true;
                    continue;
                }

                if ($c === '"') {
                    $inStr = !$inStr;
                    continue;
                }

                if ($inStr) {
                    continue;
                }

                if ($c === $open) {
                    $depth++;
                } elseif ($c === $close) {
                    $depth--;
                    if ($depth === 0) {
                        $candidates[] = substr($input, $i, $j - $i + 1);
                        break;
                    }
                }
            }
        }

        // Longest candidates first — more likely to be the full tool_calls payload
        usort($candidates, fn ($a, $b) => strlen($b) - strlen($a));

        return $candidates;
    }

    /**
     * Normalize different provider formats into a unified internal structure.
     *
     * All formats are reduced to:
     *   [['id' => 'call_abc', 'name' => 'plugin_name', 'input' => [...]], ...]
     *
     * Recognized structures:
     *   - Anthropic:      array of blocks with type=tool_use
     *   - OpenAI wrapped: {"tool_calls": [...]}
     *   - OpenAI bare:    array of {"type":"function","function":{...}}
     *   - Single call:    {"name":"..","input":{..}}
     *
     * @param  array $data Decoded JSON data from the model response
     * @return array       Normalized tool_calls array
     */
    protected function normalizeToolCalls(array $data): array
    {
        $result = [];

        // Anthropic format: array of content blocks with type=tool_use
        if (isset($data[0]['type'])) {
            foreach ($data as $block) {
                if (($block['type'] ?? '') === 'tool_use' && isset($block['name'])) {
                    $result[] = [
                        'id'    => $block['id'] ?? null,
                        'name'  => $block['name'],
                        'input' => $block['input'] ?? [],
                    ];
                }
            }
            return $result;
        }

        // OpenAI wrapped format: {"tool_calls": [...]}
        if (isset($data['tool_calls']) && is_array($data['tool_calls'])) {
            foreach ($data['tool_calls'] as $tc) {
                $normalized = $this->normalizeOpenAiToolCall($tc);
                if ($normalized !== null) {
                    $result[] = $normalized;
                }
            }
            return $result;
        }

        // OpenAI bare array: [{"id":"call_abc","type":"function","function":{...}}, ...]
        if (isset($data[0]['function'])) {
            foreach ($data as $tc) {
                $normalized = $this->normalizeOpenAiToolCall($tc);
                if ($normalized !== null) {
                    $result[] = $normalized;
                }
            }
            return $result;
        }

        // Single call without array wrapper: {"name":"..","id":"..","input":{...}}
        if (isset($data['name'])) {
            $result[] = [
                'id'    => $data['id'] ?? null,
                'name'  => $data['name'],
                'input' => $data['input'] ?? $data['arguments'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Normalize a single OpenAI-style tool_call entry.
     *
     * @param  array      $tc
     * @return array|null
     */
    protected function normalizeOpenAiToolCall(array $tc): ?array
    {
        if (!isset($tc['function']['name'])) {
            return null;
        }

        $arguments = $tc['function']['arguments'] ?? '{}';
        if (is_string($arguments)) {
            $arguments = $this->normalizeDsml($arguments);
            $arguments = json_decode($arguments, true) ?? [];
        }

        // DeepSeek V4 variant: {"arguments": {...}} wrapper
        if (is_array($arguments) && count($arguments) === 1 && isset($arguments['arguments'])) {
            $arguments = is_array($arguments['arguments'])
                ? $arguments['arguments']
                : (json_decode($arguments['arguments'], true) ?? []);
        }

        return [
            'id'    => $tc['id'] ?? null,
            'name'  => $tc['function']['name'],
            'input' => is_array($arguments) ? $arguments : [],
        ];
    }

    /**
     * Convert a normalized tool_call into a ParsedCommand.
     *
     * Input normalization:
     *   Some providers may deliver 'input' as a JSON string rather than a
     *   decoded array (e.g. when double-encoding occurs in the response).
     *   We decode it here so the rest of the method always works with an array.
     *
     * Content resolution:
     *   1. input['content'] exists → use as string
     *   2. Otherwise → JSON-encode remaining input (excluding 'method')
     *
     * @param  array            $toolCall
     * @param  int              $index
     * @return ParsedCommand|null
     */
    protected function toolCallToCommand(array $toolCall, int $index): ?ParsedCommand
    {
        $plugin = trim($toolCall['name'] ?? '');

        if (empty($plugin)) {
            $this->logger->warning('ToolCallParser: tool_call without name, skipping', $toolCall);
            return null;
        }

        $input = $toolCall['input'] ?? [];

        // Guard: some providers deliver 'input' as a JSON string instead of
        // a decoded array. Decode it so array_filter below never receives a string.
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input   = is_array($decoded) ? $decoded : ['content' => $input];
        }

        if (!is_array($input)) {
            $input = [];
        }

        $method     = $input['method']   ?? 'execute';
        $toolCallId = $toolCall['id']    ?? null;

        if (isset($input['content'])) {
            $content = (string) $input['content'];
        } else {
            $inputWithoutMethod = array_filter(
                $input,
                fn ($key) => $key !== 'method',
                ARRAY_FILTER_USE_KEY
            );
            $content = empty($inputWithoutMethod)
                ? ''
                : json_encode($inputWithoutMethod, JSON_UNESCAPED_UNICODE);
        }

        return new ParsedCommand(
            plugin:     strtolower($plugin),
            method:     strtolower($method),
            content:    $content,
            position:   $index * 1000,
            toolCallId: $toolCallId,
        );
    }

    /**
     * Normalize DeepSeek DSML-wrapped parameters.
     *
     * DeepSeek sometimes emits tool call arguments wrapped in DSML tags:
     *   <|DSML|parameter name="content" string="true">value</|DSML|parameter>
     *   <|DSML|parameter name="arguments" string="false">{"key":"val"}</|DSML|parameter>
     *
     * This method extracts all named parameters and rebuilds a clean JSON object.
     * If no DSML tags are found, the original string is returned unchanged.
     */
    private function normalizeDsml(string $raw): string
    {
        if (!str_contains($raw, 'DSML')) {
            return $raw;
        }

        $params = [];
        $pattern = '/<\|DSML\|parameter\s+name="([^"]+)"\s+string="([^"]+)">(.*?)<\/\|DSML\|parameter>/si';

        if (!preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER)) {
            return $raw;
        }

        foreach ($matches as $match) {
            $name     = $match[1];
            $isString = strtolower($match[2]) === 'true';
            $value    = trim($match[3]);

            if (!$isString) {
                $decoded = json_decode($value, true);
                $params[$name] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
            } else {
                $params[$name] = $value;
            }
        }

        if (empty($params)) {
            return $raw;
        }

        $encoded = json_encode($params, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : $raw;
    }

}

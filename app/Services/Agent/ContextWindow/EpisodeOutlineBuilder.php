<?php

namespace App\Services\Agent\ContextWindow;

use App\Models\Message;
use Carbon\CarbonInterface;

/**
 * Builds the outline row: one user-role message listing older episodes the
 * window does not show in full — when, what came in, what I answered.
 *
 *   [Before what follows — earlier exchanges in outline, oldest first. …]
 *   • 21:49 — User says: Eugeny Gazzaev: Привет
 *     → me: Привет, Женя. Я здесь…
 *   • … 2 more exchange(s) omitted …
 *   • 21:51 — User says: Eugeny Gazzaev: Сделай для меня по шагам… (6 cycles: goal, knowledge)
 *     → me: Шаг 1 — что я о тебе знаю…
 *
 * Why one user-role row and not per-episode pairs in the agent's voice:
 *   - nothing in it is written "as me", so there is no service-format turn of
 *     mine for the model to imitate;
 *   - role alternation is untouched;
 *   - it is one row of budget, whatever the number of items.
 *
 * No "conversation" or "session" wording: the agent's stream has no sessions.
 * Times anchor the items instead (date added when not today).
 *
 * The reply of an episode comes from the KIND_SPEECH row linked to its last
 * speaking agent row; for older history, from the agent row itself (plain
 * text, or args of a speak-like tool call). Never saved.
 */
class EpisodeOutlineBuilder
{
    private const HEADER = '[Before what follows — earlier exchanges in outline, oldest first. '
        . 'Only the gist is shown; details can be recalled from memory.]';

    /**
     * @param Episode[]          $episodes        oldest first, all with a known head
     * @param int                $omitted         episodes left out between the first item and the rest
     * @param array<int, string> $speechByAgentId agent row id → spoken text
     */
    public function build(array $episodes, int $omitted, array $speechByAgentId, int $chars): ?Message
    {
        $episodes = array_values(array_filter($episodes, fn (Episode $e) => $e->head !== null && $e->cycles !== []));
        if ($episodes === []) {
            return null;
        }

        $lines = [self::HEADER];

        foreach ($episodes as $i => $ep) {
            $lines[] = $this->item($ep, $speechByAgentId, $chars);

            if ($i === 0 && $omitted > 0) {
                $lines[] = "• … {$omitted} more exchange(s) omitted …";
            }
        }

        $newest = $episodes[array_key_last($episodes)]->rows();
        $first  = $episodes[0]->rows();

        $row = new Message();
        $row->forceFill([
            'id'           => $newest[array_key_last($newest)]->id, // sorts before anything shown in full
            'role'         => 'user',
            'content'      => implode("\n", $lines),
            'from_user_id' => null,
            'metadata'     => [
                'kind'       => Message::KIND_OUTLINE,
                'outline_of' => ['from_id' => $first[0]->id, 'to_id' => $newest[array_key_last($newest)]->id],
                'items'      => count($episodes),
                'omitted'    => $omitted,
            ],
        ]);

        return $row;
    }

    private function item(Episode $ep, array $speechByAgentId, int $chars): string
    {
        $rows   = $ep->rows();
        $cycles = count($ep->cycles);
        $tools  = $this->toolNames($rows);
        $when   = $this->when($ep->head);

        $input = $ep->hardHead
            ? $this->clip($this->inputText($ep->head), $chars)
            : '(on my own)';

        $work = '';
        if ($cycles > 1 || $tools !== []) {
            $work = " ({$cycles} cycle(s)" . ($tools !== [] ? ': ' . implode(', ', $tools) : '') . ')';
        }

        $reply = $this->finalReply($rows, $speechByAgentId);
        $reply = $reply === null ? '(no reply)' : $this->clip($reply, $chars);

        return "• {$when} — {$input}{$work}\n  → me: {$reply}";
    }

    private function when(object $head): string
    {
        $at = $head->created_at ?? null;
        if (!$at instanceof CarbonInterface) {
            return '?';
        }

        return $at->isToday() ? $at->format('H:i') : $at->format('d.m H:i');
    }

    /**
     * Readable input: pool payloads are flattened to "source content" pairs.
     */
    private function inputText(object $head): string
    {
        $content = (string) ($head->content ?? '');
        $pool    = json_decode($content, true);

        if (is_array($pool) && isset($pool['sources']) && is_array($pool['sources'])) {
            $parts = [];
            foreach ($pool['sources'] as $s) {
                $parts[] = trim(($s['source'] ?? '') . ' ' . ($s['content'] ?? ''));
            }
            $content = implode(' | ', array_filter($parts));
        }

        return $content;
    }

    /**
     * The last thing the agent said in the episode.
     *
     * @param Message[] $rows
     */
    private function finalReply(array $rows, array $speechByAgentId): ?string
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            if ($row->role === 'user' || !$row->didSpeak()) {
                continue;
            }

            $text = trim((string) ($speechByAgentId[$row->id] ?? $this->replyFromAgentRow($row)));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function replyFromAgentRow(Message $row): ?string
    {
        $raw = $row->metadata['tool_calls_raw'] ?? null;

        // Tag mode has no raw; in tool_calls mode a plain-text answer is stored
        // with the text itself as tool_calls_raw. Both: the text is the reply.
        $calls = $raw ? $this->decodeToolCalls($raw) : [];
        if ($calls === []) {
            return (string) $row->content;
        }

        foreach ($calls as [$name, $args]) {
            if (!str_contains(strtolower($name), 'speak')) {
                continue;
            }
            foreach ($args as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param Message[] $rows
     * @return string[]
     */
    private function toolNames(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $raw = $row->metadata['tool_calls_raw'] ?? null;
            if (!$raw) {
                continue;
            }
            foreach ($this->decodeToolCalls($raw) as [$name]) {
                // speak is the reply itself, not work done
                if ($name !== '' && !str_contains(strtolower($name), 'speak')) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * OpenAI-compatible {"tool_calls":[{"function":{"name","arguments"}}]} or
     * Anthropic [{"type":"tool_use","name","input"}].
     *
     * @return array<int, array{0: string, 1: array}>
     */
    private function decodeToolCalls(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded['tool_calls'] ?? [] as $call) {
            $args = $call['function']['arguments'] ?? [];
            if (is_string($args)) {
                $args = json_decode($args, true);
            }
            $out[] = [(string) ($call['function']['name'] ?? ''), is_array($args) ? $args : []];
        }

        if (array_is_list($decoded)) {
            foreach ($decoded as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                    $out[] = [(string) ($block['name'] ?? ''), is_array($block['input'] ?? null) ? $block['input'] : []];
                }
            }
        }

        return $out;
    }

    /**
     * One line, at most $chars characters.
     */
    private function clip(string $text, int $chars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $chars
            ? rtrim(mb_substr($text, 0, max(1, $chars - 1))) . '…'
            : $text;
    }
}

<?php

namespace App\Services\Agent\ContextWindow;

use App\Models\Message;

/**
 * Condenses an older episode into its outline: the input that opened it and
 * one in-memory agent row with what it came to.
 *
 *   user:     <the original input, unchanged>
 *   thinking: [Condensed earlier episode: 7 cycles, tools: run, browser.]
 *             Final reply: <what the agent said at the end>
 *
 * Tool results — the bulk of any working episode — are dropped; the question
 * and the answer stay. Roughly how a person remembers yesterday's task.
 *
 * The reply comes from the KIND_SPEECH system row linked to the episode's last
 * speaking agent row (AgentActionsHandler writes it). For history written
 * before that link existed, it falls back to the agent row itself: plain text
 * in tag mode, the arguments of a speak-like tool call in tool_calls mode.
 *
 * The built row is never saved. It takes the id of the episode's last row so
 * that sorting by id keeps it in place.
 */
class EpisodeSkeletonBuilder
{
    private const HEADER         = '[Condensed earlier episode: %d cycle(s)%s.]';
    private const HEADER_NOREPLY = '[Condensed earlier episode: %d cycle(s)%s; it ended without a reply.]';

    /**
     * @param array<int, string> $speechByAgentId agent row id → spoken text
     * @return Message[]|null head + condensed row, or null if the episode has no head
     */
    public function build(Episode $episode, array $speechByAgentId, int $replyChars): ?array
    {
        if ($episode->head === null || $episode->cycles === []) {
            return null;
        }

        $rows  = $episode->rows();
        $last  = $rows[array_key_last($rows)];
        $tools = $this->toolNames($rows);
        $reply = $this->finalReply($rows, $speechByAgentId);

        $toolsPart = $tools === [] ? '' : ', tools: ' . implode(', ', $tools);
        $cycles    = count($episode->cycles);

        if ($reply === null) {
            $content = sprintf(self::HEADER_NOREPLY, $cycles, $toolsPart);
        } else {
            $content = sprintf(self::HEADER, $cycles, $toolsPart)
                . "\nFinal reply: " . $this->truncate($reply, $replyChars);
        }

        $row = new Message();
        $row->forceFill([
            'id'           => $last->id,
            'role'         => 'thinking',
            'content'      => $content,
            'from_user_id' => null,
            'metadata'     => [
                'kind'        => Message::KIND_SKELETON,
                'skeleton_of' => ['from_id' => $rows[0]->id, 'to_id' => $last->id],
            ],
        ]);

        return [$episode->head, $row];
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

            $text = $speechByAgentId[$row->id] ?? $this->replyFromAgentRow($row);
            $text = trim((string) $text);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Fallback for rows without a linked speech row.
     */
    private function replyFromAgentRow(Message $row): ?string
    {
        $raw = $row->metadata['tool_calls_raw'] ?? null;

        // Tag mode has no raw at all; in tool_calls mode a plain-text answer is
        // stored with the text itself as tool_calls_raw. Both: the text is the reply.
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
     * Distinct tool names called in the episode, in first-use order.
     *
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
                // speak is the reply itself, not work done — it is shown below
                if ($name !== '' && !str_contains(strtolower($name), 'speak')) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Both stored shapes:
     *   OpenAI-compatible: {"tool_calls":[{"function":{"name":..,"arguments":"{..}"}}]}
     *   Anthropic:         [{"type":"tool_use","name":..,"input":{..}}]
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

    private function truncate(string $text, int $chars): string
    {
        $text = trim($text);

        return mb_strlen($text) > $chars
            ? rtrim(mb_substr($text, 0, max(1, $chars - 1))) . '…'
            : $text;
    }
}

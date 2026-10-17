<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

use App\Models\Message;

/**
 * "Who am I talking to right now?" — answered from the preset's own history.
 *
 * Walks back through recent user-role messages and stops at the first one
 * that came from a real interlocutor:
 *
 *   messenger           → that chat (metadata.reply_to, written by
 *                         InboundRouter), if still inside the reply window
 *   web / api / user id → someone at the DepthNet UI or API: speak stays here
 *   orchestrator, agent → a pipeline or another agent is the interlocutor
 *
 * Everything else is bookkeeping and is skipped: "Continue", pool remnants,
 * compaction recaps, skill/goal injections. No extra state is kept — the
 * history already says who spoke last, and survives restarts.
 *
 * Handoff messages from AgentMessageService carry no source marker (yet);
 * plain-mode ones are recognised by their "handoff response from" prefix.
 * Marking them with source=agent in AgentMessageService makes this exact for
 * pool mode too (see STEP7.md).
 */
class ReplyRouteResolver
{
    /** Sources that mean "the interlocutor is not in the messenger". */
    public const STOP_SOURCES = ['web', 'api', 'orchestrator', 'agent'];

    /** How far back to look past bookkeeping messages. */
    private const LOOKBACK = 30;

    private const HANDOFF_PREFIX = 'handoff response from ';

    public function __construct(
        protected Message $messageModel,
    ) {
    }

    /**
     * @return array{chat: string, chat_id?: string, message_id?: string, label?: string}|null
     */
    public function resolve(int $presetId, int $windowSeconds, ?int $now = null): ?array
    {
        return $this->pick($this->recentUserMessages($presetId), $windowSeconds, $now ?? time());
    }

    /**
     * Pure decision over messages newest-first. Each item needs `metadata`
     * (array|null), `from_user_id`, `content`, `created_at`.
     *
     * @param iterable<object> $messages
     */
    public function pick(iterable $messages, int $windowSeconds, int $now): ?array
    {
        foreach ($messages as $m) {
            $meta   = is_array($m->metadata ?? null) ? $m->metadata : [];
            $source = $meta['source'] ?? null;

            if ($source === InboundRouter::SOURCE) {
                $route = $meta['reply_to'] ?? null;
                if (!is_array($route) || empty($route['chat'])) {
                    return null;   // delivered without a reply route (speak replies off)
                }

                $at = $this->timestamp($m->created_at ?? null);
                if ($at === null || $now - $at > $windowSeconds) {
                    return null;   // conversation went quiet
                }

                return $route;
            }

            if (in_array($source, self::STOP_SOURCES, true) || !empty($m->from_user_id)) {
                return null;
            }

            if ($source === null && str_starts_with((string) ($m->content ?? ''), self::HANDOFF_PREFIX)) {
                return null;       // unmarked plain-mode handoff from another agent
            }
        }

        return null;
    }

    /**
     * @return iterable<Message>
     */
    protected function recentUserMessages(int $presetId): iterable
    {
        return $this->messageModel->newQuery()
            ->where('preset_id', $presetId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->limit(self::LOOKBACK)
            ->get(['id', 'from_user_id', 'metadata', 'content', 'created_at']);
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            $t = strtotime($value);
            return $t === false ? null : $t;
        }

        return null;
    }
}

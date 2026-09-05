<?php

namespace App\Services\Agent\ContextBuilder\Traits;

use App\Models\Message;

/**
 * Trait for cleaning message content in context builders
 */
trait ContentCleaningTrait
{
    /**
     * Clean message content from whitespace and empty lines
     *
     * @param string $content
     * @return string
     */
    protected function cleanMessageContent(string $content): string
    {
        // Remove leading/trailing whitespace
        $content = trim($content);

        // Remove excessive empty lines (more than 2 consecutive)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        // Remove trailing whitespace from each line
        $content = preg_replace('/[ \t]+$/m', '', $content);

        return $content;
    }

    /**
     * Build message array with cleaned content
     *
     * @param Message $message
     * @return array|null Returns null if message should be skipped
     */
    protected function buildCleanMessageArray(Message $message): ?array
    {
        $cleanContent = $this->cleanMessageContent($message->content);

        // Skip empty messages after cleaning — BUT don't touch tool-turns,
        // whose content may be empty, and the entire payload is in the metadata
        $metadata = $message->metadata ?? [];
        $hasToolPayload = !empty($metadata['tool_calls_raw']) || !empty($metadata['tool_results']);

        if (empty($cleanContent) && !$hasToolPayload) {
            return null;
        }

        return [
            'role' => $message->role,
            'content' => $cleanContent,
            'from_user_id' => $message->from_user_id,
            'metadata' => $metadata,
        ];
    }

    /**
     * Build context array from messages with content cleaning
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @return array
     */
    protected function buildCleanContextFromMessages(\Illuminate\Database\Eloquent\Collection $messages): array
    {
        $context = [];

        foreach ($messages as $message) {
            $messageArray = $this->buildCleanMessageArray($message);

            // Skip null messages (empty after cleaning)
            if ($messageArray !== null) {
                $context[] = $messageArray;
            }
        }

        return $context;
    }

    /**
     * Strip leading 'command' messages — the first message in context
     * must not have the 'command' role (AI APIs may reject it or
     * misinterpret the conversation start).
     *
     * @param array $context
     * @return void
     */
    protected function stripLeadingCommandMessages(array &$context): void
    {
        while (!empty($context) && ($context[array_key_first($context)]['role'] ?? null) === 'result') {
            array_shift($context);
        }
    }

    /**
     * Reposition the partial (watchdog) compression recap to its logical place:
     * before the last contiguous group of fresh user messages.
     *
     * The watchdog collapses the head of the window while leaving the tail intact,
     * but the recap is created with a higher ID and physically placed AFTER the tail.
     * Here, we move it to the boundary between the "collapsed section" and the
     * "fresh incoming block" so that the model accesses its memory of the
     * preceding messages before the new ones, rather than after them.
     *
     * A no-op during an agent-initiated [compact] (since the recap is already
     * in the tail, with no user messages following it).
     *
     * @param array $context
     * @return void
     */
    protected function liftCompactionRecap(array &$context): void
    {
        if (empty($context)) {
            return;
        }

        $recapIndex = null;
        foreach ($context as $i => $msg) {
            if (($msg['role'] ?? null) === 'user'
                && (($msg['metadata']['source'] ?? null) === Message::SOURCE_COMPACTION)) {
                $recapIndex = $i;
                break;
            }
        }

        if ($recapIndex === null) {
            return; // There is no compression in the window.
        }

        $recap = $context[$recapIndex];
        unset($context[$recapIndex]);
        $context = array_values($context);

        // From the end we skip a continuous tail of new users
        // (we do not count the recap itself as a user—otherwise, the old recap would shift the boundary).
        $insertAt = count($context);
        for ($i = count($context) - 1; $i >= 0; $i--) {
            $isFreshUser = ($context[$i]['role'] ?? null) === 'user'
                && (($context[$i]['metadata']['source'] ?? null) !== Message::SOURCE_COMPACTION);

            if ($isFreshUser) {
                $insertAt = $i;
            } else {
                break;
            }
        }

        array_splice($context, $insertAt, 0, [$recap]);
    }

}

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
     * Strip leading 'result' messages — a tool result whose call is not in the
     * window (provider APIs reject an orphan tool turn).
     *
     * With ContextWindowSelector the window no longer starts mid-cycle, so this
     * only matters for rows the selector does not control (e.g. the very start
     * of history). Kept as a cheap safety net.
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
     * Reposition a compaction recap before the fresh user messages that were
     * left unfolded by the same fold.
     *
     * A fold marks everything up to the last agent turn as compacted; user rows
     * that arrived after that turn stay in the window. The recap is written
     * after them, so by id it sits AFTER the inputs it should precede. Here it
     * is moved to the start of the contiguous run of user rows directly in
     * front of it: memory of what came before, then the new inputs.
     *
     * The run is looked for immediately BEFORE the recap, not at the end of the
     * context. Searching from the end (the previous behaviour) re-lifted an old
     * recap every cycle: once the agent had moved on, the recap jumped from its
     * place to just before the newest user turn, and the model kept reading a
     * stale summary as if it were the latest thing before the current input.
     * Anchoring to the recap's own neighbours makes the result stable — the
     * same placement every cycle for the whole life of the recap.
     *
     * A no-op when the recap has no user rows directly before it.
     *
     * @param array $context
     * @return void
     */
    protected function liftCompactionRecap(array &$context): void
    {
        if (empty($context)) {
            return;
        }

        $context = array_values($context);

        $recapIndex = null;
        foreach ($context as $i => $msg) {
            if ($this->isRecapEntry($msg)) {
                $recapIndex = $i; // keep the LAST one: the latest recap is the live one
            }
        }

        if ($recapIndex === null) {
            return; // No compaction in the window.
        }

        // Walk back over the contiguous fresh user rows right before the recap
        // (another recap does not count as fresh input).
        $insertAt = $recapIndex;
        for ($i = $recapIndex - 1; $i >= 0; $i--) {
            $isFreshUser = ($context[$i]['role'] ?? null) === 'user' && !$this->isRecapEntry($context[$i]);

            if (!$isFreshUser) {
                break;
            }
            $insertAt = $i;
        }

        if ($insertAt === $recapIndex) {
            return;
        }

        $recap = $context[$recapIndex];
        array_splice($context, $recapIndex, 1);
        array_splice($context, $insertAt, 0, [$recap]);
    }

    /**
     * @param array $msg context entry
     */
    private function isRecapEntry(array $msg): bool
    {
        if (($msg['role'] ?? null) !== 'user') {
            return false;
        }

        $meta = $msg['metadata'] ?? [];

        return ($meta['source'] ?? null) === Message::SOURCE_COMPACTION
            || ($meta['kind'] ?? null) === Message::KIND_RECAP;
    }

}

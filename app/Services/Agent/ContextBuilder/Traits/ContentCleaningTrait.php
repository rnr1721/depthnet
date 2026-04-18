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

}

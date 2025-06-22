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

        // Skip empty messages after cleaning
        if (empty($cleanContent)) {
            return null;
        }

        return [
            'role' => $message->role,
            'content' => $cleanContent,
            'from_user_id' => $message->from_user_id,
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
     * Clean instruction text (for cycle instructions)
     *
     * @param string $instruction
     * @return string
     */
    protected function cleanInstruction(string $instruction): string
    {
        return trim($instruction);
    }
}

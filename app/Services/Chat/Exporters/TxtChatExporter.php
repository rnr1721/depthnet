<?php

namespace App\Services\Chat\Exporters;

use App\Contracts\Chat\ChatExporterInterface;

class TxtChatExporter implements ChatExporterInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'txt';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'Text';
    }

    /**
     * @inheritDoc
     */
    public function getExtension(): string
    {
        return 'txt';
    }

    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return 'text/plain';
    }

    /**
     * Export chat messages to plain text format
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @param array $options
     * @return string
     */
    public function export($messages, array $options = []): string
    {
        $output = [];

        $output[] = "Chat Export";
        $output[] = "Generated: " . now()->format('Y-m-d H:i:s');
        $output[] = "Total messages: " . $messages->count();
        $output[] = str_repeat("=", 50);
        $output[] = "";

        foreach ($messages as $message) {
            $timestamp = $message->created_at->format('Y-m-d H:i:s');
            $role = $this->formatRole($message->role);

            $output[] = "[{$timestamp}] {$role}:";
            $output[] = $message->content;
            $output[] = "";
        }

        return implode("\n", $output);
    }

    /**
     * Format role name for display
     *
     * @param string $role
     * @return string
     */
    protected function formatRole(string $role): string
    {
        return match($role) {
            'user' => 'User',
            'assistant' => 'Assistant',
            'thinking' => 'AI Thinking',
            'speaking' => 'AI Speaking',
            'command' => 'Command',
            default => ucfirst($role)
        };
    }
}

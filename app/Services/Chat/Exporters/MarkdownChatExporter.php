<?php

namespace App\Services\Chat\Exporters;

use App\Contracts\Chat\ChatExporterInterface;

class MarkdownChatExporter implements ChatExporterInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'markdown';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'Markdown';
    }

    /**
     * @inheritDoc
     */
    public function getExtension(): string
    {
        return 'md';
    }

    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return 'text/markdown';
    }

    /**
     * Export chat messages to Markdown format
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @param array $options
     * @return string
     */
    public function export($messages, array $options = []): string
    {
        $output = [];

        $output[] = "# Chat Export";
        $output[] = "";
        $output[] = "**Generated:** " . now()->format('Y-m-d H:i:s');
        $output[] = "**Total messages:** " . $messages->count();
        $output[] = "";
        $output[] = "---";
        $output[] = "";

        foreach ($messages as $message) {
            $timestamp = $message->created_at->format('Y-m-d H:i:s');
            $role = $this->formatRole($message->role);

            $output[] = "## {$role}";
            $output[] = "*{$timestamp}*";
            $output[] = "";
            $output[] = $message->content;
            $output[] = "";
            $output[] = "---";
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
            'user' => ' User',
            'assistant' => 'Assistant',
            'thinking' => 'AI Thinking',
            'speaking' => 'AI Speaking',
            'command' => 'Command',
            default => ' ' . ucfirst($role)
        };
    }
}

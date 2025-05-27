<?php

namespace App\Services\Chat\Exporters;

use App\Contracts\Chat\ChatExporterInterface;

class JsonChatExporter implements ChatExporterInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'json';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'JSON';
    }

    /**
     * @inheritDoc
     */
    public function getExtension(): string
    {
        return 'json';
    }

    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return 'application/json';
    }

    /**
     * Export chat messages to JSON format
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @param array $options
     * @return string
     */
    public function export($messages, array $options = []): string
    {
        $exportData = [
            'exported_at' => now()->toISOString(),
            'total_messages' => $messages->count(),
            'options' => $options,
            'messages' => $messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'from_user_id' => $message->from_user_id,
                    'is_visible_to_user' => $message->is_visible_to_user,
                    'created_at' => $message->created_at->toISOString(),
                    'metadata' => $message->metadata
                ];
            })->toArray()
        ];

        return json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

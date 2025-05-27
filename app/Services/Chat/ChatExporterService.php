<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatExporterRegistryInterface;
use App\Contracts\Chat\ChatServiceInterface;
use Illuminate\Http\Response;

class ChatExporterService implements ChatExporterServiceInterface
{
    public function __construct(
        protected ChatExporterRegistryInterface $exporterRegistry,
        protected ChatServiceInterface $chatService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function export(string $format, array $options = []): Response
    {
        $exporter = $this->exporterRegistry->get($format);
        $messages = $this->chatService->getAllMessages();

        $content = $exporter->export($messages, $options);
        $filename = 'chat_export_' . date('Y-m-d_H-i-s') . '.' . $exporter->getExtension();

        return response($content)
            ->header('Content-Type', $exporter->getMimeType())
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @inheritDoc
     */
    public function getAvailableFormats(): array
    {
        $exporters = $this->exporterRegistry->all();

        return array_map(function ($exporter) {
            return [
                'name' => $exporter->getName(),
                'displayName' => $exporter->getDisplayName(),
                'extension' => $exporter->getExtension()
            ];
        }, $exporters);
    }
}

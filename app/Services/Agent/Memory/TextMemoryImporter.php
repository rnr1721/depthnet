<?php

namespace App\Services\Agent\Memory;

use App\Contracts\Agent\Memory\MemoryImporterInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Models\AiPreset;
use Illuminate\Http\Request;

/**
 * Service for importing memory content from files or direct input
 */
class TextMemoryImporter implements MemoryImporterInterface
{
    public function __construct(
        protected MemoryServiceInterface $memoryService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function import(AiPreset $preset, Request $request, array $config): array
    {
        try {
            $content = $this->extractContent($request);

            if (!$this->validateContent($content)) {
                return [
                    'success' => false,
                    'message' => 'Content is empty or contains no valid data.'
                ];
            }

            // Clear existing memory if requested
            if ($request->boolean('replace_existing')) {
                $clearResult = $this->memoryService->clearMemory($preset, $config);
                if (!$clearResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'Failed to clear existing memory: ' . $clearResult['message']
                    ];
                }
            }

            $result = $this->memoryService->addMemoryItem($preset, $content, $config);

            if ($result['success']) {
                $action = $request->boolean('replace_existing') ? 'replaced' : 'imported';
                return [
                    'success' => true,
                    'message' => "Memory {$action} successfully."
                ];
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error importing content: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function extractContent(Request $request): ?string
    {
        if ($request->hasFile('file')) {
            return file_get_contents($request->file('file')->path());
        }

        return $request->input('content');
    }

    /**
     * @inheritDoc
     */
    public function validateContent(?string $content): bool
    {
        return !empty(trim($content ?? ''));
    }
}

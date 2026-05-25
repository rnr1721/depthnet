<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders file chunk search results.
 *
 * File item metadata:
 *   - 'chunk'      : FileChunk model (must expose id, file_id, chunk_index,
 *                                     content, and ->file->original_name)
 *   - 'similarity' : float — similarity score 0..1
 *
 * Output:
 *   1. [filename.pdf | chunk#3 | 72.5%] Content preview...
 */
final class FilesSectionRenderer extends AbstractSectionRenderer
{
    public function supports(): string
    {
        return RagSectionType::Files->value;
    }

    public function defaultLabel(): string
    {
        return '[RELEVANT FILE CONTENT]';
    }

    public function render(RagSectionInterface $section): array
    {
        $options         = $section->getRenderOptions();
        $maxContentLimit = (int) $this->option($options, 'max_content_limit', self::DEFAULT_CONTENT_LIMIT);

        $lines = [];
        $num   = 1;

        foreach ($section->getItems() as $item) {
            $meta  = $item->getMetadata();
            $chunk = $meta['chunk'] ?? null;

            if ($chunk === null) {
                continue;
            }

            $similarity = (float) ($meta['similarity'] ?? $item->getScore() ?? 0);
            $score      = round($similarity * 100, 1);
            $fileName   = $chunk->file->original_name ?? ("file#" . $chunk->file_id);
            $preview    = mb_substr($chunk->content, 0, $maxContentLimit);

            $lines[] = sprintf(
                '%d. [%s | chunk#%d | %s%%] %s',
                $num++,
                $fileName,
                $chunk->chunk_index,
                $score,
                $preview,
            );
        }

        return $lines;
    }
}

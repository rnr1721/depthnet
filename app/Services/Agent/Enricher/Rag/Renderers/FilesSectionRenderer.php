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
            $meta = $item->getMetadata();

            if (!isset($meta['file_name'])) {
                continue;
            }

            $similarity = (float) ($meta['similarity'] ?? $item->getScore() ?? 0);
            $score      = round($similarity * 100, 1);
            $preview    = mb_substr($meta['content'] ?? $item->getContent(), 0, $maxContentLimit);

            $lines[] = sprintf(
                '%d. [%s | chunk#%d | %s%%] %s',
                $num++,
                $meta['file_name'],
                $meta['chunk_index'] ?? 0,
                $score,
                $preview,
            );
        }

        return $lines;
    }
}

<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileServiceInterface;
use App\Contracts\Agent\FileStorage\FileStorageFactoryInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\AiPreset;
use App\Models\File;
use App\Models\FileChunk;
use App\Services\Agent\Capabilities\Embedding\EmbeddingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Main orchestrator for the file storage pipeline.
 *
 * Flow for a new file:
 *   1. store()     — physical file → disk via FileStorageFactory driver
 *   2. process()   — extract text chunks via FileProcessorRegistry
 *   3. index()     — TF-IDF vectors + optional embeddings → file_chunks table
 *
 * Search flow:
 *   - embedding cosine similarity (if available) with TF-IDF fallback
 *   - scoped to preset (private + global files)
 */
class FileService implements FileServiceInterface
{
    public function __construct(
        protected FileStorageFactoryInterface $storageFactory,
        protected FileProcessorRegistry $processorRegistry,
        protected TfIdfServiceInterface $tfIdfService,
        protected EmbeddingService $embeddingService,
        protected File $fileModel,
        protected FileChunk $fileChunkModel,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    /**
     * Store an uploaded file for a preset, run the processing pipeline,
     * and return the persisted File model.
     *
     * @param  UploadedFile  $upload
     * @param  AiPreset      $preset
     * @param  string        $driver       laravel|sandbox
     * @param  string        $scope        private|global
     * @param  string|null   $projectSlug  Optional project context
     */
    public function store(
        UploadedFile $upload,
        AiPreset $preset,
        string $driver = 'laravel',
        string $scope = 'private',
        ?string $projectSlug = null,
    ): File {
        // Сохраняем всё что нужно ДО физического перемещения файла
        $originalName = $upload->getClientOriginalName();
        $mimeType     = $upload->getMimeType() ?? 'application/octet-stream';
        $size         = $upload->getSize();

        $storage     = $this->storageFactory->make($driver);
        $storagePath = $storage->store($upload, $preset, $projectSlug);
        // После этой строки $upload уже невалиден для sandbox driver!

        $file = $this->fileModel->create([
            'preset_id'         => $preset->id,
            'original_name'     => $originalName,
            'mime_type'         => $mimeType,
            'storage_driver'    => $driver,
            'storage_path'      => $storagePath,
            'size'              => $size,
            'scope'             => $scope,
            'processing_status' => 'pending',
        ]);

        $this->process($file, $preset);

        return $file->fresh();
    }

    // -------------------------------------------------------------------------
    // Process
    // -------------------------------------------------------------------------

    /**
     * Run the processing pipeline for an already-stored file.
     * Safe to call multiple times — re-indexes from scratch.
     */
    public function process(File $file, AiPreset $preset): void
    {
        $file->markProcessing();

        try {
            $storage  = $this->storageFactory->make($file->storage_driver);
            $absPath  = $storage->absolutePath($file);

            $processor = $this->processorRegistry->resolve($file->mime_type);

            if (!$processor) {
                $file->markFailed("No processor found for MIME type: {$file->mime_type}");
                return;
            }

            $result = $processor->process($file, $absPath);

            if (!$result->success) {
                $file->markFailed($result->error ?? 'Processing failed');
                return;
            }

            // Delete old chunks before re-indexing
            $file->chunks()->delete();

            $this->indexChunks($file, $result->chunks, $preset);

            $file->markProcessed($result->meta);

            $this->logger->info('FileService: file processed', [
                'file_id'     => $file->id,
                'chunks'      => count($result->chunks),
                'mime_type'   => $file->mime_type,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('FileService::process error', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            $file->markFailed($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search file chunks visible to a preset using embedding → TF-IDF fallback.
     *
     * @return array{success: bool, results: array, message: string}
     */
    public function search(
        AiPreset $preset,
        string $query,
        int $limit = 5,
        float $threshold = 0.2,
    ): array {
        $query = trim($query);

        if ($query === '') {
            return ['success' => false, 'results' => [], 'message' => 'Search query cannot be empty.'];
        }

        // Load all processed chunks for files visible to this preset
        $chunks = $this->fileChunkModel->query()
            ->whereHas('file', fn ($q) => $q->forPreset($preset->id)->processed())
            ->get();

        if ($chunks->isEmpty()) {
            return ['success' => true, 'results' => [], 'message' => 'No indexed file chunks found.'];
        }

        // Try embedding search first
        $queryEmbedding = $this->embeddingService->embed($query, $preset);

        if ($queryEmbedding !== null) {
            return $this->embeddingSearch($chunks, $queryEmbedding, $query, $limit, $threshold, $preset);
        }

        return $this->tfidfSearch($chunks, $query, $limit, $threshold);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    /**
     * Delete a file — removes physical file, chunks, and the DB record.
     */
    public function delete(File $file): bool
    {
        try {
            $storage = $this->storageFactory->make($file->storage_driver);
            $storage->delete($file);
            $file->delete(); // chunks cascade via FK
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('FileService::delete error', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Backfill embeddings
    // -------------------------------------------------------------------------

    /**
     * Attach embeddings to chunks that were indexed without one.
     * Run via artisan command or queued job.
     *
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function backfillEmbeddings(AiPreset $preset, int $batchSize = 50): array
    {
        if (!$this->embeddingService->isAvailable($preset)) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => 0,
                    'error' => 'No active embedding capability for this preset.'];
        }

        $chunks = $this->fileChunkModel->query()
            ->whereHas('file', fn ($q) => $q->where('preset_id', $preset->id))
            ->whereNull('embedding')
            ->limit($batchSize)
            ->get();

        $processed = $failed = 0;

        foreach ($chunks as $chunk) {
            $vector = $this->embeddingService->embed($chunk->content, $preset);

            if ($vector !== null) {
                $chunk->update(['embedding' => $vector, 'embedding_dim' => count($vector)]);
                $processed++;
            } else {
                $failed++;
            }
        }

        $remaining = $this->fileChunkModel->query()
            ->whereHas('file', fn ($q) => $q->where('preset_id', $preset->id))
            ->whereNull('embedding')
            ->count();

        return compact('processed', 'failed', 'remaining');
    }

    public function getDownloadPath(File $file): string
    {
        $storage = $this->storageFactory->make($file->storage_driver);

        if (!$storage->exists($file)) {
            throw new \RuntimeException("File no longer exists on disk: {$file->original_name}");
        }

        return $storage->absolutePath($file);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Create FileChunk records with TF-IDF vectors (and optionally embeddings).
     *
     * @param  string[]  $chunks
     */
    private function indexChunks(File $file, array $chunks, AiPreset $preset): void
    {
        if (empty($chunks)) {
            return;
        }

        $embeddingAvailable = $this->embeddingService->isAvailable($preset);

        foreach ($chunks as $index => $text) {
            $vector   = $this->tfIdfService->vectorize($text);
            $keywords = $this->extractKeywords($vector);

            $embedding    = null;
            $embeddingDim = null;

            if ($embeddingAvailable) {
                $vec = $this->embeddingService->embed($text, $preset);
                if ($vec !== null) {
                    $embedding    = $vec;
                    $embeddingDim = count($vec);
                }
                usleep(200000);
            }

            $this->fileChunkModel->create([
                'file_id'       => $file->id,
                'chunk_index'   => $index,
                'content'       => $text,
                'tfidf_vector'  => $vector,
                'embedding'     => $embedding,
                'embedding_dim' => $embeddingDim,
                'keywords'      => $keywords,
            ]);
        }
    }

    /**
     * Cosine similarity search over chunks that have embeddings,
     * supplemented by TF-IDF for chunks without.
     */
    private function embeddingSearch(
        Collection $chunks,
        array $queryEmbedding,
        string $query,
        int $limit,
        float $threshold,
        AiPreset $preset,
    ): array {
        $withEmbedding    = $chunks->filter(fn ($c) => !empty($c->embedding));
        $withoutEmbedding = $chunks->filter(fn ($c) => empty($c->embedding));

        $results = [];

        foreach ($withEmbedding as $chunk) {
            $similarity = $this->embeddingService->cosineSimilarity($queryEmbedding, $chunk->embedding);
            if ($similarity >= $threshold) {
                $results[] = ['chunk' => $chunk, 'similarity' => $similarity, 'source' => 'embedding'];
            }
        }

        usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $results = array_slice($results, 0, $limit);

        // Supplement with TF-IDF for chunks lacking embeddings
        if ($withoutEmbedding->isNotEmpty() && count($results) < $limit) {
            $seenIds   = array_map(fn ($r) => $r['chunk']->id, $results);
            $remaining = $limit - count($results);

            $tfidfResults = $this->tfIdfService->findSimilar(
                $query,
                $withoutEmbedding,
                $remaining,
                0.1,
                false, // file chunks don't age like memories
            );

            foreach ($tfidfResults as $r) {
                if (!in_array($r['document']->id, $seenIds, true)) {
                    $results[] = [
                        'chunk'      => $r['document'],
                        'similarity' => $r['similarity'],
                        'source'     => 'tfidf_fallback',
                    ];
                }
            }

            usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
            $results = array_slice($results, 0, $limit);
        }

        return [
            'success'        => true,
            'results'        => $results,
            'message'        => 'Found ' . count($results) . ' chunks via embedding search.',
            'embedding_used' => true,
        ];
    }

    /**
     * TF-IDF fallback search over all chunks.
     */
    private function tfidfSearch(
        Collection $chunks,
        string $query,
        int $limit,
        float $threshold,
    ): array {
        $results = $this->tfIdfService->findSimilar($query, $chunks, $limit, $threshold, false);

        $mapped = array_map(fn ($r) => [
            'chunk'      => $r['document'],
            'similarity' => $r['similarity'],
            'source'     => 'tfidf',
        ], $results);

        return [
            'success'        => true,
            'results'        => $mapped,
            'message'        => 'Found ' . count($mapped) . ' chunks via TF-IDF search.',
            'embedding_used' => false,
        ];
    }

    /**
     * Extract top keywords from a TF-IDF vector (top 10 by weight).
     *
     * @return string[]
     */
    private function extractKeywords(array $tfidfVector): array
    {
        if (empty($tfidfVector)) {
            return [];
        }

        arsort($tfidfVector);
        return array_slice(array_keys($tfidfVector), 0, 10);
    }
}

<?php

namespace App\Jobs\File;

use App\Contracts\Agent\FileStorage\FileServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateChunkEmbedding implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected const API_DELAY_MS = 200;

    public function __construct(
        public int $chunkId,
        public int $presetId,
    ) {
        $this->onQueue('embeddings');
    }

    public function handle(FileServiceInterface $fileService): void
    {
        usleep(self::API_DELAY_MS * 1000);
        $fileService->generateChunkEmbedding($this->chunkId, $this->presetId);
    }
}

<?php

namespace App\Services\Agent\DTO;

use App\Contracts\Agent\AiModelResponseInterface;

class ModelResponseDTO implements AiModelResponseInterface
{
    public function __construct(
        private string $response,
        private bool $isError = false,
        private array $metadata = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getResponse(): string
    {
        return $this->response;
    }

    /**
     * @inheritDoc
     */
    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

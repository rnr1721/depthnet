<?php

namespace App\Contracts\Agent;

interface AiModelResponseInterface
{
    /**
     * String model response
     *
     * @return string
     */
    public function getResponse(): string;

    /**
     * GetError if exists
     *
     * @return bool
     */
    public function isError(): bool;

    /**
     * Get message metadata from model with addition data
     * if present
     *
     * @return array
     */
    public function getMetadata(): array;
}

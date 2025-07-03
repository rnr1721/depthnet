<?php

declare(strict_types=1);

namespace App\Services\Sandbox\DTO;

/**
 * Represents a sandbox container instance
 */
class SandboxInstance
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $status,
        public readonly string $image,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $metadata = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'image' => $this->image,
            'created_at' => $this->createdAt->format('c'),
            'metadata' => $this->metadata,
            'ports' => $this->metadata['ports'] ?? []
        ];
    }
}

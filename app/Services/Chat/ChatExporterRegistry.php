<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatExporterRegistryInterface;
use App\Contracts\Chat\ChatExporterInterface;

class ChatExporterRegistry implements ChatExporterRegistryInterface
{
    /**
     * @var ChatExporterInterface[]
     */
    protected array $exporters = [];

    /**
     * @inheritDoc
     */
    public function register(ChatExporterInterface $exporter): self
    {
        $this->exporters[$exporter->getName()] = $exporter;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->exporters[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ChatExporterInterface
    {
        if (!$this->has($name)) {
            throw new \Exception("Exporter '$name' not found");
        }

        return $this->exporters[$name];
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->exporters;
    }
}

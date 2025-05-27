<?php

namespace App\Contracts\Chat;

interface ChatExporterRegistryInterface
{
    /**
     * Register exporter
     *
     * @param ChatExporterInterface $exporter
     * @return self
     */
    public function register(ChatExporterInterface $exporter): self;

    /**
     * Check if exporter exists
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * Get exporter by name
     *
     * @param string $name
     * @return ChatExporterInterface
     * @throws \Exception
     */
    public function get(string $name): ChatExporterInterface;

    /**
     * Get all exporters
     *
     * @return ChatExporterInterface[]
     */
    public function all(): array;
}

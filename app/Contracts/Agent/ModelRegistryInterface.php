<?php

namespace App\Contracts\Agent;

use App\Contracts\Agent\AIModelInterface;

interface ModelRegistryInterface
{
    /**
     * Register a model
     *
     * @param AIModelInterface $model
     * @param bool $isDefault
     * @return self
     */
    public function register(AIModelInterface $model, bool $isDefault = false): self;

    /**
     * Check model availability
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * Get model by name
     *
     * @param string|null $name
     * @return AIModelInterface
     * @throws \Exception
     */
    public function get(?string $name = null): AIModelInterface;

    /**
     * Get all models
     *
     * @return AIModelInterface[]
     */
    public function all(): array;

    /**
     * Get default model name
     *
     * @return string|null
     */
    public function getDefaultModelName(): ?string;

    /**
     * Set model as default
     *
     * @param string $name
     * @return self
     * @throws \Exception
     */
    public function setDefaultModel(string $name): self;

}

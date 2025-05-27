<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AIModelInterface;
use App\Contracts\Agent\ModelRegistryInterface;

class ModelRegistry implements ModelRegistryInterface
{
    /**
     * @var AIModelInterface[]
     */
    protected array $models = [];

    /**
     * @var string|null
     */
    protected ?string $defaultModel = null;

    /**
     * @inheritDoc
     */
    public function register(AIModelInterface $model, bool $isDefault = false): self
    {
        $name = $model->getName();
        $this->models[$name] = $model;

        if ($isDefault || $this->defaultModel === null) {
            $this->defaultModel = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->models[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(?string $name = null): AIModelInterface
    {
        $modelName = $name ?? $this->defaultModel;

        if ($modelName === null || !$this->has($modelName)) {
            throw new \Exception("Model '$modelName' not found");
        }

        return $this->models[$modelName];
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultModelName(): ?string
    {
        return $this->defaultModel;
    }

    /**
     * @inheritDoc
     */
    public function setDefaultModel(string $name): self
    {
        if (!$this->has($name)) {
            throw new \Exception("Model '$name' not found");
        }

        $this->defaultModel = $name;
        return $this;
    }
}

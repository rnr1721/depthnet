<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\ContextEnricherInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use App\Models\AiPreset;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

class EnricherFactory implements EnricherFactoryInterface
{
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * @inheritDoc
     */
    public function makeContextEnricher(): ContextEnricherInterface
    {
        return $this->container->make(ContextEnricher::class);
    }

    /**
     * @inheritDoc
     */
    public function makeRagEnricher(): RagContextEnricherInterface
    {
        return $this->container->make(RagContextEnricher::class);
    }

    /**
     * @inheritDoc
     */
    public function makePersonEnricher(): PersonContextEnricherInterface
    {
        return $this->container->make(PersonContextEnricher::class);
    }

    /**
     * @inheritDoc
     */
    public function getOrderedRagConfigs(AiPreset $preset): Collection
    {
        return $preset->ragConfigs()->get();
    }
}

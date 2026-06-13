<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\CyclePromptEnricherInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\InnerVoiceEnricherInterface;
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
    public function makeInnerVoiceEnricher(): InnerVoiceEnricherInterface
    {
        return $this->container->make(InnerVoiceEnricher::class);
    }

    /**
     * @inheritDoc
     */
    public function makeCyclePromptEnricher(): CyclePromptEnricherInterface
    {
        return $this->container->make(CyclePromptEnricher::class);
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

    /**
     * @inheritDoc
     */
    public function getOrderedVoiceConfigs(AiPreset $preset): Collection
    {
        return $preset->innerVoiceConfigs()->enabled()->ordered()->get();
    }
}

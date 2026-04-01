<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\ContextEnricherInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use Illuminate\Contracts\Container\Container;

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
}

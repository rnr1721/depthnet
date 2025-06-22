<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;

class ContextBuilderFactory implements ContextBuilderFactoryInterface
{
    public function __construct(
        protected CycleContextBuilder $cycleContextBuilder,
        protected SingleContextBuilder $singleContextBuilder
    ) {
    }

    /**
     * Get context builder by mode
     *
     * @param string $mode
     * @return ContextBuilderInterface
     * @throws \InvalidArgumentException
     */
    public function getContextBuilder(string $mode): ContextBuilderInterface
    {
        return match ($mode) {
            'cycle' => $this->cycleContextBuilder,
            'single' => $this->singleContextBuilder,
            default => throw new \InvalidArgumentException("Unknown context builder mode: {$mode}")
        };
    }
}

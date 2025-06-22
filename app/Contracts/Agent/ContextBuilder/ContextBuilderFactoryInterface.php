<?php

namespace App\Contracts\Agent\ContextBuilder;

interface ContextBuilderFactoryInterface
{
    public function getContextBuilder(string $mode): ContextBuilderInterface;
}

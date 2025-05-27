<?php

namespace App\Contracts\Agent;

interface CommandInstructionBuilderInterface
{
    /**
     * Instructions for model, how use the plugins
     *
     * @return string
     */
    public function buildInstructions(): string;
}

<?php

namespace App\Contracts\Agent;

interface CommandParserInterface
{
    /**
     * Parse commands from agent output
     *
     * @param string $output
     * @return ParsedCommand[]
     */
    public function parse(string $output): array;
}

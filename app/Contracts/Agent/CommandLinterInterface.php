<?php

namespace App\Contracts\Agent;

interface CommandLinterInterface
{
    /**
     * Lint commands. This will create messages for commands with
     * command syntax errors
     *
     * @param string $output
     * @return array
     */
    public function lint(string $output): array;
}

<?php

namespace App\Contracts\Agent;

/**
 * Interface for command pre-processors
 *
 * Pre-processors prepare raw model output for parsing by:
 * - Auto-closing self-closing tags
 * - Extracting nested commands
 * - Normalizing syntax
 */
interface CommandPreProcessorInterface
{
    /**
     * Pre-process the output text before command parsing
     *
     * @param string $output Raw model output text
     * @return string Processed output ready for command parsing
     */
    public function preProcess(string $output): string;
}

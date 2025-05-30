<?php

namespace App\Contracts\Agent;

interface CommandPluginInterface
{
    /**
     * Get plugin (command) name
     *
     * @return string Command name
     */
    public function getName(): string;

    /**
     * Get command description
     *
     * @return string Command description
     */
    public function getDescription(): string;

    /**
     * Execute command
     *
     * @param string $content Content to execute
     * @return string Result of command execution
     */
    public function execute(string $content): string;

    /**
     * Check if plugin has specific method
     *
     * @param string $method Method name
     * @return boolean Method exists?
     */
    public function hasMethod(string $method): bool;

    /**
     * Call specific method on plugin
     *
     * @param string $method Method name
     * @param string $content Content to pass to the method
     * @return string
     */
    public function callMethod(string $method, string $content): string;

    /**
     * Get available methods for this plugin
     *
     * @return array List of available methods
     */
    public function getAvailableMethods(): array;

    /**
     * Instructions for model
     *
     * @return array Instructions
     */
    public function getInstructions(): array;

}

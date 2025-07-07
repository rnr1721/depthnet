<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPreProcessorInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use Psr\Log\LoggerInterface;

/**
 * Pre-processes command text before parsing:
 * 1. Auto-closes self-closing tags
 * 2. Extracts nested commands and flattens them
 */
class CommandPreProcessor implements CommandPreProcessorInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Pre-process the output text
     *
     * @param string $output Raw model output
     * @return string Processed output ready for parsing
     */
    public function preProcess(string $output): string
    {

        // Step 1: Auto-close self-closing tags
        $output = $this->autoCloseSelfClosingTags($output);

        // Step 2: Extract and flatten nested commands
        $output = $this->extractNestedCommands($output);

        return $output;
    }

    /**
     * Auto-close self-closing tags that don't need content
     *
     * @param string $output
     * @return string
     */
    protected function autoCloseSelfClosingTags(string $output): string
    {
        $selfClosingTags = $this->collectSelfClosingTags();

        if (empty($selfClosingTags)) {
            return $output;
        }

        $closedCount = 0;

        foreach ($selfClosingTags as $pluginName => $methods) {
            foreach ($methods as $method) {
                // Pattern: [plugin method] not followed by [/plugin] in reasonable distance
                $pattern = '/\[' . preg_quote($pluginName) . '\s+' . preg_quote($method) . '\](?![^[]{0,100}\[\/' . preg_quote($pluginName) . '\])/';

                $before = $output;
                $output = preg_replace($pattern, "[$pluginName $method][/$pluginName]", $output);

                if ($output !== $before) {
                    $closedCount++;
                    $this->logger->debug("CommandPreProcessor: Auto-closed self-closing tag", [
                        'plugin' => $pluginName,
                        'method' => $method
                    ]);
                }
            }
        }

        if ($closedCount > 0) {
            $this->logger->info("CommandPreProcessor: Auto-closed $closedCount self-closing tags");
        }

        return $output;
    }

    /**
     * Extract nested commands and move them to top level
     *
     * @param string $output
     * @return string
     */
    protected function extractNestedCommands(string $output): string
    {
        $knownPlugins = $this->pluginRegistry->getAvailablePluginNames();
        $extractedCommands = [];
        $iteration = 0;
        $maxIterations = 10; // Prevent infinite loops

        // Keep extracting until no more nested commands found
        while ($iteration < $maxIterations) {
            $iteration++;
            $foundNested = false;

            foreach ($knownPlugins as $plugin) {
                // Find complete command blocks for this plugin
                $pattern = '/\[' . preg_quote($plugin) . '(?:\s+([a-z][a-z0-9_]*))?\](.*?)\[\/' . preg_quote($plugin) . '\]/s';

                $output = preg_replace_callback($pattern, function ($matches) use ($knownPlugins, &$extractedCommands, &$foundNested, $plugin) {
                    $fullMatch = $matches[0];
                    $method = $matches[1] ?? '';  // This is the method from regex group
                    $content = $matches[2] ?? ''; // This is the content from regex group

                    // Look for nested commands in the content
                    $nestedCommands = $this->findNestedCommands($content, $knownPlugins);

                    if (!empty($nestedCommands)) {
                        $foundNested = true;

                        // Add nested commands to extraction list
                        foreach ($nestedCommands as $nestedCommand) {
                            $extractedCommands[] = $nestedCommand;
                        }

                        // Remove nested commands from parent content
                        $cleanedContent = $this->removeNestedCommands($content, $knownPlugins);

                        // Return the parent command with cleaned content
                        $methodPart = !empty(trim($method)) ? ' ' . trim($method) : '';
                        /*
                        \Log::error("PreProcessor restoring command", [
                            'plugin_from_loop' => $plugin,
                            'method_from_match' => $methodPart,
                            'result' => "[{$plugin}{$methodPart}]{$cleanedContent}[/{$plugin}]"
                        ]);
                        */
                        return "[{$plugin}{$methodPart}]{$cleanedContent}[/{$plugin}]";
                    }

                    return $fullMatch; // No nested commands, return as-is
                }, $output);
            }

            // If no nested commands found in this iteration, we're done
            if (!$foundNested) {
                break;
            }
        }

        // Append extracted commands at the end
        if (!empty($extractedCommands)) {
            $this->logger->info("CommandPreProcessor: Extracted " . count($extractedCommands) . " nested commands");
            $output .= "\n\n" . implode("\n", $extractedCommands);
        }

        return $output;
    }

    /**
     * Find nested commands within content
     *
     * @param string $content
     * @param array $knownPlugins
     * @return array
     */
    protected function findNestedCommands(string $content, array $knownPlugins): array
    {
        $nestedCommands = [];

        foreach ($knownPlugins as $plugin) {
            $pattern = '/\[' . preg_quote($plugin) . '(?:\s+([a-z][a-z0-9_]*))?\](.*?)\[\/' . preg_quote($plugin) . '\]/s';

            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $nestedCommands[] = $match[0]; // Full command including tags
                }
            }
        }

        return $nestedCommands;
    }

    /**
     * Remove nested commands from content
     *
     * @param string $content
     * @param array $knownPlugins
     * @return string
     */
    protected function removeNestedCommands(string $content, array $knownPlugins): string
    {
        foreach ($knownPlugins as $plugin) {
            $pattern = '/\[' . preg_quote($plugin) . '(?:\s+([a-z][a-z0-9_]*))?\](.*?)\[\/' . preg_quote($plugin) . '\]/s';
            $content = preg_replace($pattern, '', $content);
        }

        return trim($content);
    }

    /**
     * Collect all self-closing tags from all plugins
     *
     * @return array [plugin_name => [method1, method2], ...]
     */
    protected function collectSelfClosingTags(): array
    {
        $selfClosingTags = [];

        foreach ($this->pluginRegistry->all() as $plugin) {
            try {
                $tags = $plugin->getSelfClosingTags();
                if (!empty($tags)) {
                    $selfClosingTags[$plugin->getName()] = $tags;
                }
            } catch (\Exception $e) {
                $this->logger->warning("CommandPreProcessor: Failed to get self-closing tags from plugin", [
                    'plugin' => $plugin->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $selfClosingTags;
    }
}

<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Illuminate\Support\Facades\Log;

class MemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;

    private $limit = 2000;

    public function getName(): string
    {
        return 'memory';
    }

    public function getDescription(): string
    {
        return 'Persistent memory storage with append, clear, and replace operations. Limit is ' . $this->limit . ' symbols. Use it to remember important information between conversations.';
    }

    public function getInstructions(): array
    {
        return [
            'Append new information to memory: [memory]Completed task: Created users table successfully[/memory]',
            'Replace entire memory content: [memory replace]User prefers PHP over Python. Database credentials saved.[/memory]',
            'Clear all memory: [memory clear][/memory]'
        ];
    }

    /**
     * Default method - replace memory content
     */
    public function execute(string $content): string
    {
        return $this->append($content);
    }

    /**
     * Replace memory content entirely
     */
    public function replace(string $content): string
    {
        try {
            $this->preset->notes = $content;
            $this->preset->save();
            return "Memory replaced successfully. New content stored.";
        } catch (\Throwable $e) {
            Log::error("MemoryPlugin::replace error: " . $e->getMessage());
            return "Error replacing memory: " . $e->getMessage();
        }
    }

    /**
     * Append to existing memory content
     */
    public function append(string $content): string
    {
        try {
            $currentContent = $this->preset->notes;
            $newContent = empty($currentContent)
                ? $content
                : $currentContent . "\n" . $content;

            $this->preset->notes = $newContent;
            $this->preset->save();
            return "Content appended to memory successfully.";
        } catch (\Throwable $e) {
            Log::error("MemoryPlugin::append error: " . $e->getMessage());
            return "Error appending to memory: " . $e->getMessage();
        }
    }

    /**
     * Clear memory content
     */
    public function clear(string $content): string
    {
        try {
            $this->preset->notes = '';
            $this->preset->save();
            return "Memory cleared successfully.";
        } catch (\Throwable $e) {
            Log::error("MemoryPlugin::clear error: " . $e->getMessage());
            return "Error clearing memory: " . $e->getMessage();
        }
    }

    /**
     * Get current memory content
     */
    public function show(string $content): string
    {
        try {
            $currentContent = $this->preset->notes;
            return empty($currentContent)
                ? "Memory is empty."
                : "Current memory content:\n" . $currentContent;
        } catch (\Throwable $e) {
            Log::error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

}

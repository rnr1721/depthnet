<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Plugins\NotepadServiceInterface;
use Illuminate\Support\Facades\Log;

class MemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;

    private $limit = 2000;

    public function __construct(
        protected NotepadServiceInterface $notepadService
    ) {
    }

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
            'Replace entire memory content: [memory]User prefers PHP over Python. Database credentials saved.[/memory]',
            'Append new information: [memory append]Completed task: Created users table successfully[/memory]',
            'Clear all memory: [memory clear][/memory]',
            'Show current memory: [memory show][/memory]',

            'Save user preferences: [memory]User language: English, Timezone: UTC+3, Prefers detailed explanations[/memory]',
            'Remember database info: [memory append]DB: mysql://user:pass@host:3306/dbname - Connection tested OK[/memory]',
            'Track completed tasks: [memory append]✓ 2025-05-26: Created MyGuests table, inserted test data[/memory]',
            'Store API endpoints: [memory append]API Base: https://api.example.com/v1/ - Auth token expires daily[/memory]',

            'Remember session state: [memory]Current session: Working on user management system. Tables: users, roles, permissions[/memory]',
            'Track errors and solutions: [memory append]Error: "Connection refused" - Solution: Changed host from localhost to 172.16.238.10[/memory]',
            'Save configuration: [memory append]Config: max_execution_time=300, memory_limit=512M for large imports[/memory]',

            'Project overview: [memory]Project: E-commerce API. Stack: PHP 8.1, MySQL 8.0, Laravel 10. Deadline: End of month[/memory]',
            'Feature tracking: [memory append]Features completed: User auth, Product catalog. Next: Shopping cart, Payment integration[/memory]',
            'Important notes: [memory append] Remember: Use prepared statements for all DB queries, validate all inputs[/memory]',

            'Debug session: [memory]Debug mode: ON. Current issue: Slow queries on products table. Need to add indexes[/memory]',
            'Performance notes: [memory append]Performance: Query optimization reduced load time from 2.3s to 0.4s[/memory]',

            'User workflow: [memory]User works best in morning hours, prefers step-by-step explanations with code examples[/memory]',
            'Learning progress: [memory append]Learning: Mastered basic Laravel, now working on advanced Eloquent relationships[/memory]',

            'Security notes: [memory append] Security: Never log passwords, always use HTTPS in production, validate file uploads[/memory]',
            'Best practices: [memory append] Standards: PSR-12 coding style, PHPDoc comments, unit tests for critical functions[/memory]',

            'Complex information: [memory]Current project status:\n- Database schema: 85% complete\n- API endpoints: 12/20 done\n- Testing: Unit tests written\n- Deployment: Staging environment ready[/memory]',

            'IMPORTANT: Always use closing tag [/memory] after each memory command!'
        ];
    }

    /**
     * Default method - replace memory content
     */
    public function execute(string $content): string
    {
        return $this->replace($content);
    }

    /**
     * Replace memory content entirely
     */
    public function replace(string $content): string
    {
        try {
            $this->notepadService->setNotepad($content);
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
            $currentContent = $this->notepadService->getNotepad();
            $newContent = empty($currentContent)
                ? $content
                : $currentContent . "\n" . $content;

            $this->notepadService->setNotepad($newContent);
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
            $this->notepadService->setNotepad('');
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
            $currentContent = $this->notepadService->getNotepad();
            return empty($currentContent)
                ? "Memory is empty."
                : "Current memory content:\n" . $currentContent;
        } catch (\Throwable $e) {
            Log::error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }
}

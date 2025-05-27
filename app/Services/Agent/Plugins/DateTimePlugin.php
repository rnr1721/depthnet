<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;

class DateTimePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;

    public function getName(): string
    {
        return 'datetime';
    }

    public function getInstructions(): array
    {
        return [
            'Show current date and time: [datetime][/datetime]',
            'Show current date and time in some format: [datetime format]d.m.Y H:i[/datetime]',
            'Get current timestamp [datetime timestamp][/datetime]'
        ];
    }

    public function execute(string $content): string
    {
        return $this->now($content);
    }

    public function now(string $content): string
    {
        try {
            $dateTime = new \DateTime();
            return "Current date and time: " . $dateTime->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return "Error: " . $e->getMessage();
        }
    }

    public function format(string $content): string
    {
        try {
            $format = trim($content) ?: 'Y-m-d H:i:s';
            $dateTime = new \DateTime();
            return "Formatted time: " . $dateTime->format($format);
        } catch (\Throwable $e) {
            return "Error formatting date: " . $e->getMessage();
        }
    }

    public function timestamp(string $content): string
    {
        try {
            $timestamp = time();
            return "Current timestamp: " . $timestamp;
        } catch (\Throwable $e) {
            return "Error: " . $e->getMessage();
        }
    }

    public function getDescription(): string
    {
        return 'Date and time operations with various formatting options.';
    }
}

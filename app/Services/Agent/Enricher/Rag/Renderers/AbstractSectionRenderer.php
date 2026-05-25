<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionRendererInterface;
use Carbon\Carbon;

/**
 * Shared utilities for section renderers.
 *
 * Encapsulates the relative-date formatting that several renderers need
 * (memory, journal). Renderers extend this and implement supports(),
 * defaultLabel(), and render().
 */
abstract class AbstractSectionRenderer implements RagSectionRendererInterface
{
    /**
     * Default per-item content truncation when not overridden via render options.
     */
    protected const DEFAULT_CONTENT_LIMIT = 500;

    /**
     * Human-friendly relative date: "5m ago", "2h ago", "3d ago", "just now"...
     */
    protected function formatRelativeDate(\DateTimeInterface|Carbon $date): string
    {
        $diff = abs(now()->diffInSeconds($date, false));

        return match (true) {
            $diff < 60         => 'just now',
            $diff < 3600       => (int) ($diff / 60) . 'm ago',
            $diff < 86400      => (int) ($diff / 3600) . 'h ago',
            $diff < 86400 * 7  => (int) ($diff / 86400) . 'd ago',
            $diff < 86400 * 30 => (int) ($diff / (86400 * 7)) . 'w ago',
            default            => (int) ($diff / (86400 * 30)) . 'mo ago',
        };
    }

    /**
     * Resolve a render option with a default fallback.
     */
    protected function option(array $options, string $key, mixed $default): mixed
    {
        return $options[$key] ?? $default;
    }
}

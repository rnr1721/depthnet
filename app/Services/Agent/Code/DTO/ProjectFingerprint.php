<?php

namespace App\Services\Agent\Code\DTO;

/**
 * ProjectFingerprint
 *
 * Structured identification of a project's type/framework.
 *
 * Separates presentation (icon, label) from machine-readable identity (id),
 * so that consumers can format output independently or branch on identity
 * without parsing emoji-embedded strings.
 */
final readonly class ProjectFingerprint
{
    public function __construct(
        /** Stable machine-readable identifier (e.g. "laravel", "nextjs", "python"). */
        public string $id,

        /** Decorative icon — single emoji or empty string. */
        public string $icon,

        /** Human-readable label without icon (e.g. "Laravel PHP project"). */
        public string $label,
    ) {
    }

    /**
     * Formatted "icon + label" string suitable for prompt injection.
     */
    public function display(): string
    {
        return trim("{$this->icon} {$this->label}");
    }

    /**
     * Returns true when no project type could be determined.
     */
    public function isUnknown(): bool
    {
        return $this->id === 'unknown';
    }

    public static function unknown(): self
    {
        return new self('unknown', '', '');
    }
}

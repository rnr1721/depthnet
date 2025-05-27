<?php

namespace App\Contracts\Chat;

interface ChatExporterInterface
{
    /**
     * Get exporter name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get display name for UI
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * Get file extension
     *
     * @return string
     */
    public function getExtension(): string;

    /**
     * Get MIME type
     *
     * @return string
     */
    public function getMimeType(): string;

    /**
     * Export chat messages to specific format
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @param array $options
     * @return string
     */
    public function export($messages, array $options = []): string;
}

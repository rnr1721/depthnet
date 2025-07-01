<?php

namespace App\Contracts\Chat;

use Illuminate\Http\Response;

interface ChatExporterServiceInterface
{
    /**
     * Export chat with specified format
     *
     * @param string $format
     * @param int $presetId
     * @param array $options
     * @return Response
     */
    public function export(string $format, int $presetId, array $options = []): Response;

    /**
     * Get available export formats
     *
     * @return array
     */
    public function getAvailableFormats(): array;
}

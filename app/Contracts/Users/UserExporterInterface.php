<?php

namespace App\Contracts\Users;

use Symfony\Component\HttpFoundation\StreamedResponse;

interface UserExporterInterface
{
    /**
     * Export users to a file (CSV, Excel, etc.). for download.
     *
     *
     * @return StreamedResponse
     */
    public function export(): StreamedResponse;
}

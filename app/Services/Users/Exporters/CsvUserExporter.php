<?php

namespace App\Services\Users\Exporters;

use App\Contracts\Users\UserExporterInterface;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvUserExporter implements UserExporterInterface
{
    public function __construct(
        protected User $userModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function export(): StreamedResponse
    {
        $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for correct display of Cyrillic in Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // headers
            fputcsv($handle, [
                'ID',
                'Name',
                'Email',
                'Role',
                'Created At',
                'Updated At',
            ]);

            $this->userModel->chunk(100, function ($users) use ($handle) {
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->is_admin ? 'Admin' : 'User',
                        $user->created_at->format('d.m.Y H:i:s'),
                        $user->updated_at->format('d.m.Y H:i:s'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

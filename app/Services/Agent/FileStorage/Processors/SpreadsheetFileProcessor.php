<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Models\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Processor for spreadsheet files (Excel, ODS, CSV).
 *
 * Each sheet becomes a separate section. Rows are converted to
 * pipe-delimited text lines — compact and readable for LLMs.
 * Empty rows are skipped. Sheets are chunked by row groups
 * so large spreadsheets don't produce oversized chunks.
 *
 * Requires: composer require phpoffice/phpspreadsheet
 *
 * Output format per sheet:
 *   [Sheet: Sales Q1]
 *   Date | Product | Revenue | Units
 *   2024-01-01 | Widget A | 1500.00 | 30
 *   2024-01-02 | Widget B | 890.50 | 18
 *   ...
 */
class SpreadsheetFileProcessor extends AbstractFileProcessor
{
    /**
     * Max rows to extract per sheet.
     * Very large sheets are truncated with a note.
     */
    protected int $maxRowsPerSheet = 1000;

    /** @inheritDoc */
    public function supportedMimeTypes(): array
    {
        return [
            // Excel
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            // ODS
            'application/vnd.oasis.opendocument.spreadsheet',
            // CSV
            'text/csv',
            'text/plain', // some systems report CSV as text/plain
            'application/csv',
        ];
    }

    /** @inheritDoc */
    protected function extractText(File $file, string $absolutePath): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException(
                'phpoffice/phpspreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet'
            );
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $sheetNames  = $spreadsheet->getSheetNames();
        $sheetCount  = count($sheetNames);

        $sections    = [];
        $totalRows   = 0;
        $truncated   = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $name     = $sheet->getTitle();
            $rows     = $sheet->toArray(
                nullValue:          '',
                calculateFormulas:  true,
                formatData:         true,
                returnCellRef:      false,
            );

            // Skip completely empty sheets
            $nonEmpty = array_filter($rows, fn ($row) => array_filter($row, fn ($cell) => trim((string)$cell) !== ''));
            if (empty($nonEmpty)) {
                continue;
            }

            $rowCount = count($nonEmpty);
            $isTruncated = false;

            if ($rowCount > $this->maxRowsPerSheet) {
                $nonEmpty    = array_slice($nonEmpty, 0, $this->maxRowsPerSheet, true);
                $isTruncated = true;
                $truncated[] = $name;
            }

            $lines = ["[Sheet: {$name}]"];

            foreach ($nonEmpty as $row) {
                // Convert all cells to strings, trim, join with pipe
                $cells  = array_map(fn ($cell) => trim((string) $cell), $row);
                // Remove trailing empty cells
                while (!empty($cells) && end($cells) === '') {
                    array_pop($cells);
                }
                if (!empty($cells)) {
                    $lines[] = implode(' | ', $cells);
                }
            }

            if ($isTruncated) {
                $lines[] = "[Truncated: showing {$this->maxRowsPerSheet} of {$rowCount} rows]";
            }

            $sections[] = implode("\n", $lines);
            $totalRows  += $rowCount;
        }

        if (empty($sections)) {
            return [
                'text' => '',
                'meta' => ['sheet_count' => $sheetCount, 'empty' => true],
            ];
        }

        $fullText = implode("\n\n", $sections);

        $meta = [
            'sheet_count' => $sheetCount,
            'sheet_names' => $sheetNames,
            'total_rows'  => $totalRows,
        ];

        if (!empty($truncated)) {
            $meta['truncated_sheets'] = $truncated;
            $meta['note'] = 'Some sheets were truncated. Increase maxRowsPerSheet for full extraction.';
        }

        return ['text' => $fullText, 'meta' => $meta];
    }
}

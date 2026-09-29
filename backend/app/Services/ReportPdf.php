<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Writes a ReportExport document as an A4 landscape PDF.
 */
class ReportPdf
{
    /** dompdf slows down sharply on very long tables; Excel has no limit. */
    public const MAX_ROWS = 2000;

    /**
     * @param  array<string, mixed>  $document  from ReportExport::build()
     */
    public function render(array $document): string
    {
        $sheets = collect($document['sheets'])
            ->reject(fn (array $sheet) => ($sheet['only'] ?? 'pdf') !== 'pdf')
            ->map(function (array $sheet) {
                $rows = Collection::make(($sheet['grouped'] ?? false) ? $sheet['groups'] : $sheet['rows']);

                return [
                    ...$sheet,
                    'grouped' => $sheet['grouped'] ?? false,
                    'rows' => $rows->take(self::MAX_ROWS),
                    'total_rows' => $rows->count(),
                ];
            })
            ->values()
            ->all();

        return Pdf::loadView('reports.pdf', [...$document, 'sheets' => $sheets, 'maxRows' => self::MAX_ROWS])
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}

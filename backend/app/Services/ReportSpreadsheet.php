<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes a ReportExport document as an .xlsx workbook: a Summary sheet,
 * then one sheet per data set with real numbers and dates, a frozen
 * header row, filters and a totals row.
 */
class ReportSpreadsheet
{
    private const MONEY_FORMAT = '"₹"#,##0.00';

    private const DATE_FORMAT = 'dd-mmm-yyyy hh:mm AM/PM';

    private const HEADER_FILL = 'FFE0E7FF';

    private const GROUP_FILL = 'FFC7D2FE';

    private const BORDER_COLOR = 'FF94A3B8';

    /**
     * @param  array<string, mixed>  $document  from ReportExport::build()
     */
    public function render(array $document): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($document['title'])->setCreator(config('app.name'));

        $this->summarySheet($book->getActiveSheet(), $document);

        foreach ($document['sheets'] as $sheet) {
            if (($sheet['only'] ?? 'xlsx') === 'xlsx') {
                $this->dataSheet($book->createSheet(), $sheet);
            }
        }

        $book->setActiveSheetIndex(count($document['sheets']) > 0 ? 1 : 0);

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function summarySheet(Worksheet $sheet, array $document): void
    {
        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', config('app.name').' · '.$document['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $rows = [
            ['Period', $document['period']->from->format('d M Y').' – '.$document['period']->to->format('d M Y')],
            ...$document['filters'],
            ['Generated', now()->format('d M Y, h:i A')],
            [],
            ...$document['summary'],
        ];

        foreach ($rows as $index => $row) {
            $line = $index + 3;
            if ($row === []) {
                continue;
            }
            $sheet->setCellValueExplicit("A{$line}", $row[0], 's');
            $sheet->setCellValue("B{$line}", $row[1]);
            $sheet->getStyle("A{$line}")->getFont()->setBold(true);
            $sheet->getStyle("B{$line}")->getAlignment()->setHorizontal('left');
        }

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(40);
    }

    /**
     * @param  array{name: string, columns: list<array{0: string, 1: string}>, rows: iterable<list<mixed>>}  $data
     */
    private function dataSheet(Worksheet $sheet, array $data): void
    {
        $sheet->setTitle(mb_substr($data['name'], 0, 31));
        $columns = $data['columns'];
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));

        foreach ($columns as $index => [$label]) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $label);
        }
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);

        $grouped = $data['grouped'] ?? false;
        $line = 1;

        if ($grouped) {
            // Bill rows are shaded; their products sit below, indented and
            // collapsible with Excel's outline (+/-) buttons.
            $sheet->setShowSummaryBelow(false);
            foreach ($data['groups'] as $group) {
                $this->writeRow($sheet, $columns, ++$line, $group['row']);
                $style = $sheet->getStyle("A{$line}:{$lastColumn}{$line}");
                $style->getFont()->setBold(true);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GROUP_FILL);

                foreach ($group['items'] as $item) {
                    $this->writeRow($sheet, $columns, ++$line, $item);
                    $sheet->getStyle("A{$line}")->getAlignment()->setIndent(2);
                    $sheet->getRowDimension($line)->setOutlineLevel(1);
                }
            }
        } else {
            foreach ($data['rows'] as $row) {
                $this->writeRow($sheet, $columns, ++$line, $row);
            }
        }

        foreach ($columns as $index => [$label, $type]) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $range = "{$letter}2:{$letter}".max(2, $line + 1);
            match ($type) {
                'money' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT),
                'datetime' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::DATE_FORMAT),
                default => null,
            };
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }

        // Outline every cell, header and totals included.
        $sheet->getStyle("A1:{$lastColumn}".($line > 1 && ! $grouped ? $line + 1 : $line))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER_COLOR);

        // A grouped sheet mixes bill and product rows, so column sums would double count.
        if ($line > 1 && ! $grouped) {
            $this->totalsRow($sheet, $columns, $line);
            $sheet->setAutoFilter("A1:{$lastColumn}{$line}");
        }

        $sheet->freezePane('A2');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns
     * @param  list<mixed>  $row
     */
    private function writeRow(Worksheet $sheet, array $columns, int $line, array $row): void
    {
        foreach (array_values($row) as $index => $value) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).$line;
            match (true) {
                $value === null => null,
                $value instanceof Carbon => $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value->copy()->setTimezone(config('app.timezone')))),
                is_string($value) || $columns[$index][1] === 'text' => $sheet->setCellValueExplicit($cell, (string) $value, 's'),
                default => $sheet->setCellValue($cell, $value),
            };
        }
    }

    /**
     * SUM formulas under every money and count column except running
     * balances such as "Stock after".
     *
     * @param  list<array{0: string, 1: string}>  $columns
     */
    private function totalsRow(Worksheet $sheet, array $columns, int $lastLine): void
    {
        $totals = $lastLine + 1;
        $sheet->setCellValue("A{$totals}", 'Total');

        foreach ($columns as $index => [$label, $type]) {
            if ($index === 0 || ! in_array($type, ['money', 'int'], true) || in_array($label, ['Stock after', 'Average bill', 'Unit price'], true)) {
                continue;
            }
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$letter}{$totals}", "=SUM({$letter}2:{$letter}{$lastLine})");
        }

        $sheet->getStyle("A{$totals}:".Coordinate::stringFromColumnIndex(count($columns)).$totals)->getFont()->setBold(true);
    }
}

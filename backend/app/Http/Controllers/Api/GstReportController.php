<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountsPdf;
use App\Services\GstReportService;
use App\Services\ReportSpreadsheet;
use App\Support\Branding;
use App\Support\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Monthly GSTR-1 (JSON or ?format=xlsx) and GSTR-3B (JSON or ?format=pdf)
 * for the selected store, or every store together.
 */
class GstReportController extends Controller
{
    public function __construct(
        private readonly GstReportService $gst,
        private readonly StoreContext $context,
    ) {}

    public function gstr1(Request $request, ReportSpreadsheet $spreadsheet): Response
    {
        $month = $this->month($request, ['json', 'xlsx']);
        $report = $this->gst->gstr1($this->context->scopeId(), $month);

        if ($request->query('format') === 'xlsx') {
            return response($spreadsheet->render($this->gst->gstr1Document($report, $this->storeName())), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"gstr1-{$month}.xlsx\"",
            ]);
        }

        return response()->json(['data' => $report, 'store' => $this->storeName(), 'gstin' => $this->gstin()]);
    }

    public function gstr3b(Request $request, AccountsPdf $pdf): Response
    {
        $month = $this->month($request, ['json', 'pdf']);
        $report = $this->gst->gstr3b($this->context->scopeId(), $month);

        if ($request->query('format') === 'pdf') {
            return response($pdf->gstr3b($report, $this->storeName()), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"gstr3b-{$month}.pdf\"",
            ]);
        }

        return response()->json(['data' => $report, 'store' => $this->storeName(), 'gstin' => $this->gstin()]);
    }

    /**
     * @param  list<string>  $formats
     */
    private function month(Request $request, array $formats): string
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'format' => ['nullable', Rule::in($formats)],
        ]);

        return $filters['month'] ?? now()->format('Y-m');
    }

    private function storeName(): string
    {
        return $this->context->isAll() ? 'All stores' : $this->context->store()->name;
    }

    private function gstin(): ?string
    {
        return $this->context->isAll() ? null : Branding::seller($this->context->store())['gstin'];
    }
}

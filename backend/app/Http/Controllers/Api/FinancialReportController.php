<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountsPdf;
use App\Services\FinancialReportService;
use App\Services\LedgerService;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trial balance, Profit & Loss and Balance Sheet as JSON, or PDF with
 * ?format=pdf.
 */
class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly AccountsPdf $pdf,
        private readonly StoreContext $context,
    ) {}

    public function trialBalance(Request $request): Response
    {
        $asOf = $this->asOf($request);
        $report = $this->reports->trialBalance($this->context->scopeId(), $asOf);

        return $this->respond($request, $report, fn () => $this->pdf->trialBalance($report, $this->storeName()), "trial-balance-{$asOf->toDateString()}.pdf");
    }

    public function profitLoss(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'pdf'])],
        ]);
        $from = Carbon::parse($filters['from'] ?? LedgerService::financialYearStart());
        $to = Carbon::parse($filters['to'] ?? now()->toDateString());

        $report = $this->reports->profitAndLoss($this->context->scopeId(), $from, $to);

        return $this->respond($request, $report, fn () => $this->pdf->profitAndLoss($report, $this->storeName()), "profit-loss-{$from->toDateString()}-{$to->toDateString()}.pdf");
    }

    public function balanceSheet(Request $request): Response
    {
        $asOf = $this->asOf($request);
        $report = $this->reports->balanceSheet($this->context->scopeId(), $asOf);

        return $this->respond($request, $report, fn () => $this->pdf->balanceSheet($report, $this->storeName()), "balance-sheet-{$asOf->toDateString()}.pdf");
    }

    private function asOf(Request $request): Carbon
    {
        $filters = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'format' => ['nullable', Rule::in(['json', 'pdf'])],
        ]);

        return Carbon::parse($filters['as_of'] ?? now()->toDateString());
    }

    private function storeName(): string
    {
        return $this->context->isAll() ? 'All stores' : $this->context->store()->name;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function respond(Request $request, array $report, callable $pdf, string $filename): Response
    {
        if ($request->query('format') === 'pdf') {
            return response($pdf(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        return response()->json(['data' => $report, 'store' => $this->storeName()]);
    }
}

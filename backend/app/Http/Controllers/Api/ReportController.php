<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReportExport;
use App\Services\ReportPdf;
use App\Services\ReportService;
use App\Services\ReportSpreadsheet;
use App\Support\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports for the chosen period. Every report answers with the resolved
 * period (so the screen can show the exact dates), a summary of the whole
 * period and, where the list can be long, one page of rows.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Employees for the report filters, including deactivated ones.
     */
    public function employeeOptions(): JsonResponse
    {
        return response()->json([
            'data' => User::orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    /**
     * Bills in the period, optionally for one employee, with their lines.
     */
    public function orders(ReportRequest $request): JsonResponse
    {
        $period = $request->period();
        $query = $this->reports->orders($period, $request->filters());

        $page = $query->clone()
            ->with(['customer', 'cashier', 'items.product'])
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (Order $order) => OrderResource::make($order)->resolve($request));

        return $this->respond($page, $period, $this->reports->orderSummary($query));
    }

    /**
     * Customers who bought in the period, biggest spenders first.
     */
    public function customers(ReportRequest $request): JsonResponse
    {
        $period = $request->period();
        $query = $this->reports->customers($period, $request->filters());

        $page = $query->clone()
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->reports->customerRow($customer, $period));

        return $this->respond($page, $period, $this->reports->customerSummary($query, $period));
    }

    /**
     * Every stock change in the period and who made it.
     */
    public function stock(ReportRequest $request): JsonResponse
    {
        $period = $request->period();
        $query = $this->reports->stock($period, $request->filters());

        $page = $query->clone()
            ->with(['product', 'user', 'order'])
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (StockMovement $movement) => [
                ...StockMovementResource::make($movement)->resolve($request),
                'product' => $movement->product ? [
                    'id' => $movement->product->id,
                    'name' => $movement->product->name,
                    'code' => $movement->product->code,
                ] : null,
            ]);

        return $this->respond($page, $period, $this->reports->stockSummary($query));
    }

    /**
     * What each employee did in the period: bills, sales and stock changes.
     */
    public function employees(ReportRequest $request): JsonResponse
    {
        $period = $request->period();
        $employees = $this->reports->employees($period, $request->filters());

        return response()->json([
            'data' => $employees,
            'period' => $period->toArray(),
            'summary' => $this->reports->employeeSummary($employees),
        ]);
    }

    /**
     * Download a whole report (every row, same filters) as PDF or Excel.
     */
    public function export(ReportRequest $request, string $report, ReportExport $export): Response
    {
        return $this->download($request, $report, $export);
    }

    /**
     * A link to the same download that works without signing in, for the
     * QR code a phone scans. It is signed (can't be altered) and expires.
     */
    public function shareLink(ReportRequest $request, string $report): JsonResponse
    {
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]])['format'];
        $expires = now()->addMinutes((int) config('inventory.report_link_minutes'));

        $params = array_filter([
            'report' => $report,
            'format' => $format,
            'by' => $request->user()->id,
            ...$request->safe()->only(['period', 'from', 'to', 'employee_id', 'search', 'type']),
        ], fn ($value) => $value !== null && $value !== '');

        // Phones can't reach "localhost"; REPORT_LINK_URL names an address they can.
        URL::forceRootUrl(config('inventory.report_link_url'));
        try {
            $url = URL::temporarySignedRoute('reports.shared', $expires, $params);
        } finally {
            URL::forceRootUrl(null);
        }

        return response()->json(['url' => $url, 'expires_at' => $expires->toIso8601String()]);
    }

    /**
     * The download behind a QR code. Only valid while the link's signature
     * holds and the employee who made it may still see reports.
     */
    public function shared(ReportRequest $request, string $report, ReportExport $export): Response
    {
        $user = User::find($request->integer('by'));
        abort_unless($user?->is_active && $user->hasPermission('reports.view'), 403, 'This link is no longer valid.');

        return $this->download($request, $report, $export);
    }

    private function download(ReportRequest $request, string $report, ReportExport $export): Response
    {
        abort_unless(in_array($report, ReportExport::REPORTS, true), 404);
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]])['format'];

        $period = $request->period();
        $document = $export->build($report, $period, $request->filters());
        $filename = "{$document['slug']}-{$period->from->toDateString()}-to-{$period->to->toDateString()}.{$format}";

        [$contents, $type] = $format === 'pdf'
            ? [app(ReportPdf::class)->render($document), 'application/pdf']
            : [app(ReportSpreadsheet::class)->render($document), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

        return response($contents, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function respond(LengthAwarePaginator $page, ReportPeriod $period, array $summary): JsonResponse
    {
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'period' => $period->toArray(),
            'summary' => $summary,
        ]);
    }
}

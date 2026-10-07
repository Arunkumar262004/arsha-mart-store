<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\TaxInvoiceService;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Tax invoices. Every bill is an invoice (its invoice_number is the gap-free
 * GST series), so this lists bills in invoice terms and renders the A4 GST
 * invoice for one.
 */
class TaxInvoiceController extends Controller
{
    public function __construct(private readonly TaxInvoiceService $invoices) {}

    /**
     * Invoices in the current store scope, newest first, with totals for the
     * whole filtered set (not just the page).
     */
    public function index(Request $request, StoreContext $context): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'payment_mode' => ['nullable', Rule::in(Order::PAYMENT_MODES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filtered($filters, $context->scopeId());

        $summary = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(subtotal), 0) as taxable, COALESCE(SUM(cgst_amount), 0) as cgst, COALESCE(SUM(sgst_amount), 0) as sgst, COALESCE(SUM(igst_amount), 0) as igst, COALESCE(SUM(tax_total), 0) as tax, COALESCE(SUM(grand_total), 0) as total')
            ->first();

        $page = $query->with(['customer', 'store'])
            ->latest()
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $money = fn ($value) => number_format((float) $value, 2, '.', '');

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $order) => [
                'id' => $order->id,
                'invoice_number' => $order->invoice_number,
                'order_number' => $order->order_number,
                'date' => $order->created_at?->toIso8601String(),
                'kind' => $order->customer_gstin ? 'b2b' : 'b2c',
                'customer_name' => $order->customer?->name,
                'customer_gstin' => $order->customer_gstin,
                'store' => $order->store?->only(['id', 'name', 'code']),
                'payment_mode' => $order->payment_mode,
                'is_interstate' => $order->is_interstate,
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'grand_total' => $order->grand_total,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'invoices' => (int) $summary->invoices,
                'taxable' => $money($summary->taxable),
                'cgst' => $money($summary->cgst),
                'sgst' => $money($summary->sgst),
                'igst' => $money($summary->igst),
                'tax' => $money($summary->tax),
                'total' => $money($summary->total),
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeStore($request, $order);

        return response()->json(['data' => $this->invoices->build($order)]);
    }

    public function pdf(Request $request, Order $order): Response
    {
        $this->authorizeStore($request, $order);

        return response($this->invoices->pdf($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->invoices->filename($order).'"',
        ]);
    }

    /**
     * Order history viewers, or cashiers (to print the invoice of a bill
     * they just made); and only bills of a store the user works in.
     */
    private function authorizeStore(Request $request, Order $order): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('orders.view') || $user->hasPermission('billing.create'), 403);
        abort_unless($order->store_id === null || $user->canAccessStore($order->store_id), 404);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function filtered(array $filters, ?int $storeId): Builder
    {
        $search = isset($filters['search']) ? mb_strtolower(trim($filters['search'])) : '';

        return Order::query()
            ->when($storeId, fn (Builder $q, int $id) => $q->where('store_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when(($filters['kind'] ?? null) === 'b2b', fn (Builder $q) => $q->whereNotNull('customer_gstin'))
            ->when(($filters['kind'] ?? null) === 'b2c', fn (Builder $q) => $q->whereNull('customer_gstin'))
            ->when($filters['payment_mode'] ?? null, fn (Builder $q, string $mode) => $q->where('payment_mode', $mode))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(invoice_number) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(customer_gstin) LIKE ?', ["%{$search}%"])
                ->orWhereHas('customer', fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));
    }
}

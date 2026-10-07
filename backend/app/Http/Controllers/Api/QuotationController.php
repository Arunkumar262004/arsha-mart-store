<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillDocumentRequest;
use App\Http\Requests\SaveQuotationRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    /**
     * Quotations in the current store scope, newest first. The status filter
     * understands the derived "expired" status.
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...Quotation::MANUAL_STATUSES, Quotation::STATUS_CONVERTED, Quotation::STATUS_EXPIRED])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $today = today()->toDateString();
        $open = [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED];

        $quotations = Quotation::query()
            ->when($context->scopeId(), fn (Builder $q, int $id) => $q->where('store_id', $id))
            ->when($filters['status'] ?? null, function (Builder $q, string $status) use ($today, $open) {
                if ($status === Quotation::STATUS_EXPIRED) {
                    $q->whereIn('status', $open)->whereNotNull('valid_until')->where('valid_until', '<', $today);
                } elseif (in_array($status, $open, true)) {
                    $q->where('status', $status)->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(customer_gstin) LIKE ?', ["%{$search}%"])))
            ->with(['store', 'convertedOrder'])
            ->withCount('items')
            ->latest('date')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return QuotationResource::collection($quotations);
    }

    public function show(Request $request, Quotation $quotation): QuotationResource
    {
        $this->authorizeStore($request, $quotation);

        return QuotationResource::make($quotation->load(['items.product', 'store', 'convertedOrder']));
    }

    public function store(SaveQuotationRequest $request, StoreContext $context): JsonResponse
    {
        $quotation = $this->quotations->create($request->validated(), $context->store(), $request->user());

        return QuotationResource::make($quotation)->response()->setStatusCode(201);
    }

    public function update(SaveQuotationRequest $request, Quotation $quotation): QuotationResource
    {
        $this->authorizeStore($request, $quotation);

        return QuotationResource::make($this->quotations->update($quotation, $request->validated()));
    }

    /**
     * Mark as sent, accepted, cancelled or back to draft.
     */
    public function setStatus(Request $request, Quotation $quotation): QuotationResource
    {
        $this->authorizeStore($request, $quotation);
        $status = $request->validate(['status' => ['required', Rule::in(Quotation::MANUAL_STATUSES)]])['status'];

        $this->quotations->ensureOpen($quotation);
        $quotation->update(['status' => $status]);

        return QuotationResource::make($quotation->load(['items.product', 'store']));
    }

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeStore($request, $quotation);

        if ($quotation->status !== Quotation::STATUS_DRAFT) {
            throw ValidationException::withMessages(['quotation' => 'Only draft quotations can be deleted; cancel it instead.']);
        }

        $quotation->delete();

        return response()->json(null, 204);
    }

    /**
     * Make a bill from the quotation at the quoted prices.
     */
    public function convert(BillDocumentRequest $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeStore($request, $quotation);

        $order = $this->quotations->convert($quotation, $request->validated(), $request->user());

        return response()->json([
            'data' => OrderResource::make($order),
            'quotation' => QuotationResource::make($quotation->fresh(['items.product', 'store', 'convertedOrder'])),
        ], 201);
    }

    private function authorizeStore(Request $request, Quotation $quotation): void
    {
        abort_unless($request->user()->canAccessStore($quotation->store_id), 404);
    }
}

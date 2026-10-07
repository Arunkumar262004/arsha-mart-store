<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillDocumentRequest;
use App\Http\Requests\SaveChallanRequest;
use App\Http\Resources\DeliveryChallanResource;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryChallan;
use App\Services\DeliveryChallanService;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeliveryChallanController extends Controller
{
    public function __construct(private readonly DeliveryChallanService $challans) {}

    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(DeliveryChallan::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = mb_strtolower(trim($filters['search'] ?? ''));

        $challans = DeliveryChallan::query()
            ->when($context->scopeId(), fn (Builder $q, int $id) => $q->where('store_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(customer_email) LIKE ?', ["%{$search}%"])))
            ->with(['store', 'order', 'items'])
            ->latest('date')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return DeliveryChallanResource::collection($challans);
    }

    public function show(Request $request, DeliveryChallan $challan): DeliveryChallanResource
    {
        $this->authorizeStore($request, $challan);

        return DeliveryChallanResource::make($challan->load(['items.product', 'store', 'order']));
    }

    /**
     * Issue a challan at the current store; its goods leave stock now.
     */
    public function store(SaveChallanRequest $request, StoreContext $context): JsonResponse
    {
        $challan = $this->challans->issue($request->validated(), $context->store(), $request->user());

        return DeliveryChallanResource::make($challan)->response()->setStatusCode(201);
    }

    /**
     * Bill one or more issued challans (same store, same customer) as one order.
     */
    public function invoice(BillDocumentRequest $request): JsonResponse
    {
        $order = $this->challans->invoice($request->validated('challan_ids'), $request->validated(), $request->user());

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function markReturned(Request $request, DeliveryChallan $challan): DeliveryChallanResource
    {
        $this->authorizeStore($request, $challan);

        return DeliveryChallanResource::make($this->challans->close($challan, DeliveryChallan::STATUS_RETURNED, $request->user()));
    }

    public function cancel(Request $request, DeliveryChallan $challan): DeliveryChallanResource
    {
        $this->authorizeStore($request, $challan);

        return DeliveryChallanResource::make($this->challans->close($challan, DeliveryChallan::STATUS_CANCELLED, $request->user()));
    }

    private function authorizeStore(Request $request, DeliveryChallan $challan): void
    {
        abort_unless($request->user()->canAccessStore($challan->store_id), 404);
    }
}

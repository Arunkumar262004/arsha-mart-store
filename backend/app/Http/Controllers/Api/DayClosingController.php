<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Services\DayClosingService;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * End-of-day summary and the counted-cash closing of the drawer.
 */
class DayClosingController extends Controller
{
    public function __construct(
        private readonly DayClosingService $closing,
        private readonly StoreContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = Carbon::parse($filters['date'] ?? now()->toDateString());
        $storeId = $this->context->scopeId();

        $summary = $this->closing->summary($storeId, $date);
        unset($summary['expected_cents']);

        $closing = $storeId === null ? null : CashClosing::query()
            ->where('store_id', $storeId)
            ->whereDate('date', $date->toDateString())
            ->with('store')
            ->first();

        return response()->json([
            'data' => [
                ...$summary,
                'store' => $storeId === null ? null : ['id' => $storeId, 'name' => $this->context->store()->name],
                'closing' => $closing?->toReport(),
            ],
            'recent' => $this->closing->recent($storeId),
            'all_stores' => $storeId === null,
        ]);
    }

    /**
     * Save (or, on the same day, correct) the counted cash for a store-day.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('billing.create') || $request->user()->can('accounts.manage'),
            403,
        );
        abort_if($this->context->isAll(), 422, 'Select a store in the header to close the day.');

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $date = Carbon::parse($data['date'] ?? now()->toDateString());
        $store = $this->context->store();
        $expected = $this->closing->summary($store->id, $date)['expected_cents'];
        $counted = Money::toCents($data['counted_cash']);

        $closing = CashClosing::query()->where('store_id', $store->id)->whereDate('date', $date->toDateString())->first();
        abort_if(
            $closing !== null && ! $closing->created_at->isSameDay(now()),
            422,
            'This day was closed earlier and can no longer be changed.',
        );

        $closing ??= new CashClosing(['store_id' => $store->id, 'date' => $date->toDateString()]);
        $closing->fill([
            'expected_cash' => Money::format($expected),
            'counted_cash' => Money::format($counted),
            'difference' => Money::format($counted - $expected),
            'notes' => $data['notes'] ?? null,
            'closed_by' => $request->user()->id,
            'closed_by_name' => $request->user()->name,
        ]);
        $wasNew = ! $closing->exists;
        $closing->save();

        return response()->json(['data' => $closing->load('store')->toReport()], $wasNew ? 201 : 200);
    }
}

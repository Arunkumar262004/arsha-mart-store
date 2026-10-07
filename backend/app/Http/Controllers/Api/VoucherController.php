<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoucherRequest;
use App\Models\Account;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The day book (every voucher any module posted) and manual journal /
 * contra entries.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly StoreContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(Voucher::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($filters['to'] ?? now());

        $query = LedgerService::whereDateBetween(Voucher::query(), 'date', $from, $to)
            ->when($this->context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.mb_strtolower(trim($search)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(narration) LIKE ?', [$like])
                    ->orWhereHas('entries.account', fn ($a) => $a
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$like])));
            });

        $byType = $query->clone()
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->type => ['count' => (int) $r->count, 'amount' => Money::format(Money::toCents($r->amount))]]);

        $page = $query
            ->with(['entries.account', 'store'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->through(fn (Voucher $v) => $this->row($v));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'count' => $byType->sum('count'),
                'amount' => Money::format($byType->sum(fn ($t) => Money::toCents($t['amount']))),
                'by_type' => $byType,
            ],
        ]);
    }

    public function show(Request $request, Voucher $voucher): JsonResponse
    {
        abort_unless($request->user()->canAccessStore($voucher->store_id), 404);

        $voucher->load(['entries.account', 'store']);

        return response()->json(['data' => [
            ...$this->row($voucher),
            'source_type' => $voucher->source_type ? Str::snake(class_basename($voucher->source_type)) : null,
            'source_id' => $voucher->source_id,
            'source_label' => $this->sourceLabel($voucher),
        ]]);
    }

    public function store(StoreVoucherRequest $request, AccountingService $accounting): JsonResponse
    {
        abort_if($this->context->isAll(), 422, 'Select a store in the header to record a voucher.');

        $accounts = Account::query()->whereKey(array_column($request->validated('lines'), 'account_id'))->get()->keyBy('id');
        $lines = array_map(fn (array $line) => [
            $accounts[$line['account_id']],
            $line['debit'] ?? 0,
            $line['credit'] ?? 0,
        ], $request->validated('lines'));

        $voucher = $accounting->post(
            $request->validated('type'),
            $lines,
            $this->context->store(),
            Carbon::parse($request->validated('date')),
            $request->validated('narration'),
            null,
            $request->user(),
        );

        return response()->json(['data' => $this->row($voucher->load(['entries.account', 'store']))], 201);
    }

    /**
     * Only manual journal / contra vouchers can be deleted; the rest belong
     * to their document (bill, purchase, ...) and are reversed there.
     */
    public function destroy(Request $request, Voucher $voucher): JsonResponse
    {
        abort_unless($request->user()->canAccessStore($voucher->store_id), 404);
        abort_unless(
            in_array($voucher->type, [Voucher::JOURNAL, Voucher::CONTRA], true) && $voucher->source_type === null,
            422,
            'Only manual journal and contra vouchers can be deleted.',
        );

        DB::transaction(function () use ($voucher) {
            $voucher->entries()->delete();
            $voucher->delete();
        });

        return response()->json(['message' => 'Voucher deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'type' => $voucher->type,
            'number' => $voucher->number,
            'date' => $voucher->date->toDateString(),
            'narration' => $voucher->narration,
            'amount' => $voucher->amount,
            'store' => $voucher->store ? ['id' => $voucher->store->id, 'name' => $voucher->store->name, 'code' => $voucher->store->code] : null,
            'created_by' => $voucher->created_by_name,
            'is_manual' => $voucher->source_type === null,
            'can_delete' => $voucher->source_type === null && in_array($voucher->type, [Voucher::JOURNAL, Voucher::CONTRA], true),
            'entries' => $voucher->entries->sortBy(fn ($e) => [Money::toCents($e->debit) > 0 ? 0 : 1, $e->id])->map(fn ($e) => [
                'account_id' => $e->account_id,
                'code' => $e->account?->code,
                'name' => $e->account?->name,
                'debit' => $e->debit,
                'credit' => $e->credit,
            ])->values()->all(),
            'created_at' => $voucher->created_at?->toIso8601String(),
        ];
    }

    /**
     * "Bill MAIN/INV/26-27/00003", "Purchase MAIN/PUR/26-27/00001", ...
     */
    private function sourceLabel(Voucher $voucher): ?string
    {
        if ($voucher->source_type === null) {
            return null;
        }

        $kind = Str::headline(class_basename($voucher->source_type));

        try {
            $source = class_exists($voucher->source_type) ? $voucher->source : null;
        } catch (Throwable) {
            $source = null;
        }

        if ($source === null) {
            return "{$kind} #{$voucher->source_id}";
        }
        if ($source instanceof Order) {
            return 'Bill '.($source->invoice_number ?? $source->order_number);
        }

        $number = $source->number ?? $source->invoice_number ?? $source->document_number ?? null;

        return $kind.' '.($number ?? '#'.$source->getKey());
    }
}

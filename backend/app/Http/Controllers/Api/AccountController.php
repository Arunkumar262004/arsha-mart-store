<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAccountRequest;
use App\Models\Account;
use App\Models\VoucherEntry;
use App\Services\FinancialReportService;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts: every ledger grouped by type and group, with its
 * balance in the selected store (or all stores, opening balances included).
 */
class AccountController extends Controller
{
    /** First code of the auto-numbered range for new ledgers of each type. */
    private const CODE_RANGES = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'income' => 4000, 'expense' => 6000];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly StoreContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(Account::TYPES)],
            'group' => ['nullable', Rule::in(array_keys(Account::GROUPS))],
            'search' => ['nullable', 'string', 'max:100'],
            'include_parties' => ['nullable', 'boolean'],
        ]);

        $accounts = Account::query()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->where('group', $group))
            ->when(! $request->boolean('include_parties'), fn ($q) => $q->whereNull('party_type'))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $this->search($q, $s))
            ->orderBy('code')
            ->get();

        $balances = $this->ledger->balances($this->context->scopeId());
        $used = VoucherEntry::query()->whereIn('account_id', $accounts->pluck('id'))->distinct()->pluck('account_id')->flip();
        $parties = $this->partyNames($accounts);

        $tree = [];
        foreach (Account::TYPES as $type) {
            $groups = [];
            foreach (Account::GROUPS as $group => $groupType) {
                if ($groupType !== $type) {
                    continue;
                }
                $rows = $accounts->where('group', $group)->map(fn (Account $a) => $this->row($a, $balances[$a->id] ?? 0, $used->has($a->id), $parties[$a->id] ?? null));
                if ($rows->isEmpty()) {
                    continue;
                }
                $groups[] = [
                    'group' => $group,
                    'label' => FinancialReportService::GROUP_LABELS[$group],
                    'balance' => Money::format($rows->sum('balance_cents')),
                    'accounts' => $rows->map(fn ($r) => array_diff_key($r, ['balance_cents' => 1]))->values()->all(),
                ];
            }
            if ($groups !== []) {
                $tree[] = [
                    'type' => $type,
                    'label' => FinancialReportService::TYPE_LABELS[$type],
                    'balance' => Money::format(array_sum(array_map(fn ($g) => Money::toCents($g['balance']), $groups))),
                    'groups' => $groups,
                ];
            }
        }

        return response()->json([
            'data' => $tree,
            'meta' => [
                'count' => $accounts->count(),
                'party_ledgers' => Account::query()->whereNotNull('party_type')->count(),
                'groups' => collect(Account::GROUPS)->map(fn ($type, $group) => ['group' => $group, 'type' => $type, 'label' => FinancialReportService::GROUP_LABELS[$group]])->values(),
                'all_stores' => $this->context->isAll(),
            ],
        ]);
    }

    /**
     * Short list for pickers (journal lines, ledger selection).
     */
    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'groups' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $groups = array_filter(explode(',', $filters['groups'] ?? ''));

        return response()->json([
            'data' => Account::query()
                ->when($request->boolean('active', true), fn ($q) => $q->where('is_active', true))
                ->when($groups, fn ($q) => $q->whereIn('group', $groups))
                ->when($filters['search'] ?? null, fn ($q, string $s) => $this->search($q, $s))
                ->orderByRaw('CASE WHEN party_type IS NULL THEN 0 ELSE 1 END')
                ->orderBy('code')
                ->limit(1000)
                ->get(['id', 'code', 'name', 'group', 'type', 'party_type', 'is_active'])
                ->map(fn (Account $a) => [
                    'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'group' => $a->group,
                    'type' => $a->type, 'is_party' => $a->party_type !== null,
                ]),
        ]);
    }

    public function store(SaveAccountRequest $request): JsonResponse
    {
        $group = $request->validated('group');
        $type = Account::GROUPS[$group];

        $account = new Account([
            'code' => $request->validated('code') ?? $this->nextCode($type, $group),
            'name' => $request->validated('name'),
            'type' => $type,
            'group' => $group,
            'opening_balance' => $request->signedOpening() ?? '0.00',
            'is_active' => $request->boolean('is_active', true),
        ]);
        $account->save();

        return response()->json(['data' => $this->row($account->refresh(), 0, false, null)], 201);
    }

    /**
     * Built-in and party ledgers: opening balance only (built-in ones can't
     * be deactivated; a party's name comes from the customer / supplier).
     */
    public function update(SaveAccountRequest $request, Account $account): JsonResponse
    {
        $changes = [];
        if (($opening = $request->signedOpening()) !== null) {
            $changes['opening_balance'] = $opening;
        }

        if (! $account->is_system && $account->party_type === null) {
            foreach (['code', 'name'] as $field) {
                if ($request->filled($field)) {
                    $changes[$field] = $request->validated($field);
                }
            }
            if ($request->has('group')) {
                $changes['group'] = $request->validated('group');
                $changes['type'] = Account::GROUPS[$changes['group']];
            }
            if ($request->has('is_active')) {
                $changes['is_active'] = $request->boolean('is_active');
            }
        }

        $account->update($changes);

        $balance = $this->ledger->balances($this->context->scopeId())[$account->id] ?? 0;
        $parties = $this->partyNames(collect([$account]));

        return response()->json(['data' => $this->row($account, $balance, $account->entries()->exists(), $parties[$account->id] ?? null)]);
    }

    public function destroy(Account $account): JsonResponse
    {
        abort_if($account->is_system, 422, 'Built-in accounts cannot be deleted.');
        abort_if($account->party_type !== null, 422, 'Customer and supplier ledgers are removed with their party.');
        abort_if($account->entries()->exists(), 422, 'This account has entries. Deactivate it instead.');

        $account->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Account $account, int $balance, bool $hasEntries, ?string $partyName): array
    {
        $opening = Money::toCents($account->opening_balance);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'group' => $account->group,
            'is_system' => $account->is_system,
            'is_active' => $account->is_active,
            'is_party' => $account->party_type !== null,
            'party_type' => $account->party_type ? mb_strtolower(class_basename($account->party_type)) : null,
            'party_name' => $partyName,
            'opening_balance' => Money::format(abs($opening)),
            'opening_side' => $opening < 0 ? 'cr' : 'dr',
            'balance' => Money::format(abs($balance)),
            'balance_side' => LedgerService::side($balance),
            'balance_cents' => $balance,
            'has_entries' => $hasEntries,
            'can_delete' => ! $account->is_system && $account->party_type === null && ! $hasEntries,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Account>  $query
     */
    private function search($query, string $search): void
    {
        $like = '%'.mb_strtolower(trim($search)).'%';
        $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, string>
     */
    private function partyNames(Collection $accounts): array
    {
        $names = [];
        foreach ($accounts->whereNotNull('party_type')->groupBy('party_type') as $class => $group) {
            if (! class_exists($class)) {
                continue;
            }
            $models = $class::query()->whereKey($group->pluck('party_id'))->get()->keyBy(fn ($m) => $m->getKey());
            foreach ($group as $account) {
                if ($name = $models[$account->party_id]->name ?? null) {
                    $names[$account->id] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Next free 4-digit code in the type's range (direct expenses and
     * purchases use 5xxx).
     */
    private function nextCode(string $type, string $group): string
    {
        $base = in_array($group, ['purchase', 'direct_expense'], true) ? 5000 : self::CODE_RANGES[$type];

        $max = Account::query()
            ->where('code', 'like', substr((string) $base, 0, 1).'%')
            ->pluck('code')
            ->filter(fn ($code) => ctype_digit($code) && strlen($code) === 4)
            ->map(fn ($code) => (int) $code)
            ->max() ?? $base;

        $next = max($max + 1, $base + 1);
        while (Account::query()->where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }
}

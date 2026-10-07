<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\GstStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery challans: goods leave the store (stock goes down) with no sale
 * in the books yet. Later the challan is either invoiced (becomes part of a
 * bill), or the goods come back (returned / cancelled, stock goes up again).
 */
class DeliveryChallanService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
        private readonly OrderService $orders,
    ) {}

    /**
     * Issue a challan at $store and take its goods out of stock.
     *
     * @param  array<string, mixed>  $data  validated SaveChallanRequest data
     *
     * @throws InsufficientStockException
     */
    public function issue(array $data, Store $store, ?User $user): DeliveryChallan
    {
        return DB::transaction(function () use ($data, $store, $user) {
            $date = Carbon::parse($data['date'] ?? today());
            $customer = $this->matchCustomer($data);
            $items = array_values($data['items']);
            $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');

            $challan = DeliveryChallan::create([
                'store_id' => $store->id,
                'number' => $this->numbers->next('delivery_challan', $store, $date),
                'customer_id' => $customer?->id,
                'customer_name' => trim($data['customer_name'] ?? '') ?: $customer?->name,
                'customer_email' => filled($data['customer_email'] ?? null) ? mb_strtolower(trim($data['customer_email'])) : $customer?->email,
                'customer_phone' => ($data['customer_phone'] ?? null) ?: $customer?->phone,
                'customer_gstin' => GstStates::normalizeGstin($data['customer_gstin'] ?? null) ?? $customer?->gstin,
                'delivery_address' => ($data['delivery_address'] ?? null) ?: $customer?->address,
                'date' => $date->toDateString(),
                'purpose' => $data['purpose'] ?? 'sale',
                'status' => DeliveryChallan::STATUS_ISSUED,
                'vehicle_number' => isset($data['vehicle_number']) ? mb_strtoupper(trim($data['vehicle_number'])) : null,
                'transporter' => $data['transporter'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            // Price and tax rate are noted for reference; the bill prices at invoicing time.
            $challan->items()->createMany(array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $products[(int) $item['product_id']]->price,
                'tax_percent' => $products[(int) $item['product_id']]->tax_percent,
            ], $items));

            $this->stock->moveMany(
                $store,
                array_map(fn (array $item) => ['product_id' => (int) $item['product_id'], 'quantity' => -(int) $item['quantity']], $items),
                StockMovement::TYPE_CHALLAN,
                $user,
                "Challan {$challan->number}",
                $challan,
            );

            return $challan->load(['items.product', 'store']);
        });
    }

    /**
     * Bill one or more issued challans of the same store and customer as one
     * order. Their stock is first put back, then placeOrder deducts it again
     * as a sale and posts the sales voucher, all in one transaction.
     *
     * @param  list<int>  $challanIds
     * @param  array<string, mixed>  $data  payment_mode, amount_paid, customer_email, customer_name, place_of_supply, interstate
     *
     * @throws InsufficientStockException
     * @throws ValidationException
     */
    public function invoice(array $challanIds, array $data, User $user): Order
    {
        return DB::transaction(function () use ($challanIds, $data, $user) {
            /** @var Collection<int, DeliveryChallan> $challans */
            $challans = DeliveryChallan::query()
                ->with(['items', 'store', 'customer'])
                ->whereKey($challanIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($challans->count() !== count(array_unique($challanIds))
                || $challans->contains(fn (DeliveryChallan $c) => ! $user->canAccessStore($c->store_id))) {
                abort(404);
            }

            $this->ensureInvoiceable($challans);

            $first = $challans->first();
            $store = $first->store;
            $withCustomer = $challans->first(fn (DeliveryChallan $c) => $c->customer_id !== null);
            $email = $withCustomer?->customer?->email
                ?? $challans->pluck('customer_email')->filter()->first()
                ?? ($data['customer_email'] ?? null);

            if (blank($email)) {
                throw ValidationException::withMessages(['customer_email' => 'A customer email is needed to make the bill.']);
            }

            foreach ($challans as $challan) {
                $this->stock->moveMany(
                    $store,
                    $challan->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all(),
                    StockMovement::TYPE_CHALLAN,
                    $user,
                    "Invoiced: challan {$challan->number}",
                    $challan,
                );
            }

            // Same product on several challans becomes one bill line.
            $items = $challans->flatMap->items
                ->groupBy('product_id')
                ->map(fn (Collection $lines, $productId) => ['product_id' => (int) $productId, 'quantity' => (int) $lines->sum('quantity')])
                ->values()
                ->all();

            $order = $this->orders->placeOrder(
                email: $email,
                name: ($data['customer_name'] ?? null) ?: $first->customer_name,
                items: $items,
                amountPaid: $data['amount_paid'] ?? null,
                cashier: $user,
                customerId: $withCustomer?->customer_id,
                interstate: (bool) ($data['interstate'] ?? false),
                paymentMode: ($data['payment_mode'] ?? null) ?: Order::PAYMENT_CASH,
                store: $store,
                customerGstin: $challans->pluck('customer_gstin')->filter()->first(),
                billingAddress: $challans->pluck('delivery_address')->filter()->first(),
                placeOfSupply: $data['place_of_supply'] ?? null,
            );

            DeliveryChallan::query()->whereKey($challans->modelKeys())->update([
                'status' => DeliveryChallan::STATUS_INVOICED,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'updated_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * The goods came back (returned) or the dispatch is called off
     * (cancelled): stock goes back into the store.
     */
    public function close(DeliveryChallan $challan, string $status, ?User $user): DeliveryChallan
    {
        return DB::transaction(function () use ($challan, $status, $user) {
            $challan = DeliveryChallan::query()->with(['items', 'store'])->lockForUpdate()->findOrFail($challan->id);

            if ($challan->status !== DeliveryChallan::STATUS_ISSUED) {
                throw ValidationException::withMessages(['status' => "This challan is already {$challan->status}."]);
            }

            $this->stock->moveMany(
                $challan->store,
                $challan->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all(),
                StockMovement::TYPE_CHALLAN,
                $user,
                ($status === DeliveryChallan::STATUS_RETURNED ? 'Returned' : 'Cancelled').": challan {$challan->number}",
                $challan,
            );

            $challan->update(['status' => $status]);

            return $challan->load(['items.product', 'store']);
        });
    }

    /**
     * @param  Collection<int, DeliveryChallan>  $challans
     */
    private function ensureInvoiceable(Collection $challans): void
    {
        if ($challans->contains(fn (DeliveryChallan $c) => $c->status !== DeliveryChallan::STATUS_ISSUED)) {
            throw ValidationException::withMessages(['challan_ids' => 'Only issued challans can be invoiced.']);
        }

        if ($challans->pluck('store_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['challan_ids' => 'All challans must be from the same store.']);
        }

        if ($challans->map(fn (DeliveryChallan $c) => $this->customerKey($c))->unique()->count() > 1) {
            throw ValidationException::withMessages(['challan_ids' => 'All challans must be for the same customer.']);
        }
    }

    /**
     * Who a challan is for: the linked customer, else the email, else the name.
     */
    private function customerKey(DeliveryChallan $challan): string
    {
        $email = $challan->customer?->email ?? $challan->customer_email;

        return filled($email) ? 'e:'.mb_strtolower($email) : 'n:'.mb_strtolower(trim($challan->customer_name));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function matchCustomer(array $data): ?Customer
    {
        if (filled($data['customer_id'] ?? null)) {
            return Customer::find($data['customer_id']);
        }

        if (filled($data['customer_email'] ?? null)) {
            return Customer::where('email', mb_strtolower(trim($data['customer_email'])))->first();
        }

        return null;
    }
}

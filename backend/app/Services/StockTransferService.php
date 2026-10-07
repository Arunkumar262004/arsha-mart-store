<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock transfers between stores of the same business. No accounting entry:
 * the goods only change location. While in transit they are in neither
 * store's stock.
 */
class StockTransferService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
    ) {}

    /**
     * Dispatch goods from $from to another store: stock leaves $from now.
     *
     * @param  array<string, mixed>  $data  validated SaveStockTransferRequest data
     *
     * @throws InsufficientStockException
     */
    public function dispatch(array $data, Store $from, ?User $user): StockTransfer
    {
        $to = Store::query()->active()->find($data['to_store_id']);

        if ($to === null || $to->id === $from->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Choose a different, active store to send the stock to.']);
        }

        return DB::transaction(function () use ($data, $from, $to, $user) {
            $items = array_values($data['items']);

            $transfer = StockTransfer::create([
                'number' => $this->numbers->next('stock_transfer', $from),
                'from_store_id' => $from->id,
                'to_store_id' => $to->id,
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'dispatched_at' => now(),
                'dispatched_by' => $user?->id,
                'dispatched_by_name' => $user?->name,
                'vehicle_number' => isset($data['vehicle_number']) ? mb_strtoupper(trim($data['vehicle_number'])) : null,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->items()->createMany(array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ], $items));

            $this->stock->moveMany(
                $from,
                array_map(fn (array $item) => ['product_id' => (int) $item['product_id'], 'quantity' => -(int) $item['quantity']], $items),
                StockMovement::TYPE_TRANSFER_OUT,
                $user,
                "Transfer {$transfer->number} to {$to->name}",
                $transfer,
            );

            return $transfer->load(['items.product', 'fromStore', 'toStore']);
        });
    }

    /**
     * The destination store received the goods: stock arrives there.
     */
    public function receive(StockTransfer $transfer, ?User $user): StockTransfer
    {
        return $this->finish($transfer, StockTransfer::STATUS_RECEIVED, $user);
    }

    /**
     * Called off while in transit: the goods go back into the source store.
     */
    public function cancel(StockTransfer $transfer, ?User $user): StockTransfer
    {
        return $this->finish($transfer, StockTransfer::STATUS_CANCELLED, $user);
    }

    private function finish(StockTransfer $transfer, string $status, ?User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $status, $user) {
            $transfer = StockTransfer::query()->with(['items', 'fromStore', 'toStore'])->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== StockTransfer::STATUS_IN_TRANSIT) {
                throw ValidationException::withMessages(['status' => 'This transfer is already '.str_replace('_', ' ', $transfer->status).'.']);
            }

            $received = $status === StockTransfer::STATUS_RECEIVED;

            $this->stock->moveMany(
                $received ? $transfer->toStore : $transfer->fromStore,
                $transfer->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all(),
                StockMovement::TYPE_TRANSFER_IN,
                $user,
                $received
                    ? "Transfer {$transfer->number} from {$transfer->fromStore->name}"
                    : "Transfer {$transfer->number} cancelled, back in stock",
                $transfer,
            );

            $transfer->update($received
                ? ['status' => $status, 'received_at' => now(), 'received_by' => $user?->id, 'received_by_name' => $user?->name]
                : ['status' => $status]);

            return $transfer->load(['items.product', 'fromStore', 'toStore']);
        });
    }
}

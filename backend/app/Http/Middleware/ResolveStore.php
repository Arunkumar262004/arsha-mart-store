<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the store this request works in.
 *
 * - A user assigned to a store always works in that store; the header is ignored.
 * - Everyone else chooses with the X-Store-Id header: a store id, or "all" to
 *   read across every store (new documents then go to their own store or the
 *   first active one). Without the header: their own store, else the first
 *   active store.
 */
class ResolveStore
{
    /**
     * Endpoints that create a document or change stock in the current store.
     * (Documents tied to an existing one, like a sales return of a bill, use
     * that document's store instead and are not listed.)
     */
    private const CREATES_IN_CURRENT_STORE = [
        'api/orders', 'api/products/*/stock', 'api/quotations', 'api/challans', 'api/transfers',
        'api/purchases', 'api/purchase-returns', 'api/receipts', 'api/payments', 'api/expenses',
        'api/vouchers', 'api/accounts/day-closing',
    ];

    public function __construct(private readonly StoreContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->worksInAllStores()) {
            $store = $user->store;
            abort_unless($store?->is_active, 403, 'Your store is inactive. Contact your admin.');
            $this->context->set($store);

            return $next($request);
        }

        $header = trim((string) $request->header('X-Store-Id'));
        $fallback = fn () => ($user?->store?->is_active ? $user->store : null)
            ?? Store::active()->orderBy('id')->first()
            ?? Store::main();

        if ($header === 'all') {
            // New documents belong to one store: refuse to guess which.
            abort_if(
                $request->isMethod('post') && $request->is(self::CREATES_IN_CURRENT_STORE),
                422,
                'Select a store in the header before creating this.',
            );
            $this->context->set($fallback(), all: true);
        } elseif ($header !== '') {
            $store = ctype_digit($header) ? Store::find((int) $header) : null;
            abort_unless($store?->is_active, 422, 'The selected store does not exist or is inactive.');
            $this->context->set($store);
        } else {
            $this->context->set($fallback());
        }

        return $next($request);
    }
}

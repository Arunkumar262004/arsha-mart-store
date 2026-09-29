<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a bill as an 80 mm thermal-receipt PDF, attached to the
 * confirmation email and sent as a WhatsApp document.
 */
class ReceiptPdf
{
    /** 80 mm roll width in PDF points (1 mm = 2.8346 pt). */
    private const WIDTH_PT = 226.77;

    public function render(Order $order): string
    {
        $order->loadMissing(['customer', 'cashier', 'items.product']);

        return Pdf::loadView('receipts.thermal', ['order' => $order])
            ->setPaper([0, 0, self::WIDTH_PT, $this->heightFor($order)])
            // Embed only the characters used, not the whole font (~480 KB -> ~30 KB).
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    public function filename(Order $order): string
    {
        return "Bill-{$order->order_number}.pdf";
    }

    /**
     * A till slip is as long as its contents, so size the page to fit:
     * a fixed header and footer plus room for each line and GST rate.
     */
    private function heightFor(Order $order): float
    {
        $rates = $order->items->pluck('tax_percent')->map(fn ($rate) => (float) $rate)->unique()->count();

        $cashLines = $order->amount_paid !== null ? 30 : 0;

        return 400 + ($order->items->count() * 32) + ($rates * 12) + $cashLines;
    }
}

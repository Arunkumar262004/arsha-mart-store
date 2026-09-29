<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\ReceiptPdf;
use App\Services\WhatsApp\WasenderClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kept separate from the email job so a WhatsApp outage (or retry) never
 * causes the email to be re-sent, and vice versa.
 */
class SendOrderWhatsAppConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before each retry.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(public Order $order) {}

    public function handle(WasenderClient $whatsApp, ReceiptPdf $receipt): void
    {
        if ($this->order->whatsapp_sent_at !== null) {
            return;
        }

        $this->order->loadMissing(['customer', 'items.product']);
        $phone = $this->order->customer->phone;

        if ($phone === null) {
            return;
        }

        if (! $whatsApp->isConfigured()) {
            Log::warning('WhatsApp confirmation skipped: WASENDER_API_TOKEN is not set.', [
                'order_number' => $this->order->order_number,
            ]);

            return;
        }

        // One message: the PDF bill, with the order summary as its caption.
        $whatsApp->sendDocument(
            $phone,
            $receipt->render($this->order),
            $receipt->filename($this->order),
            'application/pdf',
            $this->message(),
        );

        $this->order->forceFill(['whatsapp_sent_at' => now()])->save();

        Log::info('Order confirmation WhatsApp sent.', [
            'order_number' => $this->order->order_number,
            'phone' => $phone,
        ]);
    }

    private function message(): string
    {
        $order = $this->order;

        $lines = $order->items->map(
            fn ($item) => "• {$item->product->name} × {$item->quantity} = ₹{$item->line_total}"
        )->implode("\n");

        return implode("\n", [
            "Hi {$order->customer->name}, thank you for shopping with ".config('app.name').'!',
            '',
            "*Order {$order->order_number}*",
            $lines,
            '',
            "Subtotal: ₹{$order->subtotal}",
            ...($order->is_interstate
                ? ["IGST: ₹{$order->igst_amount}"]
                : ["CGST: ₹{$order->cgst_amount}", "SGST: ₹{$order->sgst_amount}"]),
            "*Grand total: ₹{$order->grand_total}*",
        ]);
    }
}

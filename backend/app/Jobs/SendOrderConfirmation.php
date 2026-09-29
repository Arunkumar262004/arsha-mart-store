<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmation implements ShouldQueue
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

    /**
     * Send the confirmation through the configured mailer. With the default
     * MAIL_MAILER=log the full email lands in storage/logs/laravel.log.
     */
    public function handle(): void
    {
        // Idempotent: a retry after a partial failure must not email twice.
        if ($this->order->confirmation_sent_at !== null) {
            return;
        }

        $this->order->loadMissing(['customer', 'items.product']);

        Mail::to($this->order->customer->email, $this->order->customer->name)
            ->send(new OrderConfirmationMail($this->order));

        $this->order->forceFill(['confirmation_sent_at' => now()])->save();

        Log::info('Order confirmation email sent.', [
            'order_number' => $this->order->order_number,
            'customer_email' => $this->order->customer->email,
            'grand_total' => $this->order->grand_total,
        ]);
    }
}

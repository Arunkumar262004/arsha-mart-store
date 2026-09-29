<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\ReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your order {$this->order->order_number} is confirmed",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.order-confirmation',
        );
    }

    /**
     * The thermal-receipt bill as a PDF.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $receipt = app(ReceiptPdf::class);

        return [
            Attachment::fromData(fn () => $receipt->render($this->order), $receipt->filename($this->order))
                ->withMime('application/pdf'),
        ];
    }
}

<?php

namespace Tests\Unit;

use App\Jobs\SendOrderConfirmation;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendOrderConfirmationJobTest extends TestCase
{
    use RefreshDatabase;

    public function testJobIsQueuedNotRunInline(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(SendOrderConfirmation::class));
    }

    public function testItMailsTheCustomerAndMarksTheOrderConfirmed(): void
    {
        Mail::fake();
        $order = Order::factory()->create();

        (new SendOrderConfirmation($order))->handle();

        Mail::assertSent(OrderConfirmationMail::class, fn ($mail) => $mail->hasTo($order->customer->email));
        $this->assertNotNull($order->fresh()->confirmation_sent_at);
    }

    public function testTheEmailCarriesTheBillAsAPdf(): void
    {
        $order = Order::factory()->create();

        [$attachment] = (new OrderConfirmationMail($order))->attachments();
        $pdf = $attachment->attachWith(fn ($path) => null, fn ($data) => $data());

        $this->assertSame("Bill-{$order->order_number}.pdf", $attachment->as);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testItDoesNotSendTwiceOnRetry(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['confirmation_sent_at' => now()]);

        (new SendOrderConfirmation($order))->handle();

        Mail::assertNothingSent();
    }
}

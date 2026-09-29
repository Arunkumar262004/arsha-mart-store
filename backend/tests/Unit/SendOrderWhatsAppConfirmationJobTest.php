<?php

namespace Tests\Unit;

use App\Jobs\SendOrderWhatsAppConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Services\ReceiptPdf;
use App\Services\WhatsApp\WasenderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendOrderWhatsAppConfirmationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.wasender.token' => 'test-token',
            'services.wasender.url' => 'https://wasender.test/messages/text',
            'services.wasender.document_url' => 'https://wasender.test/messages/document',
        ]);
    }

    public function testItSendsThePdfBillToTheCustomersWhatsapp(): void
    {
        Http::fake(['wasender.test/*' => Http::response(['success' => true])]);
        $order = $this->orderFor('9876543210');

        $this->handle($order);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://wasender.test/messages/document'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '919876543210'
            && str_starts_with($request['media'], 'data:application/pdf;base64,JVBERi')
            && $request['filename'] === "Bill-{$order->order_number}.pdf"
            && str_contains($request['caption'], $order->order_number));
        $this->assertNotNull($order->fresh()->whatsapp_sent_at);
    }

    public function testApiErrorThrowsSoTheQueueRetries(): void
    {
        Http::fake(['wasender.test/*' => Http::response(['success' => false], 500)]);
        $order = $this->orderFor('9876543210');

        try {
            $this->handle($order);
            $this->fail('Expected a RequestException.');
        } catch (RequestException) {
            $this->assertNull($order->fresh()->whatsapp_sent_at);
        }
    }

    public function testItSkipsWhenNotConfiguredOrAlreadySent(): void
    {
        Http::fake();

        config(['services.wasender.token' => null]);
        $this->handle($this->orderFor('9876543210'));

        config(['services.wasender.token' => 'test-token']);
        $sent = $this->orderFor('9876543211', ['whatsapp_sent_at' => now()]);
        $this->handle($sent);

        Http::assertNothingSent();
    }

    private function handle(Order $order): void
    {
        (new SendOrderWhatsAppConfirmation($order))->handle(app(WasenderClient::class), app(ReceiptPdf::class));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function orderFor(string $phone, array $attributes = []): Order
    {
        return Order::factory()
            ->for(Customer::factory()->state(['phone' => $phone]))
            ->create($attributes);
    }
}

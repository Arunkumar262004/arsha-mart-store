<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmation;
use App\Jobs\SendOrderWhatsAppConfirmation;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();

        Queue::fake();
    }

    public function testLookupByPhoneReturnsEmailAndName(): void
    {
        Customer::factory()->create(['email' => 'thomas@example.com', 'name' => 'Thomas', 'phone' => '9876543210']);

        // Any common spelling of the number matches the stored E.164 value.
        foreach (['9876543210', '+91 98765 43210', '098765-43210', '919876543210'] as $spelling) {
            $this->getJson('/api/customers/lookup?phone='.urlencode($spelling))
                ->assertOk()
                ->assertJsonPath('data.email', 'thomas@example.com')
                ->assertJsonPath('data.name', 'Thomas')
                ->assertJsonPath('data.phone', '+919876543210');
        }
    }

    public function testLookupByEmailReturnsPhoneAndName(): void
    {
        Customer::factory()->create(['email' => 'priya@example.com', 'name' => 'Priya', 'phone' => '9123456780']);

        $this->getJson('/api/customers/lookup?email=PRIYA@example.com')
            ->assertOk()
            ->assertJsonPath('data.name', 'Priya')
            ->assertJsonPath('data.phone', '+919123456780');
    }

    public function testLookupMissesAndBadInput(): void
    {
        $this->getJson('/api/customers/lookup?phone=9000000000')->assertNotFound();
        $this->getJson('/api/customers/lookup?email=nobody@example.com')->assertNotFound();
        $this->getJson('/api/customers/lookup')->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
        $this->getJson('/api/customers/lookup?phone=12ab')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function testOrderSavesThePhoneForANewCustomerAndQueuesWhatsapp(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'new@example.com',
            'customer_name' => 'New Person',
            'customer_phone' => '98765 11111',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer.phone', '+919876511111');

        Queue::assertPushed(SendOrderConfirmation::class);
        Queue::assertPushed(SendOrderWhatsAppConfirmation::class);
    }

    public function testNoWhatsappJobWithoutAPhone(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'nophone@example.com',
            'customer_name' => 'No Phone',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        Queue::assertPushed(SendOrderConfirmation::class);
        Queue::assertNotPushed(SendOrderWhatsAppConfirmation::class);
    }

    public function testExistingCustomerGetsTheirPhoneAdded(): void
    {
        $customer = Customer::factory()->create(['email' => 'old@example.com', 'phone' => null]);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'old@example.com',
            'customer_phone' => '9000011111',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame('+919000011111', $customer->fresh()->phone);
    }

    public function testPhoneOwnedByAnotherCustomerIsRejected(): void
    {
        Customer::factory()->create(['email' => 'owner@example.com', 'phone' => '9876543210']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'someone@example.com',
            'customer_name' => 'Someone',
            'customer_phone' => '+91 98765 43210',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_phone')
            // The counter is told who owns the number so it can offer to update them.
            ->assertJsonPath('conflict.type', 'phone_owner')
            ->assertJsonPath('conflict.customer.email', 'owner@example.com');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function testPhoneOwnerCanBeUpdatedWithANewEmailWhileBilling(): void
    {
        $arun = Customer::factory()->create(['name' => 'Arun', 'email' => 'arun@old.com', 'phone' => '9578777764']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_id' => $arun->id,
            'update_customer' => true,
            'customer_email' => 'Arun@New.com',
            'customer_name' => 'Arun Kumar',
            'customer_phone' => '95787 77764',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.customer.id', $arun->id)
            ->assertJsonPath('data.customer.email', 'arun@new.com')
            ->assertJsonPath('data.customer.name', 'Arun Kumar');

        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('+919578777764', $arun->fresh()->phone);
    }

    public function testBillingTheNewEmailWithoutTheTakenMobileCreatesASeparateCustomer(): void
    {
        $arun = Customer::factory()->create(['email' => 'arun@old.com', 'phone' => '9578777764']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'someone@new.com',
            'customer_name' => 'Someone',
            'customer_phone' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer.phone', null);

        $this->assertSame('arun@old.com', $arun->fresh()->email, 'The owner is left untouched.');
    }

    public function testUpdatingACustomerCannotStealAnotherCustomersEmail(): void
    {
        $arun = Customer::factory()->create(['email' => 'arun@old.com', 'phone' => '9578777764']);
        Customer::factory()->create(['email' => 'taken@example.com']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_id' => $arun->id,
            'update_customer' => true,
            'customer_email' => 'taken@example.com',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_email');

        $this->assertSame('arun@old.com', $arun->fresh()->email);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'x@example.com',
            'customer_name' => 'X',
            'customer_phone' => '12345',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
    }
}

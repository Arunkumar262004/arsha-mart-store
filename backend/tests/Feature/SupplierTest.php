<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function testSupplierCrudWithOpeningBalanceOnTheLedger(): void
    {
        $this->signIn(['suppliers.manage']);

        $id = $this->postJson('/api/suppliers', [
            'name' => 'Fresh Farms', 'gstin' => '33abcde1234f1z5', 'phone' => '9876543210',
            'payment_terms_days' => 30, 'opening_balance' => 1500,
        ])->assertCreated()
            ->assertJsonPath('data.gstin', '33ABCDE1234F1Z5')
            ->assertJsonPath('data.state_code', '33')
            ->assertJsonPath('data.outstanding', '1500.00')
            ->json('data.id');

        $supplier = Supplier::find($id);
        $ledger = Account::forParty($supplier);
        $this->assertSame("SUP-{$id}", $ledger->code);
        $this->assertSame('payable', $ledger->group);
        $this->assertSame('liability', $ledger->type);
        $this->assertSame('-1500.00', $ledger->opening_balance);
        $this->assertSame(-150000, app(AccountingService::class)->balance($ledger));

        // GSTIN is unique when present; blank GSTINs never collide.
        $this->postJson('/api/suppliers', ['name' => 'Copy', 'gstin' => '33ABCDE1234F1Z5'])
            ->assertUnprocessable()->assertJsonValidationErrors('gstin');
        $this->postJson('/api/suppliers', ['name' => 'No GST 1', 'gstin' => ''])->assertCreated();
        $this->postJson('/api/suppliers', ['name' => 'No GST 2'])->assertCreated();

        $this->putJson("/api/suppliers/{$id}", ['name' => 'Fresh Farms Ltd', 'gstin' => '33ABCDE1234F1Z5'])
            ->assertOk()->assertJsonPath('data.name', 'Fresh Farms Ltd');
        $this->assertSame('Fresh Farms Ltd', $ledger->fresh()->name);

        $this->getJson('/api/suppliers?search=fresh')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outstanding', '1500.00');

        $this->getJson("/api/suppliers/{$id}")->assertOk()
            ->assertJsonPath('data.name', 'Fresh Farms Ltd')
            ->assertJsonPath('recent_purchases', []);
    }

    public function testASupplierWithDocumentsCanOnlyBeDeactivated(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 0]);
        $unused = Supplier::create(['name' => 'Unused']);
        $used = Supplier::create(['name' => 'Used']);

        $this->postJson('/api/purchases', [
            'supplier_id' => $used->id, 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10]],
        ])->assertCreated();

        $this->deleteJson("/api/suppliers/{$used->id}")->assertUnprocessable();
        $this->putJson("/api/suppliers/{$used->id}", ['name' => 'Used', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/suppliers/{$unused->id}")->assertOk();
        $this->assertModelMissing($unused);
    }

    public function testSupplierManagementNeedsPermission(): void
    {
        $this->signIn(['orders.view']);

        $this->getJson('/api/suppliers')->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'X'])->assertForbidden();

        // Purchasing staff can read the list for the picker but not edit suppliers.
        $this->signIn(['purchases.manage']);
        $this->getJson('/api/suppliers')->assertOk();
        $this->postJson('/api/suppliers', ['name' => 'X'])->assertForbidden();
    }
}

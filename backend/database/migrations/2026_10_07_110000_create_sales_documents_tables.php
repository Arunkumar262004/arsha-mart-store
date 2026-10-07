<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales documents: B2B customer details on customers and bills (GSTIN,
 * billing address, place of supply), quotations, delivery challans and
 * stock transfers between stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gstin', 15)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('pincode', 10)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Snapshots taken when the bill is made, so later edits to the
            // customer never change an issued invoice.
            $table->string('customer_gstin', 15)->nullable()->index();
            $table->string('billing_address', 500)->nullable();
            // GST state code of the place of supply, e.g. "29" (Karnataka).
            $table->string('place_of_supply', 2)->nullable();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_gstin', 15)->nullable();
            $table->date('date');
            $table->date('valid_until')->nullable();
            // draft | sent | accepted | cancelled | converted ("expired" is derived)
            $table->string('status', 20)->default('draft');
            $table->boolean('is_interstate')->default(false);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('tax_percent', 5, 2);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('cgst_percent', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_percent', 5, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_percent', 5, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });

        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_gstin', 15)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->date('date');
            // sale | approval | job_work | other
            $table->string('purpose', 20)->default('sale');
            // issued | invoiced | returned | cancelled
            $table->string('status', 20)->default('issued');
            $table->string('vehicle_number', 20)->nullable();
            $table->string('transporter', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('delivery_challan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_challan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('tax_percent', 5, 2);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->restrictOnDelete();
            // in_transit | received | cancelled
            $table->string('status', 20)->default('in_transit');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispatched_by_name')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->string('vehicle_number', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_store_id', 'status']);
            $table->index(['to_store_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('delivery_challan_items');
        Schema::dropIfExists('delivery_challans');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_gstin']);
            $table->dropColumn(['customer_gstin', 'billing_address', 'place_of_supply']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'address', 'city', 'state', 'state_code', 'pincode']);
        });
    }
};

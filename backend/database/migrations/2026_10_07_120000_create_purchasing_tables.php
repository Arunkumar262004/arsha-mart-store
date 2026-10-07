<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing and money: suppliers, purchases (goods received), purchase
 * returns (debit notes), sales returns (credit notes) and the simple money
 * documents (customer receipts, supplier payments, expenses).
 *
 * Every document keeps its own totals and GST split; the accounting effect
 * lives in the vouchers it posts (vouchers.source = the document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique when present: several suppliers may have no GSTIN (NULLs never collide).
            $table->string('gstin', 15)->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number', 50)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('date');
            $table->boolean('is_interstate')->default(false);
            // cash | bank (paid now) | credit (owed to the supplier)
            $table->string('payment_mode', 10);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('freight', 14, 2)->default(0);
            // Signed: positive = rounded up.
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            // posted | cancelled
            $table->string('status', 10)->default('posted');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['supplier_id', 'date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Cost per unit before GST.
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('reason', 500)->nullable();
            $table->boolean('is_interstate')->default(false);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            // credit (reduce what we owe) | cash | bank (supplier refunds money)
            $table->string('refund_mode', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            // The store of the original bill: stock goes back there.
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('reason', 500)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            // cash | bank (money back) | credit (reduces what the customer owes / store credit)
            $table->string('refund_mode', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });

        // Customer receipts, supplier payments and expenses share one table:
        // they have the same shape (an amount moving in or out of cash / bank).
        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            // receipt | payment | expense
            $table->string('kind', 10);
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->date('date');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            // The expense ledger debited (expenses only).
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            // cash | bank
            $table->string('mode', 10);
            // Before GST for expenses; the full amount otherwise.
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('paid_to')->nullable();
            $table->string('supplier_gstin', 15)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('narration', 500)->nullable();
            // posted | cancelled (cancelling posts a reversing journal)
            $table->string('status', 10)->default('posted');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['kind', 'store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_transactions');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};

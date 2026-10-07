<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry bookkeeping: a chart of accounts, vouchers (one per business
 * event: a sale, a purchase, a payment...) and their debit / credit lines.
 * Also the counters behind gap-free document numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);           // invoice, quotation, delivery_challan, ...
            $table->string('financial_year', 5);  // "26-27" = April 2026 to March 2027
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['store_id', 'type', 'financial_year']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            // asset | liability | equity | income | expense
            $table->string('type', 10);
            // Finer grouping for reports: cash, bank, receivable, payable, duties_taxes,
            // sales, purchase, direct_expense, indirect_expense, capital, current_asset, ...
            $table->string('group', 30)->index();
            // Customer / supplier ledgers point at their party.
            $table->nullableMorphs('party');
            // Signed: positive = debit balance, negative = credit balance.
            $table->decimal('opening_balance', 14, 2)->default(0);
            // Built-in accounts the software posts to; cannot be deleted or renamed by code.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            // sales, purchase, receipt, payment, contra, journal, credit_note, debit_note, expense
            $table->string('type', 20);
            $table->string('number', 40)->unique();
            $table->date('date');
            $table->string('narration')->nullable();
            // The document that produced it (order, purchase, ...); null for manual entries.
            $table->nullableMorphs('source');
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['type', 'date']);
        });

        Schema::create('voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // Copied from the voucher so ledgers filter without a join.
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);

            $table->index(['account_id', 'date']);
            $table->index(['store_id', 'date']);
        });

        $now = now();
        DB::table('accounts')->insert(array_map(fn (array $a) => [
            'code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'group' => $a[3],
            'is_system' => true, 'is_active' => true, 'opening_balance' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ], [
            ['1000', 'Cash in Hand', 'asset', 'cash'],
            ['1010', 'Bank Account', 'asset', 'bank'],
            ['1100', 'Closing Stock', 'asset', 'current_asset'],
            ['1300', 'Input CGST', 'asset', 'duties_taxes'],
            ['1301', 'Input SGST', 'asset', 'duties_taxes'],
            ['1302', 'Input IGST', 'asset', 'duties_taxes'],
            ['2200', 'Output CGST', 'liability', 'duties_taxes'],
            ['2201', 'Output SGST', 'liability', 'duties_taxes'],
            ['2202', 'Output IGST', 'liability', 'duties_taxes'],
            ['3000', 'Capital Account', 'equity', 'capital'],
            ['4000', 'Sales', 'income', 'sales'],
            ['4010', 'Sales Returns', 'income', 'sales'],
            ['4900', 'Other Income', 'income', 'indirect_income'],
            ['5000', 'Purchases', 'expense', 'purchase'],
            ['5010', 'Purchase Returns', 'expense', 'purchase'],
            ['5100', 'Freight Inward', 'expense', 'direct_expense'],
            ['6000', 'Rent', 'expense', 'indirect_expense'],
            ['6010', 'Salaries & Wages', 'expense', 'indirect_expense'],
            ['6020', 'Electricity', 'expense', 'indirect_expense'],
            ['6030', 'Stock Write-off', 'expense', 'indirect_expense'],
            ['6040', 'Transport', 'expense', 'indirect_expense'],
            ['6050', 'Packaging', 'expense', 'indirect_expense'],
            ['6060', 'Repairs & Maintenance', 'expense', 'indirect_expense'],
            ['6090', 'Miscellaneous Expenses', 'expense', 'indirect_expense'],
            ['6900', 'Round Off', 'expense', 'indirect_expense'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_entries');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('document_sequences');
    }
};

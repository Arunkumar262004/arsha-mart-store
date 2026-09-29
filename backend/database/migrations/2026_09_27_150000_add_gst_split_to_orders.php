<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GST is recorded as CGST + SGST (sale within the store's state) or IGST
 * (sale to another state), both per line and as order totals.
 * Rates live on the lines because one bill can mix 5%, 12% and 18% items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_interstate')->default(false)->after('tax_total');
            $table->decimal('cgst_amount', 12, 2)->default(0)->after('is_interstate');
            $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('cgst_percent', 5, 2)->default(0)->after('line_tax');
            $table->decimal('cgst_amount', 12, 2)->default(0)->after('cgst_percent');
            $table->decimal('sgst_percent', 5, 2)->default(0)->after('cgst_amount');
            $table->decimal('sgst_amount', 12, 2)->default(0)->after('sgst_percent');
            $table->decimal('igst_percent', 5, 2)->default(0)->after('sgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0)->after('igst_percent');
        });

        // Existing bills were all counter sales inside the state: split their tax evenly.
        DB::table('order_items')->update([
            'cgst_percent' => DB::raw('tax_percent / 2'),
            'sgst_percent' => DB::raw('tax_percent / 2'),
            'cgst_amount' => DB::raw('ROUND(line_tax / 2, 2)'),
            'sgst_amount' => DB::raw('line_tax - ROUND(line_tax / 2, 2)'),
        ]);
        DB::table('orders')->update([
            'cgst_amount' => DB::raw('ROUND(tax_total / 2, 2)'),
            'sgst_amount' => DB::raw('tax_total - ROUND(tax_total / 2, 2)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cgst_percent', 'cgst_amount', 'sgst_percent', 'sgst_amount', 'igst_percent', 'igst_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_interstate', 'cgst_amount', 'sgst_amount', 'igst_amount']);
        });
    }
};

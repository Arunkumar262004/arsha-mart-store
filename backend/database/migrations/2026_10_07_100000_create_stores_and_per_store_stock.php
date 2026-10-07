<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple stores: every bill, stock level and stock movement now belongs to
 * a store. Existing data moves into a "Main Store" created here, and the
 * single products.stock column is replaced by one stock row per store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Short code used in document numbers, e.g. MAIN/INV/26-27/00001.
            $table->string('code', 10)->unique();
            $table->string('gstin', 15)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $mainStoreId = DB::table('stores')->insertGetId([
            'name' => 'Main Store',
            'code' => 'MAIN',
            'gstin' => config('inventory.store.gstin') ?: null,
            'phone' => config('inventory.store.phone') ?: null,
            'address' => config('inventory.store.address') ?: null,
            'state' => config('inventory.home_state') ?: null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Unsigned: the database itself refuses a negative stock value.
            $table->unsignedInteger('stock')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['product_id', 'store_id']);
            $table->index(['store_id', 'stock']);
        });

        DB::table('product_stocks')->insertUsing(
            ['product_id', 'store_id', 'stock', 'updated_at'],
            // The id is inlined: PostgreSQL cannot infer a bound parameter's type in a select list.
            DB::table('products')->selectRaw('id, '.(int) $mainStoreId.', stock, updated_at'),
        );

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['stock']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('hsn_code', 8)->nullable()->after('code');
            // Department / section of the store, e.g. Grocery, Dairy, Personal Care.
            $table->string('category', 100)->nullable()->after('hsn_code')->index();
            $table->string('unit', 10)->default('pcs')->after('category');
            // Purchase cost per unit (excluding GST), for margin reports.
            $table->decimal('cost_price', 10, 2)->nullable()->after('price');
        });

        // null = may work in every store (and switch between them).
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            // Gap-free GST invoice number, per store and financial year.
            $table->string('invoice_number', 40)->nullable()->unique()->after('order_number');
            $table->string('payment_mode', 10)->default('cash')->after('grand_total');
            $table->index(['store_id', 'created_at']);
        });
        DB::table('orders')->update(['store_id' => $mainStoreId]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            // The document behind the change (purchase, transfer, challan, ...).
            $table->nullableMorphs('source');
            $table->index(['store_id', 'created_at']);
        });
        DB::table('stock_movements')->update(['store_id' => $mainStoreId]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropMorphs('source');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropUnique(['invoice_number']);
            $table->dropColumn(['invoice_number', 'payment_mode']);
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['hsn_code', 'category', 'unit', 'cost_price']);
            $table->unsignedInteger('stock')->default(0)->index();
        });

        DB::table('products')->update([
            'stock' => DB::raw('(SELECT COALESCE(SUM(stock), 0) FROM product_stocks WHERE product_stocks.product_id = products.id)'),
        ]);

        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('stores');
    }
};

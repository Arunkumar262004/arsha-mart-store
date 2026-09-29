<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the name of the employee who billed an order or adjusted stock.
 * The user foreign keys are nulled when an account is deleted, so without
 * this copy the reports would lose who did the work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('created_by_name')->nullable()->after('created_by');
            $table->index('created_at');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('user_name')->nullable()->after('user_id');
            $table->index('created_at');
        });

        DB::table('orders')->whereNotNull('created_by')->update([
            'created_by_name' => DB::raw('(SELECT name FROM users WHERE users.id = orders.created_by)'),
        ]);
        DB::table('stock_movements')->whereNotNull('user_id')->update([
            'user_name' => DB::raw('(SELECT name FROM users WHERE users.id = stock_movements.user_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('user_name');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('created_by_name');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-day cash count per store: the cash the books expect in the drawer,
 * what was actually counted, and the difference (short / excess).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('expected_cash', 14, 2);
            $table->decimal('counted_cash', 14, 2);
            // counted − expected: negative = cash short.
            $table->decimal('difference', 14, 2);
            $table->string('notes', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};

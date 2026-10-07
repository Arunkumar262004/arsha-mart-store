<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-wide settings as key / value pairs: company name, tagline, and the
 * uploaded logo and favicon (stored as data URIs, like profile photos, so they
 * survive hosts with a throwaway disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->mediumText('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

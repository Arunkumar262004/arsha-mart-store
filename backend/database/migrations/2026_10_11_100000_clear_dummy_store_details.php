<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first stores migration copied the sample STORE_ADDRESS / STORE_PHONE /
 * STORE_GSTIN values from .env onto the main store, where they override the
 * details set under Settings → Company. Clear exactly those sample values.
 */
return new class extends Migration
{
    private const SAMPLES = [
        'address' => '12, Gandhi Road, Coimbatore 641001',
        'phone' => '+91 98765 00000',
        'gstin' => '33ABCDE1234F1Z5',
    ];

    public function up(): void
    {
        foreach (self::SAMPLES as $column => $sample) {
            DB::table('stores')->where($column, $sample)->update([$column => null]);
            DB::table('settings')->where('key', $column)->where('value', $sample)->update(['value' => null]);
        }
    }

    public function down(): void
    {
        // Sample data is not restored.
    }
};

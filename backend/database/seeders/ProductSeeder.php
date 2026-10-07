<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class ProductSeeder extends Seeder
{
    /**
     * A small counter catalog across the store's departments; a few items
     * start below the default low-stock threshold so the alert has
     * something to show. Stock goes into the main store.
     */
    public function run(): void
    {
        $products = [
            ['name' => 'Colgate Toothpaste 100g', 'code' => 'COL-100', 'hsn_code' => '3306', 'category' => 'Personal Care', 'price' => 50.00, 'cost_price' => 38.00, 'tax_percent' => 18, 'stock' => 40],
            ['name' => 'Parle-G Biscuit', 'code' => 'PAR-G-01', 'hsn_code' => '1905', 'category' => 'Snacks', 'price' => 10.00, 'cost_price' => 8.20, 'tax_percent' => 5, 'stock' => 120],
            ['name' => 'Bread (400g)', 'code' => 'BRD-400', 'hsn_code' => '1905', 'category' => 'Bakery', 'price' => 45.00, 'cost_price' => 36.00, 'tax_percent' => 0, 'stock' => 4],
            ['name' => 'Milk 1L', 'code' => 'MLK-1L', 'hsn_code' => '0401', 'category' => 'Dairy', 'unit' => 'l', 'price' => 62.00, 'cost_price' => 55.00, 'tax_percent' => 0, 'stock' => 9],
            ['name' => 'Eggs (12)', 'code' => 'EGG-12', 'hsn_code' => '0407', 'category' => 'Dairy', 'unit' => 'dozen', 'price' => 84.00, 'cost_price' => 70.00, 'tax_percent' => 0, 'stock' => 2],
            ['name' => 'Tata Salt 1kg', 'code' => 'TAT-SLT-1', 'hsn_code' => '2501', 'category' => 'Grocery', 'price' => 28.00, 'cost_price' => 22.50, 'tax_percent' => 5, 'stock' => 60],
            ['name' => 'Aashirvaad Atta 5kg', 'code' => 'ASH-ATT-5', 'hsn_code' => '1101', 'category' => 'Grocery', 'price' => 265.00, 'cost_price' => 228.00, 'tax_percent' => 5, 'stock' => 25],
            ['name' => 'Surf Excel 1kg', 'code' => 'SRF-XL-1', 'hsn_code' => '3402', 'category' => 'Household', 'price' => 140.00, 'cost_price' => 112.00, 'tax_percent' => 18, 'stock' => 30],
            ['name' => 'Dove Soap 100g', 'code' => 'DOV-100', 'hsn_code' => '3401', 'category' => 'Personal Care', 'price' => 58.50, 'cost_price' => 44.00, 'tax_percent' => 18, 'stock' => 1],
            ['name' => 'Maggi Noodles 70g', 'code' => 'MAG-70', 'hsn_code' => '1902', 'category' => 'Snacks', 'price' => 14.00, 'cost_price' => 11.30, 'tax_percent' => 12, 'stock' => 200],
        ];

        $store = Store::main();

        // Stock is written directly: DatabaseSeeder mutes model events, so
        // Product's own "stock" handling does not run here.
        foreach ($products as $attributes) {
            $stock = Arr::pull($attributes, 'stock');
            $product = Product::updateOrCreate(['code' => $attributes['code']], $attributes);
            ProductStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $store->id], ['stock' => $stock]);
        }
    }
}

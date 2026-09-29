<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * A small counter catalog; a few items start below the default
     * low-stock threshold so the alert has something to show.
     */
    public function run(): void
    {
        $products = [
            ['name' => 'Colgate Toothpaste 100g', 'code' => 'COL-100', 'price' => 50.00, 'tax_percent' => 18, 'stock' => 40],
            ['name' => 'Parle-G Biscuit', 'code' => 'PAR-G-01', 'price' => 10.00, 'tax_percent' => 5, 'stock' => 120],
            ['name' => 'Bread (400g)', 'code' => 'BRD-400', 'price' => 45.00, 'tax_percent' => 0, 'stock' => 4],
            ['name' => 'Milk 1L', 'code' => 'MLK-1L', 'price' => 62.00, 'tax_percent' => 0, 'stock' => 9],
            ['name' => 'Eggs (12)', 'code' => 'EGG-12', 'price' => 84.00, 'tax_percent' => 0, 'stock' => 2],
            ['name' => 'Tata Salt 1kg', 'code' => 'TAT-SLT-1', 'price' => 28.00, 'tax_percent' => 5, 'stock' => 60],
            ['name' => 'Aashirvaad Atta 5kg', 'code' => 'ASH-ATT-5', 'price' => 265.00, 'tax_percent' => 5, 'stock' => 25],
            ['name' => 'Surf Excel 1kg', 'code' => 'SRF-XL-1', 'price' => 140.00, 'tax_percent' => 18, 'stock' => 30],
            ['name' => 'Dove Soap 100g', 'code' => 'DOV-100', 'price' => 58.50, 'tax_percent' => 18, 'stock' => 1],
            ['name' => 'Maggi Noodles 70g', 'code' => 'MAG-70', 'price' => 14.00, 'tax_percent' => 12, 'stock' => 200],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(['code' => $product['code']], $product);
        }
    }
}

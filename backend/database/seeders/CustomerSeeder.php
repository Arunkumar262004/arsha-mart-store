<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // Real test numbers: bills for these customers send actual WhatsApp messages.
        Customer::updateOrCreate(['email' => 'arun@example.com'], ['name' => 'Arun Kumar', 'phone' => '9578777764']);
        Customer::updateOrCreate(['email' => 'priya@example.com'], ['name' => 'Priya Ramasamy', 'phone' => '9092276443']);

        Customer::factory()->count(8)->create();
    }
}

<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    private const FIRST_NAMES = [
        'Arun', 'Bala', 'Dinesh', 'Ganesh', 'Karthik', 'Murugan', 'Prakash', 'Saravanan', 'Senthil', 'Vignesh',
        'Anitha', 'Deepa', 'Divya', 'Kavitha', 'Lakshmi', 'Meena', 'Nandhini', 'Revathi', 'Selvi', 'Tamilselvi',
    ];

    private const LAST_NAMES = [
        'Annamalai', 'Chidambaram', 'Elango', 'Govindan', 'Kannan', 'Muthusamy', 'Natarajan', 'Palanisamy',
        'Ramasamy', 'Subramanian', 'Thangaraj', 'Velusamy',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->randomElement(self::FIRST_NAMES);
        $last = fake()->randomElement(self::LAST_NAMES);

        return [
            'name' => "{$first} {$last}",
            'email' => strtolower("{$first}.{$last}").fake()->unique()->numberBetween(1, 9999).'@example.com',
            'phone' => fake()->unique()->numerify('55########'),
        ];
    }
}

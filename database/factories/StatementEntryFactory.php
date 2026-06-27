<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\StatementEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementEntry>
 */
class StatementEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'invoice_no' => fake()->unique()->numerify('INV-#####'),
            'amount' => fake()->randomFloat(3, 10, 5000),
        ];
    }
}

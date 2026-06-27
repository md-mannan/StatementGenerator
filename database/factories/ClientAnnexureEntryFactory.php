<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAnnexureEntry>
 */
class ClientAnnexureEntryFactory extends Factory
{
    protected $model = ClientAnnexureEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'invoice_no' => fake()->unique()->numerify('INV-#####'),
            'amount' => fake()->randomFloat(3, 10, 5000),
        ];
    }
}

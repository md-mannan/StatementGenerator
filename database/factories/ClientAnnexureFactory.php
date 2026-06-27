<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAnnexure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAnnexure>
 */
class ClientAnnexureFactory extends Factory
{
    protected $model = ClientAnnexure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'year' => 2026,
            'month' => 5,
            'payment_checks' => [
                ['check_number' => '051939', 'amount' => 6500.000],
                ['check_number' => '070414', 'amount' => 6700.083],
            ],
            'rebate' => 150.000,
            'notes' => null,
        ];
    }
}

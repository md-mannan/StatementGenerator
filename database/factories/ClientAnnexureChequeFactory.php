<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAnnexureCheque>
 */
class ClientAnnexureChequeFactory extends Factory
{
    protected $model = ClientAnnexureCheque::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'year' => now()->year,
            'month' => now()->month,
            'cheque_date' => now()->startOfMonth(),
            'check_number' => '',
            'amount' => 0,
            'rebate' => 0,
            'review_completed' => false,
            'payment_saved' => false,
        ];
    }
}

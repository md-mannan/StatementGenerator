<?php

namespace App\Models;

use Database\Factories\ClientAnnexureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property int $year
 * @property int $month
 * @property list<array{check_number: string, amount: float|string}> $payment_checks
 * @property string $rebate
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'user_id', 'year', 'month', 'payment_checks', 'rebate', 'review_completed', 'payment_saved', 'notes'])]
class ClientAnnexure extends Model
{
    /** @use HasFactory<ClientAnnexureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_checks' => 'array',
            'rebate' => 'decimal:3',
            'review_completed' => 'boolean',
            'payment_saved' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

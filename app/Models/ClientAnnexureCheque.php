<?php

namespace App\Models;

use Database\Factories\ClientAnnexureChequeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property int $year
 * @property int $month
 * @property Carbon|null $cheque_date
 * @property string $check_number
 * @property string $amount
 * @property string $rebate
 * @property bool $review_completed
 * @property bool $payment_saved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'user_id', 'year', 'month', 'cheque_date', 'check_number', 'amount', 'rebate', 'review_completed', 'payment_saved'])]
class ClientAnnexureCheque extends Model
{
    /** @use HasFactory<ClientAnnexureChequeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (ClientAnnexureCheque $cheque): void {
            if ($cheque->cheque_date !== null || ! $cheque->year || ! $cheque->month) {
                return;
            }

            $cheque->cheque_date = Carbon::create($cheque->year, $cheque->month, 1);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_date' => 'date',
            'amount' => 'decimal:3',
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

    /**
     * @return HasMany<ClientAnnexureEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ClientAnnexureEntry::class);
    }

    /**
     * @param  Builder<ClientAnnexureCheque>  $query
     */
    public function scopeForMonth(Builder $query, int $year, int $month): void
    {
        $query->where('year', $year)->where('month', $month);
    }
}

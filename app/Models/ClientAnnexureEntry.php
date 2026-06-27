<?php

namespace App\Models;

use Database\Factories\ClientAnnexureEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property int $client_annexure_cheque_id
 * @property int|null $branch_id
 * @property Carbon $transaction_date
 * @property string $invoice_no
 * @property string $amount
 * @property bool $no_branch_expected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'user_id', 'client_annexure_cheque_id', 'branch_id', 'transaction_date', 'invoice_no', 'amount', 'no_branch_expected'])]
class ClientAnnexureEntry extends Model
{
    /** @use HasFactory<ClientAnnexureEntryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:3',
            'no_branch_expected' => 'boolean',
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
     * @return BelongsTo<ClientAnnexureCheque, $this>
     */
    public function annexureCheque(): BelongsTo
    {
        return $this->belongsTo(ClientAnnexureCheque::class, 'client_annexure_cheque_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<ClientAnnexureEntry>  $query
     */
    public function scopeForMonth(Builder $query, int $year, int $month): void
    {
        $query->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);
    }
}

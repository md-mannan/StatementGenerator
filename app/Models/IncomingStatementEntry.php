<?php

namespace App\Models;

use Database\Factories\IncomingStatementEntryFactory;
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
 * @property int|null $branch_id
 * @property Carbon $transaction_date
 * @property int|null $statement_year
 * @property int|null $statement_month
 * @property string $invoice_no
 * @property string $amount
 * @property bool $no_branch_expected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'user_id', 'branch_id', 'transaction_date', 'statement_year', 'statement_month', 'invoice_no', 'amount', 'no_branch_expected'])]
class IncomingStatementEntry extends Model
{
    /** @use HasFactory<IncomingStatementEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (IncomingStatementEntry $entry): void {
            if ($entry->statement_year !== null && $entry->statement_month !== null) {
                return;
            }

            $date = $entry->transaction_date instanceof Carbon
                ? $entry->transaction_date
                : Carbon::parse($entry->transaction_date);

            $entry->statement_year = $date->year;
            $entry->statement_month = $date->month;
        });
    }

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
     * @param  Builder<IncomingStatementEntry>  $query
     */
    public function scopeForMonth(Builder $query, int $year, int $month): void
    {
        $query->where('statement_year', $year)
            ->where('statement_month', $month);
    }

    /**
     * @param  Builder<IncomingStatementEntry>  $query
     */
    public function scopeForInvoiceMonth(Builder $query, int $year, int $month): void
    {
        $query->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);
    }

    /**
     * @param  Builder<IncomingStatementEntry>  $query
     * @param  iterable<int, array{year: int, month: int}>  $periods
     */
    public function scopeForInvoiceMonths(Builder $query, iterable $periods): void
    {
        $periods = collect($periods)->values();

        if ($periods->isEmpty()) {
            return;
        }

        $query->where(function (Builder $query) use ($periods): void {
            foreach ($periods as $period) {
                $query->orWhere(function (Builder $query) use ($period): void {
                    $query->forInvoiceMonth($period['year'], $period['month']);
                });
            }
        });
    }
}

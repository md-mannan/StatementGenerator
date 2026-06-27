<?php

namespace App\Models;

use Database\Factories\StatementEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property Carbon $transaction_date
 * @property int|null $statement_year
 * @property int|null $statement_month
 * @property string $invoice_no
 * @property string $amount
 * @property bool $no_bill_expected
 * @property string|null $invoice_scan_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['branch_id', 'user_id', 'transaction_date', 'statement_year', 'statement_month', 'invoice_no', 'amount', 'no_bill_expected', 'invoice_scan_path'])]
class StatementEntry extends Model
{
    /** @use HasFactory<StatementEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (StatementEntry $entry): void {
            if ($entry->statement_year !== null && $entry->statement_month !== null) {
                return;
            }

            $date = $entry->transaction_date instanceof Carbon
                ? $entry->transaction_date
                : Carbon::parse($entry->transaction_date);

            $entry->statement_year = $date->year;
            $entry->statement_month = $date->month;
        });

        static::deleting(function (StatementEntry $entry): void {
            if ($entry->invoice_scan_path !== null) {
                Storage::disk('local')->delete($entry->invoice_scan_path);
            }
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
            'no_bill_expected' => 'boolean',
        ];
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
     * @param  Builder<StatementEntry>  $query
     */
    public function scopeForMonth(Builder $query, int $year, int $month): void
    {
        $query->where('statement_year', $year)
            ->where('statement_month', $month);
    }

    /**
     * @param  Builder<StatementEntry>  $query
     */
    public function scopeForInvoiceMonth(Builder $query, int $year, int $month): void
    {
        $query->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);
    }

    /**
     * @param  Builder<StatementEntry>  $query
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

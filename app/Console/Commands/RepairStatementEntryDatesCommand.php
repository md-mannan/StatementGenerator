<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairStatementEntryDatesCommand extends Command
{
    protected $signature = 'statement-entries:repair-dates {--dry-run : Show how many rows would change without updating them}';

    protected $description = 'Backfill invalid statement entry transaction dates from statement year and month';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('This command is only supported on MySQL.');

            return self::FAILURE;
        }

        $query = DB::table('statement_entries')
            ->whereNotNull('statement_year')
            ->whereNotNull('statement_month')
            ->where('statement_year', '>', 0)
            ->where('statement_month', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('transaction_date')
                    ->orWhereRaw("CAST(transaction_date AS CHAR) LIKE '0000-%'");
            });

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No statement entries need date repair.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would repair {$count} statement entr".($count === 1 ? 'y' : 'ies').'.');

            return self::SUCCESS;
        }

        $updated = $query->update([
            'transaction_date' => DB::raw("LAST_DAY(STR_TO_DATE(CONCAT(statement_year, '-', statement_month, '-01'), '%Y-%m-%d'))"),
            'updated_at' => now(),
        ]);

        $this->info("Repaired {$updated} statement entr".($updated === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}

<?php

use App\Models\ClientAnnexure;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_annexure_entries', function (Blueprint $table) {
            $table->foreignId('client_annexure_cheque_id')
                ->nullable()
                ->after('user_id')
                ->constrained('client_annexure_cheques')
                ->cascadeOnDelete();
        });

        $this->migrateExistingData();

        if (ClientAnnexureEntry::query()->whereNull('client_annexure_cheque_id')->doesntExist()) {
            Schema::table('client_annexure_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('client_annexure_cheque_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_annexure_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_annexure_cheque_id');
        });
    }

    private function migrateExistingData(): void
    {
        $groupedEntries = ClientAnnexureEntry::query()
            ->get()
            ->groupBy(fn (ClientAnnexureEntry $entry): string => $entry->client_id.'|'.$entry->transaction_date->year.'|'.$entry->transaction_date->month);

        foreach ($groupedEntries as $key => $entries) {
            [$clientId, $year, $month] = array_map(intval(...), explode('|', (string) $key));

            $annexure = ClientAnnexure::query()
                ->where('client_id', $clientId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            $paymentChecks = collect($annexure?->payment_checks ?? [])
                ->filter(fn (array $check): bool => trim((string) ($check['check_number'] ?? '')) !== ''
                    || (float) ($check['amount'] ?? 0) > 0)
                ->values();

            if ($paymentChecks->isEmpty()) {
                $cheque = ClientAnnexureCheque::query()->create([
                    'client_id' => $clientId,
                    'user_id' => $entries->first()->user_id,
                    'year' => $year,
                    'month' => $month,
                    'check_number' => '',
                    'amount' => 0,
                    'rebate' => (float) ($annexure?->rebate ?? 0),
                    'review_completed' => (bool) ($annexure?->review_completed ?? false),
                    'payment_saved' => (bool) ($annexure?->payment_saved ?? false),
                ]);

                ClientAnnexureEntry::query()
                    ->whereIn('id', $entries->pluck('id'))
                    ->update(['client_annexure_cheque_id' => $cheque->id]);

                continue;
            }

            $paymentChecks->each(function (array $check, int $index) use ($clientId, $year, $month, $annexure, $entries): void {
                $cheque = ClientAnnexureCheque::query()->create([
                    'client_id' => $clientId,
                    'user_id' => $entries->first()->user_id,
                    'year' => $year,
                    'month' => $month,
                    'check_number' => trim((string) ($check['check_number'] ?? '')),
                    'amount' => (float) ($check['amount'] ?? 0),
                    'rebate' => $index === 0 ? (float) ($annexure?->rebate ?? 0) : 0,
                    'review_completed' => (bool) ($annexure?->review_completed ?? false),
                    'payment_saved' => (bool) ($annexure?->payment_saved ?? false),
                ]);

                if ($index === 0) {
                    ClientAnnexureEntry::query()
                        ->whereIn('id', $entries->pluck('id'))
                        ->update(['client_annexure_cheque_id' => $cheque->id]);
                }
            });
        }
    }
};

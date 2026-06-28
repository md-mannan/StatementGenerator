<?php

use App\Models\Client;
use App\Models\StatementEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('statement entry date repair command updates invalid transaction dates', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL-only repair command.');
    }

    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    DB::table('statement_entries')->insert([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '0000-00-00',
        'statement_year' => 2026,
        'statement_month' => 5,
        'invoice_no' => '27965',
        'amount' => 1181.000,
        'no_bill_expected' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('statement-entries:repair-dates');

    $entry = StatementEntry::query()->firstOrFail();

    expect($entry->transaction_date->format('Y-m-d'))->toBe('2026-05-31');
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'MySQL-only repair command.');

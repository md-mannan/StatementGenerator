<?php

use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

test('guests cannot access clients', function () {
    $this->get(route('clients.index'))->assertRedirect(route('login'));
});

test('authenticated users can list their clients', function () {
    $user = User::factory()->create();
    Client::factory()->forUser($user)->count(2)->create();
    Client::factory()->create();

    $this->actingAs($user)
        ->get(route('clients.index'))
        ->assertOk();
});

test('users can create a client with branches', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('clients.store'), ['name' => 'Lulu Hyper Market'])
        ->assertRedirect();

    $client = Client::query()->where('name', 'Lulu Hyper Market')->first();

    expect($client)->not->toBeNull()
        ->and($client->user_id)->toBe($user->id);

    $this->actingAs($user)
        ->post(route('clients.branches.store', $client), [
            'code' => 'BH001',
            'name' => 'Dubai Mall Branch',
        ])
        ->assertRedirect(route('clients.show', $client));

    $branch = $client->branches()->first();

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-06-01',
        'invoice_no' => 'INV-001',
        'amount' => 150.500,
    ]);

    $this->actingAs($user)
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/show')
            ->has('client.branches', 1)
            ->where('client.branches.0.total_amount', '150.500')
            ->where('client.branches.0.total_amount_value', 150.5)
            ->has('branchMonthStats', 1)
            ->where('branchMonthStats.0.year', 2026)
            ->where('branchMonthStats.0.month', 6)
            ->where('branchMonthStats.0.entries_count', 1)
            ->where('branchMonthStats.0.total_amount', '150.500'));
});

test('client branches page lists each statement month separately', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => 'INV-001',
        'amount' => 100.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-06-10',
        'invoice_no' => 'INV-002',
        'amount' => 50.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/show')
            ->has('branchMonthStats', 2)
            ->where('branchMonthStats.0.year', 2026)
            ->where('branchMonthStats.0.month', 6)
            ->where('branchMonthStats.0.entries_count', 1)
            ->where('branchMonthStats.0.total_amount', '50.000')
            ->where('branchMonthStats.1.year', 2026)
            ->where('branchMonthStats.1.month', 5)
            ->where('branchMonthStats.1.entries_count', 1)
            ->where('branchMonthStats.1.total_amount', '100.000'));
});

test('branch month stats use invoice month not statement period', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2025-12-15',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-10',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25514',
        'amount' => 33.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.generate-statement', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/generate-statement')
            ->has('branchMonthStats', 2)
            ->where('branchMonthStats.0.year', 2026)
            ->where('branchMonthStats.0.month', 1)
            ->where('branchMonthStats.1.year', 2025)
            ->where('branchMonthStats.1.month', 12));
});

test('users can view monthly statements for a branch', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-06-05',
        'invoice_no' => 'INV-1001',
        'amount' => 150.50,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 6,
        ]))
        ->assertOk();
});

test('branch statements default to the route branch and all months when no filters are selected', function () {
    Carbon::setTestNow('2026-06-15');

    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $firstBranch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);
    $secondBranch = $client->branches()->create([
        'code' => 'BH002',
        'name' => 'Second Branch',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $firstBranch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-10',
        'invoice_no' => 'INV-JAN',
        'amount' => 100,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $secondBranch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-04-12',
        'invoice_no' => 'INV-APR',
        'amount' => 200,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $firstBranch,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->where('periodLabel', 'All months')
            ->where('selectedPeriods', [])
            ->where('branchIds', [$firstBranch->id])
            ->has('entries', 1));
});

test('branch statements keep selected branches on refresh when branch_ids are in the url', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $firstBranch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);
    $secondBranch = $client->branches()->create([
        'code' => 'BH002',
        'name' => 'Second Branch',
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $firstBranch,
            'branch_ids' => [$firstBranch->id, $secondBranch->id],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->where('branchIds', [$firstBranch->id, $secondBranch->id]));
});

test('branch statement month filter only lists months with invoice dates', function () {
    Carbon::setTestNow('2026-06-15');

    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-04-12',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => 'INV-APR',
        'amount' => 200,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 4,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->has('availableMonths', 1)
            ->where('availableMonths.0.year', 2026)
            ->where('availableMonths.0.month', 4)
            ->missing('availableMonths.1'));
});

test('branch statements show cheque number received amount and difference', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '03',
        'name' => 'Dajeej',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-05',
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    $cheque = ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 5,
        'check_number' => '079125',
        'payment_saved' => true,
    ]);

    ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-05',
        'invoice_no' => '25513',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->where('entries.0.invoice_no', '25513')
            ->where('entries.0.cheque_number', '079125')
            ->where('entries.0.cheque_received_amount', '20.000')
            ->where('entries.0.difference_amount', '2.000')
            ->where('chequeReceivedTotal', '20.000')
            ->where('differenceTotal', '2.000'));
});

test('branch statements show client statement amount and difference', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '03',
        'name' => 'Dajeej',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-05',
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-05',
        'invoice_no' => '25513',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->where('entries.0.client_statement_amount', '20.000')
            ->where('entries.0.client_difference_amount', '-2.000')
            ->where('entries.0.is_resolved', true)
            ->where('clientStatementTotal', '20.000')
            ->where('clientDifferenceTotal', '-2.000'));
});

test('users can mark branch statement entries as no bill expected', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '03',
        'name' => 'Dajeej',
    ]);

    $entry = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-03-08',
        'invoice_no' => '26201',
        'amount' => 78.500,
    ]);

    $this->actingAs($user)
        ->patch(route('statement-entries.no-bill-expected', $entry), [
            'no_bill_expected' => true,
        ])
        ->assertRedirect();

    expect($entry->fresh()->no_bill_expected)->toBeTrue();

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 3,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.0.no_bill_expected', true)
            ->where('entries.0.is_resolved', false));
});

test('users can mark annexure entries as supplier invoice with no branch expected', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 5,
        'check_number' => '079125',
        'amount' => 18.000,
    ]);

    $entry = ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => null,
        'transaction_date' => '2026-03-28',
        'invoice_no' => '27682',
        'amount' => 18.000,
    ]);

    $this->actingAs($user)
        ->patch(route('client-annexure-entries.no-branch-expected', $entry), [
            'no_branch_expected' => true,
        ])
        ->assertRedirect();

    expect($entry->fresh()->no_branch_expected)->toBeTrue();

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/annexure/index')
            ->where('entries.0.no_branch_expected', true)
            ->where('entries.0.is_resolved', false)
            ->where('unresolvedCount', 0));
});

test('users can mark received statement entries as supplier invoice with no branch expected', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();

    $entry = \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => null,
        'transaction_date' => '2026-03-28',
        'invoice_no' => '27682',
        'amount' => 18.000,
    ]);

    $this->actingAs($user)
        ->patch(route('incoming-statement-entries.no-branch-expected', $entry), [
            'no_branch_expected' => true,
        ])
        ->assertRedirect();

    expect($entry->fresh()->no_branch_expected)->toBeTrue();

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 3,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->where('entries.0.no_branch_expected', true)
            ->where('entries.0.is_resolved', false)
            ->where('unresolvedCount', 0));
});

test('users can view statements for multiple branches and months', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Qurian',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-02-10',
        'invoice_no' => 'INV-2001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-03-12',
        'invoice_no' => 'INV-2002',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branchA,
            'branch_ids' => [$branchA->id, $branchB->id],
            'periods' => ['2026-2', '2026-3'],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('statements/index')
            ->where('branchIds', [$branchA->id, $branchB->id])
            ->has('selectedPeriods', 2)
            ->has('entries', 2)
            ->where('total', '30.000'));
});

test('branch statement exports include branch details for multiple branches', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Qurian',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-03-01',
        'invoice_no' => 'INV-3001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-03-02',
        'invoice_no' => 'INV-3002',
        'amount' => 20.000,
    ]);

    $query = [
        'branch_ids' => [$branchA->id, $branchB->id],
        'periods' => ['2026-3'],
    ];

    $this->actingAs($user)
        ->get(route('branches.statements.export.excel', [
            'branch' => $branchA,
            ...$query,
        ]))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($user)
        ->get(route('branches.statements.export.pdf', [
            'branch' => $branchA,
            ...$query,
        ]))
        ->assertOk()
        ->assertDownload();
});

test('users can open generate statement tab for a client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.generate-statement', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/generate-statement')
            ->has('branches', 1)
            ->has('branchMonthStats', 1)
            ->where('branchMonthStats.0.branch_id', $branch->id));
});

test('users can export multi branch statements for a client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Qurian',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '10001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '10002',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.generate-statement.export.excel', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'branch_ids' => [$branchA->id, $branchB->id],
        ]))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($user)
        ->get(route('clients.generate-statement.export.pdf', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'branch_ids' => [$branchA->id, $branchB->id],
        ]))
        ->assertOk()
        ->assertDownload();
});

test('users can export multi month statements for a client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-15',
        'invoice_no' => '10001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-02-10',
        'invoice_no' => '10002',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.statement.show', [
            'client' => $client,
            'branch_ids' => [$branch->id],
            'periods' => ['2026-1', '2026-2'],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/statement-view')
            ->has('entries', 2)
            ->where('total', '30.000')
            ->where('periodLabel', '2 months'));

    $this->actingAs($user)
        ->get(route('clients.generate-statement.export.excel', [
            'client' => $client,
            'branch_ids' => [$branch->id],
            'periods' => ['2026-1', '2026-2'],
        ]))
        ->assertOk()
        ->assertDownload();
});

test('users can view a combined statement for multiple branches', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Qurian',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '10001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '10002',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.statement.show', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'branch_ids' => [$branchA->id, $branchB->id],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/statement-view')
            ->has('entries', 2)
            ->where('total', '30.000'));
});

test('combined statement filters by invoice month not statement period', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-15',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.statement.show', [
            'client' => $client,
            'year' => 2026,
            'month' => 6,
            'branch_ids' => [$branch->id],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/statement-view')
            ->has('entries', 0));

    $this->actingAs($user)
        ->get(route('clients.statement.show', [
            'client' => $client,
            'year' => 2026,
            'month' => 1,
            'branch_ids' => [$branch->id],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/statement-view')
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '25513')
            ->where('entries.0.transaction_date', '15/01/2026'));
});

test('combined statement defaults to all branches when branch ids are missing', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Qurian',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '10001',
        'amount' => 10.000,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '10002',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.statement.show', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'filter' => 'unresolved',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/statement-view')
            ->has('entries', 2)
            ->where('total', '30.000'));
});

test('users cannot access another users client', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $client = Client::factory()->forUser($owner)->create();

    $this->actingAs($other)
        ->get(route('clients.show', $client))
        ->assertForbidden();
});

test('users can import and view received client statements', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->post(route('clients.received-statements.import.store', $client), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'client-statement.csv',
                "date,invoice_no,amount\n10/05/2026,27965,55.000\n11/05/2026,99999,10.000\n",
            ),
        ])
        ->assertRedirect(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 2)
            ->where('entries.0.invoice_no', '27965')
            ->where('entries.0.branch_code', '04')
            ->where('entries.0.branch_name', 'Salmiya')
            ->where('entries.0.is_resolved', true)
            ->where('entries.0.branch_amount', '55.000')
            ->where('entries.0.difference_amount', '0.000')
            ->where('entries.0.has_difference', false)
            ->where('entries.1.invoice_no', '99999')
            ->where('entries.1.branch_code', null)
            ->where('entries.1.is_resolved', false)
            ->where('entries.1.branch_amount', null)
            ->where('entries.1.difference_amount', null)
            ->where('unresolvedCount', 1)
            ->where('mismatchCount', 0));

    $this->actingAs($user)
        ->get(route('clients.received-statements.export.excel', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertDownload();
});

test('received statement export can be limited to filtered entry ids', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $included = \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 60.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-11',
        'invoice_no' => '99999',
        'amount' => 10.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.received-statements.export.excel', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'entry_ids' => [$included->id],
        ]))
        ->assertOk()
        ->assertDownload();
});

test('received statements compare amounts with branch statements', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 60.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 1)
            ->where('entries.0.amount', '60.000')
            ->where('entries.0.branch_amount', '55.000')
            ->where('entries.0.difference_amount', '5.000')
            ->where('entries.0.has_difference', true)
            ->where('mismatchCount', 1)
            ->where('totalDifference', '5.000')
            ->where('branchStatementTotal', '55.000'));
});

test('received statements filter by invoice month not statement filing month', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-01',
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-01',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 21.175,
    ]);

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 1)
            ->has('availableMonths', 1)
            ->where('availableMonths.0.year', 2026)
            ->where('availableMonths.0.month', 1)
            ->where('periodLabel', 'January 2026')
            ->where('entries.0.invoice_no', '25513')
            ->where('entries.0.branch_amount', '22.000')
            ->where('entries.0.invoice_date_differs_from_period', true));

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 6,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 0));
});

test('received statements auto lookup branch from branch statement by invoice', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '03',
        'name' => 'Dajeej',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-03-08',
        'invoice_no' => '26200',
        'amount' => 9.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => null,
        'transaction_date' => '2026-03-08',
        'invoice_no' => '26200',
        'amount' => 9.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 3,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '26200')
            ->where('entries.0.branch_id', null)
            ->where('entries.0.branch_code', '03')
            ->where('entries.0.suggested_branch_id', $branch->id)
            ->where('entries.0.branch_amount', '9.000')
            ->where('entries.0.is_resolved', false));
});

test('received statements support multiple invoice months', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2025-12-01',
        'invoice_no' => '25001',
        'amount' => 10.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-03-01',
        'invoice_no' => '26001',
        'amount' => 20.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'periods' => ['2025-12', '2026-3'],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 2)
            ->has('selectedPeriods', 2)
            ->where('periodLabel', '2 months')
            ->where('entries.0.statement_period', 'Dec 2025')
            ->where('entries.1.statement_period', 'Mar 2026'));
});

test('users can delete received statement entries individually and in bulk', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    $entryA = \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 63.000,
    ]);

    $entryB = \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '27964',
        'amount' => 132.500,
    ]);

    $this->actingAs($user)
        ->delete(route('incoming-statement-entries.destroy', $entryA), [
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(\App\Models\IncomingStatementEntry::query()->find($entryA->id))->toBeNull();

    $this->actingAs($user)
        ->delete(route('clients.received-statements.entries.bulk-destroy', $client), [
            'entry_ids' => [$entryB->id],
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(\App\Models\IncomingStatementEntry::query()->find($entryB->id))->toBeNull();
});

test('users can update received statement entries', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    $entry = \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 63.000,
    ]);

    $this->actingAs($user)
        ->put(route('incoming-statement-entries.update', $entry), [
            'branch_id' => $branch->id,
            'transaction_date' => '02/05/2026',
            'invoice_no' => '27999',
            'amount' => '70.500',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    $entry->refresh();

    expect($entry->transaction_date->format('Y-m-d'))->toBe('2026-05-02')
        ->and($entry->invoice_no)->toBe('27999')
        ->and((float) $entry->amount)->toBe(70.5);

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->where('entries.0.invoice_no', '27999')
            ->where('entries.0.amount', '70.500'));
});

test('users can bulk add received statement entries', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->post(route('clients.received-statements.entries.bulk-store', $client), [
            'entries' => [
                [
                    'transaction_date' => '10/05/2026',
                    'invoice_no' => '27965',
                    'amount' => '60.000',
                ],
                [
                    'transaction_date' => '11/05/2026',
                    'invoice_no' => '27966',
                    'amount' => '25.000',
                ],
            ],
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    $this->actingAs($user)
        ->get(route('clients.received-statements.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/received-statements/index')
            ->has('entries', 2)
            ->where('entries.0.invoice_no', '27965')
            ->where('entries.0.branch_code', '04')
            ->where('entries.0.amount', '60.000')
            ->where('entries.1.invoice_no', '27966')
            ->where('entries.1.branch_code', null));
});

test('statement entries can belong to a statement month with a different invoice date', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $this->actingAs($user)
        ->post(route('branches.statement-entries.bulk-store', $branch), [
            'entries' => [
                [
                    'transaction_date' => '15/02/2026',
                    'invoice_no' => '99001',
                    'amount' => '44.000',
                ],
            ],
            'year' => 2026,
            'month' => 1,
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 1,
        ]));

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries', 0));

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '99001')
            ->where('entries.0.transaction_date', '15/02/2026')
            ->where('entries.0.invoice_date_differs_from_period', true));
});

test('users can add a statement entry', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $this->actingAs($user)
        ->post(route('branches.statement-entries.store', $branch), [
            'transaction_date' => '15/05/2026',
            'invoice_no' => '99001',
            'amount' => '125.500',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]));

    $entry = StatementEntry::query()->where('invoice_no', '99001')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->branch_id)->toBe($branch->id)
        ->and($entry->transaction_date->toDateString())->toBe('2026-05-15')
        ->and((float) $entry->amount)->toBe(125.5);
});

test('users can bulk add branch statement entries from spreadsheet data', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $this->actingAs($user)
        ->post(route('branches.statement-entries.bulk-store', $branch), [
            'year' => 2026,
            'month' => 5,
            'entries' => [
                [
                    'transaction_date' => '03/05/2026',
                    'invoice_no' => '27990',
                    'amount' => '20',
                ],
                [
                    'transaction_date' => '05/05/2026',
                    'invoice_no' => '28010',
                    'amount' => '30',
                ],
            ],
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(StatementEntry::query()->where('branch_id', $branch->id)->count())->toBe(2)
        ->and((float) StatementEntry::query()->where('invoice_no', '27990')->value('amount'))->toBe(20.0);
});

test('bulk add saves entries to the selected statement month even when invoice dates differ', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $this->actingAs($user)
        ->post(route('branches.statement-entries.bulk-store', $branch), [
            'year' => 2026,
            'month' => 6,
            'entries' => [
                [
                    'transaction_date' => '01/05/2026',
                    'invoice_no' => '27990',
                    'amount' => '20',
                ],
                [
                    'transaction_date' => '02/05/2026',
                    'invoice_no' => '28010',
                    'amount' => '30',
                ],
            ],
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 6,
        ]));

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 6,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries', 0));

    $this->actingAs($user)
        ->get(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 2)
            ->where('entries.0.invoice_date_differs_from_period', true));
});

test('bulk add rejects invoice numbers pasted into the date column', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $this->actingAs($user)
        ->post(route('branches.statement-entries.bulk-store', $branch), [
            'entries' => [
                [
                    'transaction_date' => '01/05/2026',
                    'invoice_no' => '27990',
                    'amount' => '20',
                ],
                [
                    'transaction_date' => '27990',
                    'invoice_no' => '28010',
                    'amount' => '30',
                ],
            ],
        ])
        ->assertSessionHasErrors('entries.1.transaction_date');
});

test('users can update a statement entry', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $entry = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->put(route('statement-entries.update', $entry), [
            'transaction_date' => '02/05/2026',
            'invoice_no' => '27966',
            'amount' => '60.500',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]));

    $entry->refresh();

    expect($entry->transaction_date->toDateString())->toBe('2026-05-02')
        ->and($entry->invoice_no)->toBe('27966')
        ->and((float) $entry->amount)->toBe(60.5);
});

test('users can delete a statement entry', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $entry = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->delete(route('statement-entries.destroy', $entry), [
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(StatementEntry::query()->find($entry->id))->toBeNull();
});

test('users can bulk delete branch statement entries', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => 'BH001',
        'name' => 'Main Branch',
    ]);

    $entryA = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 63.000,
    ]);

    $entryB = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '27964',
        'amount' => 132.500,
    ]);

    $entryC = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-03',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->delete(route('branches.statement-entries.bulk-destroy', $branch), [
            'entry_ids' => [$entryA->id, $entryB->id],
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branch,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(StatementEntry::query()->find($entryA->id))->toBeNull()
        ->and(StatementEntry::query()->find($entryB->id))->toBeNull()
        ->and(StatementEntry::query()->find($entryC->id))->not->toBeNull();
});

test('users can bulk delete branch statement entries across multiple branches', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '03',
        'name' => 'Dajeej',
    ]);
    $branchB = $client->branches()->create([
        'code' => '04',
        'name' => 'Al Rai',
    ]);

    $entryA = StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 63.000,
    ]);

    $entryB = StatementEntry::factory()->create([
        'branch_id' => $branchB->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-02',
        'invoice_no' => '27964',
        'amount' => 132.500,
    ]);

    $this->actingAs($user)
        ->delete(route('branches.statement-entries.bulk-destroy', $branchA), [
            'entry_ids' => [$entryA->id, $entryB->id],
            'year' => 2026,
            'month' => 5,
            'branch_ids' => [$branchA->id, $branchB->id],
        ])
        ->assertRedirect(route('branches.statements.index', [
            'branch' => $branchA,
            'branch_ids' => [$branchA->id, $branchB->id],
            'year' => 2026,
            'month' => 5,
        ]));

    expect(StatementEntry::query()->find($entryA->id))->toBeNull()
        ->and(StatementEntry::query()->find($entryB->id))->toBeNull();
});

test('users can update and delete entries from the combined client statement view', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branchA = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch A',
    ]);
    $branchB = $client->branches()->create([
        'code' => '02',
        'name' => 'Branch B',
    ]);

    $entry = StatementEntry::factory()->create([
        'branch_id' => $branchA->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 55.000,
    ]);

    $redirectParams = [
        'client' => $client,
        'year' => 2026,
        'month' => 5,
        'branch_ids' => [$branchA->id, $branchB->id],
    ];

    $this->actingAs($user)
        ->put(route('statement-entries.update', $entry), [
            'transaction_date' => '02/05/2026',
            'invoice_no' => '27963',
            'amount' => '60.000',
            'year' => 2026,
            'month' => 5,
            'client_id' => $client->id,
            'branch_ids' => [$branchA->id, $branchB->id],
        ])
        ->assertRedirect(route('clients.statement.show', $redirectParams));

    $entry->refresh();

    expect((float) $entry->amount)->toBe(60.0);

    $this->actingAs($user)
        ->delete(route('statement-entries.destroy', $entry), [
            'year' => 2026,
            'month' => 5,
            'client_id' => $client->id,
            'branch_ids' => [$branchA->id, $branchB->id],
        ])
        ->assertRedirect(route('clients.statement.show', $redirectParams));

    expect(StatementEntry::query()->find($entry->id))->toBeNull();
});

test('users can bulk add client annexure entries manually', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->post(route('clients.annexure.entries.bulk-store', $client), [
            'entries' => [
                [
                    'transaction_date' => '10/01/2026',
                    'invoice_no' => '27965',
                    'amount' => '60.000',
                ],
            ],
            'year' => 2026,
            'month' => 1,
        ])
        ->assertRedirect();

    $cheque = \App\Models\ClientAnnexureCheque::query()->first();

    expect($cheque)->not->toBeNull();

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 1,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/annexure/index')
            ->where('phase', 'review')
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '27965')
            ->where('entries.0.amount', '60.000'));
});

test('annexure branch amount lookup uses invoice date month not cheque month', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '02',
        'name' => 'Branch 02',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-31',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25864',
        'amount' => 21.500,
    ]);

    $this->actingAs($user)
        ->post(route('clients.annexure.entries.bulk-store', $client), [
            'entries' => [
                [
                    'transaction_date' => '31/01/2026',
                    'invoice_no' => '25864',
                    'amount' => '21.500',
                ],
            ],
            'year' => 2026,
            'month' => 6,
        ])
        ->assertRedirect();

    $cheque = \App\Models\ClientAnnexureCheque::query()->first();

    expect($cheque)->not->toBeNull()
        ->and($cheque->year)->toBe(2026)
        ->and($cheque->month)->toBe(6);

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 6,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/annexure/index')
            ->where('phase', 'review')
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '25864')
            ->where('entries.0.branch_amount', '21.500')
            ->where('entries.0.difference_amount', '0.000')
            ->where('entries.0.has_difference', false));
});

test('users can view and save client annexure with check numbers and rebate', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->post(route('clients.annexure.import.store', $client), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'client-annexure.csv',
                "date,invoice,amount\n10/05/2026,27965,60.000\n",
            ),
        ])
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => \App\Models\ClientAnnexureCheque::query()->first()->id,
        ]));

    $cheque = \App\Models\ClientAnnexureCheque::query()->first();

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/annexure/index')
            ->where('phase', 'review')
            ->where('chequeId', $cheque->id)
            ->where('clientTotal', '60.000')
            ->where('branchTotal', '55.000')
            ->where('differenceTotal', '5.000')
            ->has('entries', 1));

    $this->actingAs($user)
        ->post(route('client-annexure-cheques.complete-review', $cheque))
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => $cheque->id,
        ]));

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('phase', 'payment'));

    $this->actingAs($user)
        ->put(route('client-annexure-cheques.update', $cheque), [
            'check_number' => '051939',
            'amount' => '60.000',
            'rebate' => '150.000',
            'cheque_date' => '10/05/2026',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('phase', 'complete')
            ->has('cheques', 1)
            ->where('cheques.0.check_number', '051939'));

    $this->actingAs($user)
        ->put(route('client-annexure-cheques.update', $cheque), [
            'check_number' => '051939',
            'amount' => '60.000',
            'rebate' => '150.000',
            'cheque_date' => '15/07/2026',
            'year' => 2026,
            'month' => 7,
            'stay_on_cheque' => true,
        ])
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 7,
            'cheque' => $cheque->id,
        ]));

    expect($cheque->fresh())
        ->year->toBe(2026)
        ->month->toBe(7)
        ->cheque_date->format('Y-m-d')->toBe('2026-07-15');

    $this->actingAs($user)
        ->delete(route('client-annexure-cheques.destroy', $cheque), [
            'year' => 2026,
            'month' => 5,
        ])
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]));

    expect(\App\Models\ClientAnnexureCheque::query()->count())->toBe(0);
    expect(\App\Models\ClientAnnexureEntry::query()->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('clients.annexure.export.pdf', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($user)
        ->post(route('clients.annexure.import.store', $client), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'client-annexure.csv',
                "date,invoice,amount\n10/05/2026,27965,60.000\n",
            ),
        ]);

    $this->actingAs($user)
        ->get(route('clients.annexure.export.excel', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
        ]))
        ->assertOk()
        ->assertDownload();
});

test('annexure import accepts client amount column headers and excel date formats', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '04',
        'name' => 'Salmiya',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => '27965',
        'amount' => 55.000,
    ]);

    $this->actingAs($user)
        ->post(route('clients.annexure.import.store', $client), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'client-annexure.csv',
                "sl,date,branch_id,invoice_no,client_amount,branch_amount,difference\n1,2026-05-10 00:00:00,04,27965,60.000,55.000,5.000\n",
            ),
        ])
        ->assertRedirect(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => \App\Models\ClientAnnexureCheque::query()->first()->id,
        ]));

    $cheque = \App\Models\ClientAnnexureCheque::query()->first();

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 5,
            'cheque' => $cheque->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '27965')
            ->where('entries.0.amount', '60.000'));
});

test('users can view cross check reconciling branch received and annexure data', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '02',
        'name' => 'Branch 02',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-31',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25864',
        'amount' => 21.500,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-31',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25864',
        'amount' => 21.500,
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '078160',
        'amount' => 21.500,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-31',
        'invoice_no' => '25864',
        'amount' => 21.500,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/cross-check/index')
            ->where('summary.context', 'cross_check')
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25864')
            ->where('rows.0.branch_amount', '21.500')
            ->where('rows.0.received_amount', '21.500')
            ->where('rows.0.annexure_amount', '21.500')
            ->where('rows.0.cheque_number', '078160')
            ->where('rows.0.cheque_period', 'Jun 2026')
            ->where('rows.0.status', 'complete')
            ->where('completeCount', 1));
});

test('cross check merges annexure cheque data using invoice month not statement period', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-01',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-01',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 21.175,
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '069660',
        'amount' => 21.175,
        'payment_saved' => true,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-01',
        'invoice_no' => '25513',
        'amount' => 21.175,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25513')
            ->where('rows.0.statement_period', 'Jan 2026')
            ->where('rows.0.branch_amount', '22.000')
            ->where('rows.0.received_amount', '21.175')
            ->where('rows.0.annexure_amount', '21.175')
            ->where('rows.0.cheque_number', '069660')
            ->where('rows.0.cheque_period', 'Jun 2026')
            ->where('rows.0.status', 'complete'));
});

test('cross check available periods use invoice month not statement period', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-02-01',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25875',
        'amount' => 8.500,
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-15',
        'statement_year' => 2026,
        'statement_month' => 6,
        'invoice_no' => '25513',
        'amount' => 22.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/cross-check/index')
            ->has('availablePeriods', 2)
            ->where('availablePeriods.0.year', 2026)
            ->where('availablePeriods.0.month', 2)
            ->where('availablePeriods.1.year', 2026)
            ->where('availablePeriods.1.month', 1)
            ->missing('availablePeriods.2'));
});

test('cross check marks mismatched invoices complete when cheque is issued', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '02',
        'name' => 'Branch 02',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-31',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25864',
        'amount' => 21.500,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-31',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25864',
        'amount' => 25.000,
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '078160',
        'amount' => 25.000,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-31',
        'invoice_no' => '25864',
        'amount' => 25.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/cross-check/index')
            ->where('rows.0.status', 'complete')
            ->where('rows.0.has_amount_mismatch', true)
            ->where('rows.0.cheque_issued', true)
            ->where('completeCount', 1)
            ->where('mismatchCount', 0));
});

test('cross check merges received rows when branch id is resolved from branch statement', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-15',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25433',
        'amount' => 16.250,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => null,
        'transaction_date' => '2026-01-15',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25433',
        'amount' => 16.250,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25433')
            ->where('rows.0.branch_code', '01')
            ->where('rows.0.branch_amount', '16.250')
            ->where('rows.0.received_amount', '16.250'));
});

test('cross check uses a single annexure amount when duplicate annexure entries exist', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Branch 01',
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '069660',
        'amount' => 21.175,
        'payment_saved' => true,
    ]);

    foreach ([21.175, 21.175, 21.175] as $amount) {
        \App\Models\ClientAnnexureEntry::factory()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'client_annexure_cheque_id' => $cheque->id,
            'branch_id' => $branch->id,
            'transaction_date' => '2026-01-15',
            'invoice_no' => '25513',
            'amount' => $amount,
        ]);
    }

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25513')
            ->where('rows.0.annexure_amount', '21.175')
            ->where('rows.0.cheque_number', '069660'));
});

test('cross check merges same invoice across different invoice months', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '07',
        'name' => 'Branch 07',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-01-23',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-23',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '069660',
        'amount' => 17.500,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2023-01-01',
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-23',
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25783')
            ->where('rows.0.statement_period', 'Jan 2026')
            ->where('rows.0.invoice_date', '23/01/2026')
            ->where('rows.0.branch_code', '07')
            ->where('rows.0.branch_amount', '17.500')
            ->where('rows.0.received_amount', '17.500')
            ->where('rows.0.annexure_amount', '17.500')
            ->where('rows.0.cheque_number', '069660')
            ->where('rows.0.status', 'complete'));
});

test('cross check marks invoices complete when cheque is issued but invoice is missing from other sources', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '07',
        'name' => 'Branch 07',
    ]);

    \App\Models\IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-23',
        'statement_year' => 2026,
        'statement_month' => 1,
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    $cheque = \App\Models\ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => '069660',
        'amount' => 17.500,
        'payment_saved' => true,
    ]);

    \App\Models\ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-01-23',
        'invoice_no' => '25783',
        'amount' => 17.500,
    ]);

    $this->actingAs($user)
        ->get(route('clients.cross-check.index', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.invoice_no', '25783')
            ->where('rows.0.branch_amount', null)
            ->where('rows.0.received_amount', '17.500')
            ->where('rows.0.annexure_amount', '17.500')
            ->where('rows.0.cheque_number', '069660')
            ->where('rows.0.status', 'complete')
            ->where('rows.0.missing_sources', ['branch'])
            ->where('completeCount', 1)
            ->where('incompleteCount', 0));
});

test('users can view a unified invoice detail page', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '02',
        'name' => 'Al Rai',
    ]);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-06-10',
        'invoice_no' => 'INV-900',
        'amount' => 120.000,
    ]);

    IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-06-10',
        'invoice_no' => 'INV-900',
        'amount' => 120.000,
    ]);

    $this->actingAs($user)
        ->get(route('clients.invoices.show', [
            'client' => $client,
            'invoiceNo' => 'INV-900',
        ]))
        ->assertOk()
            ->assertInertia(fn ($page) => $page
            ->component('clients/invoices/show')
            ->where('summary.context', 'invoice')
            ->where('invoice.invoice_no', 'INV-900')
            ->where('invoice.status', 'matched')
            ->where('invoice.branch_amount', '120.000')
            ->where('invoice.received_amount', '120.000')
            ->has('invoice.branch_entries', 1)
            ->has('invoice.received_entries', 1)
            ->has('invoice.annexure_entries', 0));
});

test('users can upload branch statement invoice scans named after the invoice number', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = $client->branches()->create([
        'code' => '01',
        'name' => 'Al Rai',
    ]);
    $entry = StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-01',
        'invoice_no' => '27963',
        'amount' => 158.500,
    ]);

    $this->actingAs($user)
        ->post(route('statement-entries.invoice-scan.store', $entry), [
            'scan' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect();

    $entry->refresh();

    expect($entry->invoice_scan_path)->not->toBeNull()
        ->and(basename($entry->invoice_scan_path))->toBe('27963.pdf');

    Storage::disk('local')->assertExists($entry->invoice_scan_path);

    $showResponse = $this->actingAs($user)
        ->get(route('statement-entries.invoice-scan.show', $entry));

    $showResponse->assertOk();
    expect($showResponse->headers->get('content-disposition'))
        ->toContain('inline')
        ->toContain('27963.pdf');

    $this->actingAs($user)
        ->delete(route('statement-entries.invoice-scan.destroy', $entry))
        ->assertRedirect();

    $entry->refresh();

    expect($entry->invoice_scan_path)->toBeNull();
    Storage::disk('local')->assertMissing('invoice-scans/clients/'.$client->id.'/branches/'.$branch->id.'/27963.pdf');
});

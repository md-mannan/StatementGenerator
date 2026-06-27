<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Models\User;

test('guests cannot use global search', function () {
    $this->get(route('search'))->assertRedirect(route('login'));
});

test('authenticated users can search clients and pages', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create(['name' => 'Lulu Hyper Market']);
    $branch = Branch::factory()->for($client)->create([
        'code' => 'BH001',
        'name' => 'Dubai Mall Branch',
    ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => 'lulu']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Branches',
            'subtitle' => 'Lulu Hyper Market',
        ])
        ->assertJsonFragment([
            'title' => 'Dubai Mall Branch',
            'subtitle' => 'Lulu Hyper Market · BH001',
        ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => 'dashboard']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Dashboard',
            'group' => 'Pages',
        ]);
});

test('global search returns default suggestions without a query', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('search'));

    $response->assertOk()
        ->assertJsonPath('results.0.title', 'Dashboard');
});

test('global search finds branch statement invoices', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create(['name' => 'Lulu Hyper Market']);
    $branch = Branch::factory()->for($client)->create(['code' => 'BH001']);

    StatementEntry::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-06-15',
        'invoice_no' => '25783',
        'amount' => 150.500,
    ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => '25783']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Invoice 25783',
            'group' => 'Invoice Overview',
        ])
        ->assertJsonFragment([
            'title' => 'Invoice 25783',
            'group' => 'Branch Statement',
        ]);
});

test('global search finds received statement entries by amount', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $branch = Branch::factory()->for($client)->create();

    IncomingStatementEntry::factory()->create([
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'transaction_date' => '2026-05-10',
        'invoice_no' => 'INV-9001',
        'amount' => 999.125,
    ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => '999.125']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Invoice INV-9001',
            'group' => 'Received Statement',
        ]);
});

test('global search finds annexure cheques by cheque number', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();

    ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'check_number' => 'CHQ-554433',
        'amount' => 2500.000,
        'rebate' => 0,
    ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => 'CHQ-554433']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Cheque CHQ-554433',
            'group' => 'Annexure Cheque',
        ]);
});

test('global search finds annexure invoices by date', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $cheque = ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
    ]);

    ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'transaction_date' => '2026-06-17',
        'invoice_no' => 'ANN-7788',
        'amount' => 88.500,
    ]);

    $this->actingAs($user)
        ->getJson(route('search', ['q' => '17/06/2026']))
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Invoice ANN-7788',
            'group' => 'Annexure Invoice',
        ]);
});

test('global search annexure invoice links use cheque month not invoice date month', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $cheque = ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
    ]);

    ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'transaction_date' => '2026-01-28',
        'invoice_no' => '25837',
        'amount' => 9.500,
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('search', ['q' => '25837']))
        ->assertOk()
        ->json('results');

    $annexureResult = collect($response)->firstWhere('group', 'Annexure Invoice');

    expect($annexureResult)->not->toBeNull()
        ->and($annexureResult['href'])->toContain('month=6')
        ->and($annexureResult['href'])->toContain('year=2026')
        ->and($annexureResult['href'])->toContain("cheque={$cheque->id}")
        ->and($annexureResult['href'])->toContain('search=25837');
});

test('annexure index opens cheque entries when cheque month differs from url month', function () {
    $user = User::factory()->create();
    $client = Client::factory()->forUser($user)->create();
    $cheque = ClientAnnexureCheque::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'year' => 2026,
        'month' => 6,
        'review_completed' => true,
        'payment_saved' => true,
    ]);

    ClientAnnexureEntry::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'client_annexure_cheque_id' => $cheque->id,
        'transaction_date' => '2026-01-28',
        'invoice_no' => '25837',
        'amount' => 9.500,
    ]);

    $this->actingAs($user)
        ->get(route('clients.annexure.index', [
            'client' => $client,
            'year' => 2026,
            'month' => 1,
            'cheque' => $cheque->id,
            'search' => '25837',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/annexure/index')
            ->where('year', 2026)
            ->where('month', 6)
            ->where('chequeId', $cheque->id)
            ->has('entries', 1)
            ->where('entries.0.invoice_no', '25837')
        );
});

<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\StatementEntry;
use App\Services\ClientPageSummaryService;
use App\Support\StatementAmount;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->withCount('branches')
            ->latest()
            ->get();

        return Inertia::render('clients/index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create');
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = new Client($request->validated());
        $client->user()->associate($request->user());
        $client->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client created successfully.'),
        ]);

        return to_route('clients.show', $client);
    }

    public function show(Client $client, ClientPageSummaryService $pageSummary): Response
    {
        $this->authorize('view', $client);

        $client->load([
            'branches' => fn ($query) => $query
                ->withCount('statementEntries')
                ->withSum('statementEntries as total_amount', 'amount')
                ->withMax('statementEntries', 'created_at')
                ->orderBy('code'),
        ]);

        return Inertia::render('clients/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'created_at' => $client->created_at,
                'updated_at' => $client->updated_at,
                'branches' => $this->mapBranches($client),
            ],
            'summary' => $pageSummary->forBranches($client),
            'branchMonthStats' => $this->branchMonthStats($client->branches->pluck('id')),
        ]);
    }

    public function generateStatement(Client $client, ClientPageSummaryService $pageSummary): Response
    {
        $this->authorize('view', $client);

        $client->load([
            'branches' => fn ($query) => $query->orderBy('code'),
        ]);

        $branchIds = $client->branches->pluck('id');

        return Inertia::render('clients/generate-statement', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $pageSummary->forGenerateStatement($client),
            'branches' => $client->branches->map(fn ($branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ]),
            'branchMonthStats' => $this->branchMonthStats($branchIds),
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', [
            'client' => $client,
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client updated successfully.'),
        ]);

        return to_route('clients.show', $client);
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client deleted successfully.'),
        ]);

        return to_route('clients.index');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapBranches(Client $client): array
    {
        return $client->branches->map(fn ($branch): array => [
            'id' => (int) $branch->id,
            'client_id' => (int) $branch->client_id,
            'code' => $branch->code,
            'name' => $branch->name,
            'created_at' => $branch->created_at,
            'updated_at' => $branch->updated_at,
            'statement_entries_count' => $branch->statement_entries_count,
            'total_amount' => StatementAmount::format($branch->total_amount ?? 0),
            'total_amount_value' => (float) ($branch->total_amount ?? 0),
            'last_uploaded_at' => $branch->statement_entries_max_created_at
                ? Carbon::parse($branch->statement_entries_max_created_at)->format('d/m/Y H:i')
                : null,
            'has_statements' => $branch->statement_entries_count > 0,
        ])->all();
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function branchMonthStats(Collection $branchIds): array
    {
        if ($branchIds->isEmpty()) {
            return [];
        }

        $driver = StatementEntry::query()->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            return $this->branchMonthStatsFromDatabase($branchIds);
        }

        return $this->branchMonthStatsInMemory($branchIds);
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function branchMonthStatsFromDatabase(Collection $branchIds): array
    {
        $yearExpression = 'COALESCE(IF(transaction_date IS NULL OR CAST(transaction_date AS CHAR) LIKE \'0000-%\', NULL, YEAR(transaction_date)), statement_year)';
        $monthExpression = 'COALESCE(IF(transaction_date IS NULL OR CAST(transaction_date AS CHAR) LIKE \'0000-%\', NULL, MONTH(transaction_date)), statement_month)';

        try {
            $subquery = StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->selectRaw("branch_id, amount, created_at, {$yearExpression} AS stat_year, {$monthExpression} AS stat_month");

            return DB::query()
                ->fromSub($subquery, 'entries_by_period')
                ->whereNotNull('stat_year')
                ->whereNotNull('stat_month')
                ->where('stat_year', '>', 0)
                ->where('stat_month', '>', 0)
                ->selectRaw('branch_id, stat_year, stat_month, COUNT(*) AS entries_count, SUM(amount) AS total_amount, MAX(created_at) AS last_uploaded_at')
                ->groupBy('branch_id', 'stat_year', 'stat_month')
                ->orderByDesc('stat_year')
                ->orderByDesc('stat_month')
                ->get()
                ->map(function ($row): array {
                    $year = (int) $row->stat_year;
                    $month = (int) $row->stat_month;
                    $totalAmount = (float) $row->total_amount;

                    return [
                        'branch_id' => (int) $row->branch_id,
                        'year' => $year,
                        'month' => $month,
                        'label' => Carbon::create($year, $month, 1)->format('F Y'),
                        'entries_count' => (int) $row->entries_count,
                        'total_amount' => StatementAmount::format($totalAmount),
                        'total_amount_value' => $totalAmount,
                        'last_uploaded_at' => $row->last_uploaded_at
                            ? Carbon::parse($row->last_uploaded_at)->format('d/m/Y H:i')
                            : null,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            return $this->branchMonthStatsInMemory($branchIds);
        }
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function branchMonthStatsInMemory(Collection $branchIds): array
    {
        return StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('transaction_date')
            ->get(['branch_id', 'transaction_date', 'statement_year', 'statement_month', 'amount', 'created_at'])
            ->groupBy(function (StatementEntry $entry): string {
                [$year, $month] = $this->statementEntryPeriod($entry);

                if ($year === null || $month === null) {
                    return '';
                }

                return (int) $entry->branch_id.'-'.$year.'-'.$month;
            })
            ->filter(fn (Collection $group, string $key): bool => $key !== '')
            ->map(function (Collection $group): array {
                /** @var StatementEntry $entry */
                $entry = $group->first();
                [$year, $month] = $this->statementEntryPeriod($entry);
                $year = (int) $year;
                $month = (int) $month;
                $totalAmount = (float) $group->sum('amount');
                $lastUploaded = $group->max('created_at');

                return [
                    'branch_id' => (int) $entry->branch_id,
                    'year' => $year,
                    'month' => $month,
                    'label' => Carbon::create($year, $month, 1)->format('F Y'),
                    'entries_count' => $group->count(),
                    'total_amount' => StatementAmount::format($totalAmount),
                    'total_amount_value' => $totalAmount,
                    'last_uploaded_at' => $lastUploaded
                        ? Carbon::parse($lastUploaded)->format('d/m/Y H:i')
                        : null,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['year'] * 100 + $row['month'])
            ->values()
            ->all();
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function statementEntryPeriod(StatementEntry $entry): array
    {
        $rawDate = $entry->getRawOriginal('transaction_date');
        $hasInvalidRawDate = $rawDate === null
            || $rawDate === ''
            || (is_string($rawDate) && str_starts_with($rawDate, '0000-'));

        if (! $hasInvalidRawDate && $entry->transaction_date !== null) {
            $year = (int) $entry->transaction_date->year;
            $month = (int) $entry->transaction_date->month;

            if ($year > 0 && $month > 0) {
                return [$year, $month];
            }
        }

        if ($entry->statement_year !== null && $entry->statement_month !== null) {
            return [(int) $entry->statement_year, (int) $entry->statement_month];
        }

        return [null, null];
    }
}

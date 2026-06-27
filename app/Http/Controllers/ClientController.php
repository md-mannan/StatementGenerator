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
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->where('user_id', $request->user()->id)
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

        $client = Client::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client created successfully.'),
        ]);

        return to_route('clients.show', $client);
    }

    public function show(Client $client): Response
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
            'summary' => $this->pageSummary->forBranches($client),
            'branchMonthStats' => $this->branchMonthStats($client->branches->pluck('id')),
        ]);
    }

    public function generateStatement(Client $client): Response
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
            'summary' => $this->pageSummary->forGenerateStatement($client),
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
            'id' => $branch->id,
            'client_id' => $branch->client_id,
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

        return StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('transaction_date')
            ->get(['branch_id', 'transaction_date', 'amount', 'created_at'])
            ->groupBy(fn (StatementEntry $entry): string => $entry->branch_id.'-'.$entry->transaction_date->format('Y-n'))
            ->map(function (Collection $group): array {
                /** @var StatementEntry $entry */
                $entry = $group->first();
                $year = $entry->transaction_date->year;
                $month = $entry->transaction_date->month;
                $totalAmount = (float) $group->sum('amount');
                $lastUploaded = $group->max('created_at');

                return [
                    'branch_id' => $entry->branch_id,
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
}

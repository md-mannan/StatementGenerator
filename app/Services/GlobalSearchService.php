<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Models\User;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class GlobalSearchService
{
    private const int LIMIT = 50;

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    public function search(User $user, string $query = ''): array
    {
        $query = trim($query);

        if ($query === '') {
            return array_slice($this->buildNavigationIndex($user), 0, self::LIMIT);
        }

        $normalized = mb_strtolower($query);

        $results = array_merge(
            $this->searchRecords($user, $query),
            $this->filterNavigation($user, $normalized),
        );

        $unique = [];
        $seen = [];

        foreach ($results as $result) {
            if (isset($seen[$result['id']])) {
                continue;
            }

            $seen[$result['id']] = true;
            $unique[] = $result;

            if (count($unique) >= self::LIMIT) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchRecords(User $user, string $query): array
    {
        return array_merge(
            $this->searchUnifiedInvoices($user, $query),
            $this->searchStatementEntries($user, $query),
            $this->searchIncomingStatementEntries($user, $query),
            $this->searchAnnexureEntries($user, $query),
            $this->searchAnnexureCheques($user, $query),
        );
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchUnifiedInvoices(User $user, string $query): array
    {
        /** @var array<string, array{
         *     client_id: int,
         *     client_name: string,
         *     invoice_no: string,
         *     has_branch: bool,
         *     has_received: bool,
         *     has_annexure: bool,
         *     latest_date: string|null,
         *     amount_hint: string|null,
         * }> $groups
         */
        $groups = [];

        StatementEntry::query()
            ->where('user_id', $user->id)
            ->with(['branch.client:id,name'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(30)
            ->get()
            ->each(function (StatementEntry $entry) use (&$groups): void {
                $client = $entry->branch?->client;

                if ($client === null) {
                    return;
                }

                $invoiceNo = trim($entry->invoice_no);
                $key = $client->id.'|'.$invoiceNo;

                $groups[$key] ??= [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'invoice_no' => $invoiceNo,
                    'has_branch' => false,
                    'has_received' => false,
                    'has_annexure' => false,
                    'latest_date' => null,
                    'amount_hint' => null,
                ];

                $groups[$key]['has_branch'] = true;
                $groups[$key]['latest_date'] = StatementDate::format($entry->transaction_date);
                $groups[$key]['amount_hint'] = StatementAmount::format($entry->amount);
            });

        IncomingStatementEntry::query()
            ->where('user_id', $user->id)
            ->with(['client:id,name'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(30)
            ->get()
            ->each(function (IncomingStatementEntry $entry) use (&$groups): void {
                $invoiceNo = trim($entry->invoice_no);
                $key = $entry->client_id.'|'.$invoiceNo;

                $groups[$key] ??= [
                    'client_id' => $entry->client_id,
                    'client_name' => $entry->client->name,
                    'invoice_no' => $invoiceNo,
                    'has_branch' => false,
                    'has_received' => false,
                    'has_annexure' => false,
                    'latest_date' => null,
                    'amount_hint' => null,
                ];

                $groups[$key]['has_received'] = true;
                $groups[$key]['latest_date'] = StatementDate::format($entry->transaction_date);
                $groups[$key]['amount_hint'] = StatementAmount::format($entry->amount);
            });

        ClientAnnexureEntry::query()
            ->where('user_id', $user->id)
            ->with(['client:id,name'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(30)
            ->get()
            ->each(function (ClientAnnexureEntry $entry) use (&$groups): void {
                $invoiceNo = trim($entry->invoice_no);
                $key = $entry->client_id.'|'.$invoiceNo;

                $groups[$key] ??= [
                    'client_id' => $entry->client_id,
                    'client_name' => $entry->client->name,
                    'invoice_no' => $invoiceNo,
                    'has_branch' => false,
                    'has_received' => false,
                    'has_annexure' => false,
                    'latest_date' => null,
                    'amount_hint' => null,
                ];

                $groups[$key]['has_annexure'] = true;
                $groups[$key]['latest_date'] = StatementDate::format($entry->transaction_date);
                $groups[$key]['amount_hint'] = StatementAmount::format($entry->amount);
            });

        return collect($groups)
            ->take(15)
            ->map(function (array $group): array {
                $sources = collect([
                    $group['has_branch'] ? 'branch' : null,
                    $group['has_received'] ? 'received' : null,
                    $group['has_annexure'] ? 'annexure' : null,
                ])->filter()->values();

                $subtitle = collect([
                    $group['client_name'],
                    $sources->count().' source'.($sources->count() === 1 ? '' : 's'),
                    $group['latest_date'],
                    $group['amount_hint'],
                ])->filter()->implode(' · ');

                return $this->item(
                    "invoice-{$group['client_id']}-{$group['invoice_no']}",
                    "Invoice {$group['invoice_no']}",
                    $subtitle,
                    'Invoice Overview',
                    route('clients.invoices.show', [
                        'client' => $group['client_id'],
                        'invoiceNo' => rawurlencode($group['invoice_no']),
                    ]),
                    "{$group['invoice_no']} {$group['client_name']} invoice overview branch received annexure",
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchStatementEntries(User $user, string $query): array
    {
        return StatementEntry::query()
            ->where('user_id', $user->id)
            ->with(['branch.client:id,name', 'branch:id,client_id,code,name'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(15)
            ->get()
            ->map(function (StatementEntry $entry): array {
                $branch = $entry->branch;
                $client = $branch?->client;
                $date = StatementDate::format($entry->transaction_date);
                $amount = StatementAmount::format($entry->amount);

                return $this->item(
                    "statement-entry-{$entry->id}",
                    "Invoice {$entry->invoice_no}",
                    "{$client?->name} · {$branch?->code} · {$date} · {$amount}",
                    'Branch Statement',
                    $this->urlWithQuery(
                        route('branches.statements.index', $branch),
                        [
                            'year' => $entry->transaction_date->year,
                            'month' => $entry->transaction_date->month,
                            'search' => $entry->invoice_no,
                        ],
                    ),
                    "{$entry->invoice_no} {$date} {$amount} {$branch?->code} {$branch?->name} {$client?->name}",
                );
            })
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchIncomingStatementEntries(User $user, string $query): array
    {
        return IncomingStatementEntry::query()
            ->where('user_id', $user->id)
            ->with(['client:id,name', 'branch:id,code,name'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(15)
            ->get()
            ->map(function (IncomingStatementEntry $entry): array {
                $date = StatementDate::format($entry->transaction_date);
                $amount = StatementAmount::format($entry->amount);
                $branchLabel = $entry->branch?->code ?? 'No branch';

                return $this->item(
                    "received-entry-{$entry->id}",
                    "Invoice {$entry->invoice_no}",
                    "{$entry->client->name} · {$branchLabel} · {$date} · {$amount}",
                    'Received Statement',
                    $this->urlWithQuery(
                        route('clients.received-statements.index', $entry->client),
                        [
                            'year' => $entry->transaction_date->year,
                            'month' => $entry->transaction_date->month,
                            'search' => $entry->invoice_no,
                        ],
                    ),
                    "{$entry->invoice_no} {$date} {$amount} received {$entry->client->name} {$branchLabel}",
                );
            })
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchAnnexureEntries(User $user, string $query): array
    {
        return ClientAnnexureEntry::query()
            ->where('user_id', $user->id)
            ->with(['client:id,name', 'branch:id,code,name', 'annexureCheque:id,check_number,year,month'])
            ->where(fn (Builder $builder) => $this->applyEntrySearch($builder, $query, 'transaction_date'))
            ->latest('transaction_date')
            ->limit(15)
            ->get()
            ->map(function (ClientAnnexureEntry $entry): array {
                $date = StatementDate::format($entry->transaction_date);
                $amount = StatementAmount::format($entry->amount);
                $branchLabel = $entry->branch?->code ?? 'No branch';
                $cheque = $entry->annexureCheque;
                $chequeNumber = $cheque?->check_number;
                $chequeYear = $cheque?->year ?? $entry->transaction_date->year;
                $chequeMonth = $cheque?->month ?? $entry->transaction_date->month;

                return $this->item(
                    "annexure-entry-{$entry->id}",
                    "Invoice {$entry->invoice_no}",
                    "{$entry->client->name} · {$branchLabel} · {$date} · {$amount}",
                    'Annexure Invoice',
                    $this->urlWithQuery(
                        route('clients.annexure.index', $entry->client),
                        array_filter([
                            'year' => $chequeYear,
                            'month' => $chequeMonth,
                            'cheque' => $entry->client_annexure_cheque_id,
                            'search' => $entry->invoice_no,
                        ]),
                    ),
                    "{$entry->invoice_no} {$date} {$amount} annexure {$chequeNumber} {$entry->client->name} {$branchLabel}",
                );
            })
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function searchAnnexureCheques(User $user, string $query): array
    {
        return ClientAnnexureCheque::query()
            ->where('user_id', $user->id)
            ->with(['client:id,name'])
            ->where(fn (Builder $builder) => $this->applyChequeSearch($builder, $query))
            ->latest('year')
            ->latest('month')
            ->limit(15)
            ->get()
            ->map(function (ClientAnnexureCheque $cheque): array {
                $amount = StatementAmount::format($cheque->amount);
                $rebate = StatementAmount::format($cheque->rebate);
                $period = Carbon::create($cheque->year, $cheque->month, 1)->format('F Y');
                $chequeDate = $cheque->cheque_date
                    ? StatementDate::format($cheque->cheque_date)
                    : null;
                $title = $cheque->check_number !== ''
                    ? "Cheque {$cheque->check_number}"
                    : 'Annexure cheque';

                return $this->item(
                    "annexure-cheque-{$cheque->id}",
                    $title,
                    "{$cheque->client->name} · {$period} · {$amount}",
                    'Annexure Cheque',
                    $this->urlWithQuery(
                        route('clients.annexure.index', $cheque->client),
                        [
                            'year' => $cheque->year,
                            'month' => $cheque->month,
                            'cheque' => $cheque->id,
                            'search' => $cheque->check_number !== '' ? $cheque->check_number : null,
                        ],
                    ),
                    "{$cheque->check_number} {$amount} {$rebate} {$period} {$chequeDate} cheque annexure {$cheque->client->name}",
                );
            })
            ->all();
    }

    /**
     * @param  Builder<StatementEntry>|Builder<IncomingStatementEntry>|Builder<ClientAnnexureEntry>  $query
     */
    private function applyEntrySearch(Builder $query, string $search, string $dateColumn): void
    {
        $query->where(function (Builder $inner) use ($search, $dateColumn): void {
            $inner->where('invoice_no', 'like', '%'.$search.'%')
                ->orWhere('amount', 'like', '%'.$search.'%');

            $this->applyDateSearch($inner, $search, $dateColumn);

            $inner->orWhereHas('branch', function (Builder $branchQuery) use ($search): void {
                $branchQuery->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        });
    }

    /**
     * @param  Builder<ClientAnnexureCheque>  $query
     */
    private function applyChequeSearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $inner) use ($search): void {
            $inner->where('check_number', 'like', '%'.$search.'%')
                ->orWhere('amount', 'like', '%'.$search.'%')
                ->orWhere('rebate', 'like', '%'.$search.'%');

            $this->applyDateSearch($inner, $search, 'cheque_date');

            if (preg_match('/^\d{4}$/', $search) === 1) {
                $inner->orWhere('year', (int) $search);
            }

            if (preg_match('/^(\d{1,2})[\/\-](\d{4})$/', $search, $matches) === 1) {
                $inner->orWhere(function (Builder $periodQuery) use ($matches): void {
                    $periodQuery->where('month', (int) $matches[1])
                        ->where('year', (int) $matches[2]);
                });
            }
        });
    }

    /**
     * @param  Builder<StatementEntry>|Builder<IncomingStatementEntry>|Builder<ClientAnnexureEntry>|Builder<ClientAnnexureCheque>  $query
     */
    private function applyDateSearch(Builder $query, string $search, string $dateColumn): void
    {
        $parsedDate = StatementDate::parse($search);

        if ($parsedDate) {
            $query->orWhereDate($dateColumn, $parsedDate);
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{4})$/', $search, $matches) === 1) {
            $query->orWhere(function (Builder $periodQuery) use ($dateColumn, $matches): void {
                $periodQuery->whereMonth($dateColumn, (int) $matches[1])
                    ->whereYear($dateColumn, (int) $matches[2]);
            });
        }
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function filterNavigation(User $user, string $normalized): array
    {
        return array_values(array_filter(
            $this->buildNavigationIndex($user),
            function (array $item) use ($normalized): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $item['title'],
                    $item['subtitle'] ?? '',
                    $item['group'],
                    $item['keywords'],
                ]));

                return str_contains($haystack, $normalized);
            },
        ));
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function buildNavigationIndex(User $user): array
    {
        return Cache::remember(
            "global-search.nav.{$user->id}",
            now()->addMinutes(10),
            fn (): array => $this->buildNavigationIndexForUser($user),
        );
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}>
     */
    private function buildNavigationIndexForUser(User $user): array
    {
        $items = [
            $this->item('dashboard', 'Dashboard', null, 'Pages', route('dashboard'), 'home overview stats'),
            $this->item('clients', 'Clients', null, 'Pages', route('clients.index'), 'clients list'),
            $this->item('clients-create', 'Add Client', null, 'Pages', route('clients.create'), 'new client create'),
            $this->item('settings-profile', 'Profile', null, 'Settings', route('profile.edit'), 'account profile settings'),
            $this->item('settings-security', 'Security', null, 'Settings', route('security.edit'), 'password security'),
            $this->item('settings-appearance', 'Appearance', null, 'Settings', route('appearance.edit'), 'theme dark light appearance'),
            $this->item('settings-data', 'Database backup', null, 'Settings', route('data.edit'), 'database backup restore export wipe sql mysqldump clear data'),
        ];

        $clients = Client::query()
            ->where('user_id', $user->id)
            ->with(['branches:id,client_id,code,name'])
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($clients as $client) {
            $clientName = $client->name;

            $items[] = $this->item(
                "client-{$client->id}-branches",
                'Branches',
                $clientName,
                'Client',
                route('clients.show', $client),
                "{$clientName} branches"
            );
            $items[] = $this->item(
                "client-{$client->id}-generate",
                'Generate Statement',
                $clientName,
                'Client',
                route('clients.generate-statement', $client),
                "{$clientName} generate statement export"
            );
            $items[] = $this->item(
                "client-{$client->id}-received",
                'Received Statements',
                $clientName,
                'Client',
                route('clients.received-statements.index', $client),
                "{$clientName} received statements incoming"
            );
            $items[] = $this->item(
                "client-{$client->id}-annexure",
                'Client Annexure',
                $clientName,
                'Client',
                route('clients.annexure.index', $client),
                "{$clientName} annexure cheques"
            );
            $items[] = $this->item(
                "client-{$client->id}-cross-check",
                'All Invoices',
                $clientName,
                'Client',
                route('clients.cross-check.index', $client),
                "{$clientName} invoices cross check reconciliation overview"
            );
            $items[] = $this->item(
                "client-{$client->id}-edit",
                'Edit Client',
                $clientName,
                'Client',
                route('clients.edit', $client),
                "{$clientName} edit rename"
            );

            foreach ($client->branches as $branch) {
                $items[] = $this->item(
                    "branch-{$branch->id}-statements",
                    $branch->name,
                    "{$clientName} · {$branch->code}",
                    'Branch',
                    route('branches.statements.index', $branch),
                    "{$branch->code} {$branch->name} {$clientName} statements entries"
                );
            }
        }

        return $items;
    }

    /**
     * @param  array<string, int|string|null>  $query
     */
    private function urlWithQuery(string $url, array $query = []): string
    {
        $filtered = array_filter(
            $query,
            fn (int|string|null $value): bool => $value !== null && $value !== '',
        );

        if ($filtered === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($filtered);
    }

    /**
     * @return array{id: string, title: string, subtitle: string|null, group: string, href: string, keywords: string}
     */
    private function item(string $id, string $title, ?string $subtitle, string $group, string $href, string $keywords): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'group' => $group,
            'href' => $href,
            'keywords' => $keywords,
        ];
    }
}

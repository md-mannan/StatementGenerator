<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Received Statement - {{ $periodLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        td.amount, th.amount { text-align: right; }
        tfoot td { background: #f9f9f9; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $client->name }}</h1>
    <div class="meta">Received statement for <strong>{{ $periodLabel }}</strong></div>

    <table>
        <thead>
            <tr>
                <th>Sl</th>
                <th>Date</th>
                <th>Branch ID</th>
                <th>Invoice No</th>
                <th class="amount">Amount</th>
                <th class="amount">Branch Amount</th>
                <th class="amount">Difference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $index => $entry)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $entry['transaction_date'] }}</td>
                    <td>{{ $entry['branch_code'] ?? '—' }}</td>
                    <td>{{ $entry['invoice_no'] }}</td>
                    <td class="amount">{{ $entry['amount'] }}</td>
                    <td class="amount">{{ $entry['branch_amount'] ?? '—' }}</td>
                    <td class="amount">{{ $entry['difference_amount'] ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No entries for this period.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($entries->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td class="amount">{{ \App\Support\StatementAmount::format($total) }}</td>
                    <td class="amount">{{ \App\Support\StatementAmount::format($branchStatementTotal) }}</td>
                    <td class="amount">{{ \App\Support\StatementAmount::format($totalDifference) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>

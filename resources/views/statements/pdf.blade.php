<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement - {{ $periodLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 0; color: #555; font-weight: normal; }
        .meta { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        td.amount, th.amount { text-align: right; }
        .total { margin-top: 16px; font-weight: bold; text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $client->name }}</h1>
    <h2>{{ $branch->code }} — {{ $branch->name }}</h2>
    <div class="meta">Statement for <strong>{{ $periodLabel }}</strong></div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice No</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ \App\Support\StatementDate::format($entry->transaction_date) }}</td>
                    <td>{{ $entry->invoice_no }}</td>
                    <td class="amount">{{ \App\Support\StatementAmount::format($entry->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">No entries for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="total">Total: {{ \App\Support\StatementAmount::format($total) }}</div>
</body>
</html>

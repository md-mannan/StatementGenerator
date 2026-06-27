<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement - {{ $periodLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 12px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 0 0 8px; color: #555; font-weight: normal; }
        .meta { margin-bottom: 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; }
        th { background: #eee; font-weight: bold; }
        td.num { text-align: right; }
        tfoot td { background: #f5f5f5; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $clientName }}</h1>
    <h2>{{ $branchLabel }}</h2>
    <div class="meta">Statement for <strong>{{ $periodLabel }}</strong></div>

    <table>
        <thead>
            <tr>
                <th>Sl</th>
                @if ($multipleBranches)
                    <th>Branch ID</th>
                    <th>Branch Name</th>
                @endif
                <th>Invoice Date</th>
                <th>Invoice No</th>
                <th class="num">Branch Amount</th>
                <th class="num">Client Amount</th>
                <th class="num">Client Diff</th>
                <th>Cheque No</th>
                <th class="num">Cheque Received</th>
                <th class="num">Cheque Diff</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}">No entries for this period.</td>
                </tr>
            @endforelse
        </tbody>
        @if (count($rows) > 0)
            <tfoot>
                <tr>
                    <td colspan="{{ $totalLabelSpan }}">Total</td>
                    <td class="num">{{ $branchTotal }}</td>
                    <td class="num">{{ $clientStatementTotal }}</td>
                    <td class="num">{{ $clientDifferenceTotal }}</td>
                    <td></td>
                    <td class="num">{{ $chequeReceivedTotal }}</td>
                    <td class="num">{{ $differenceTotal }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>

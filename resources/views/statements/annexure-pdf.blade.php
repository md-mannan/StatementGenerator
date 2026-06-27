<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Annexure - {{ $periodLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 24px 0 8px; }
        .meta { margin-bottom: 16px; }
        .summary { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .summary td { padding: 6px 8px; border: 1px solid #ddd; }
        .summary td.label { width: 45%; background: #f9f9f9; font-weight: bold; }
        .summary td.value { text-align: right; font-family: monospace; }
        table.entries { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.entries th, table.entries td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        table.entries th { background: #f5f5f5; }
        table.entries td.amount, table.entries th.amount { text-align: right; }
        table.entries tfoot td { background: #f9f9f9; font-weight: bold; }
        table.checks { width: 50%; border-collapse: collapse; margin-top: 8px; }
        table.checks th, table.checks td { border: 1px solid #ddd; padding: 8px; }
        table.checks th { background: #f5f5f5; }
        table.checks td.amount { text-align: right; font-family: monospace; }
        .notes { margin-top: 16px; color: #444; }
    </style>
</head>
<body>
    <h1>{{ $client->name }}</h1>
    <div class="meta">Client Annexure for <strong>{{ $periodLabel }}</strong></div>

    <table class="summary">
        <tr>
            <td class="label">Client Statement Total</td>
            <td class="value">{{ $clientTotal }}</td>
        </tr>
        <tr>
            <td class="label">Branch Statement Total</td>
            <td class="value">{{ $branchTotal }}</td>
        </tr>
        <tr>
            <td class="label">Difference</td>
            <td class="value">{{ $differenceTotal }}</td>
        </tr>
        <tr>
            <td class="label">Rebate (Deduction)</td>
            <td class="value">{{ $rebate }}</td>
        </tr>
        <tr>
            <td class="label">Net Amount (Payable)</td>
            <td class="value">{{ $netAmount }}</td>
        </tr>
    </table>

    <h2>Payment Checks</h2>
    <table class="checks">
        <thead>
            <tr>
                <th>Check Number</th>
                <th class="amount">Check Total</th>
                <th class="amount">Rebate</th>
                <th class="amount">Net</th>
            </tr>
        </thead>
        <tbody>
            @php
                $visibleChecks = collect($paymentChecks)->filter(
                    fn ($check) => $check['check_number'] !== '' || ($check['amount_value'] ?? 0) > 0
                );
                $checkCount = max($visibleChecks->count(), 1);
                $rebatePerCheck = $checkCount > 0 ? (float) str_replace(',', '', $rebate) / $checkCount : 0;
            @endphp
            @forelse ($visibleChecks as $check)
                @php
                    $checkAmount = $check['amount_value'] ?? 0;
                    $checkNet = $checkAmount - $rebatePerCheck;
                @endphp
                <tr>
                    <td>{{ $check['check_number'] !== '' ? $check['check_number'] : '-' }}</td>
                    <td class="amount">{{ $check['amount'] }}</td>
                    <td class="amount">{{ number_format($rebatePerCheck, 3, '.', ',') }}</td>
                    <td class="amount">{{ number_format($checkNet, 3, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No check numbers recorded.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($visibleChecks->isNotEmpty())
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="amount"><strong>{{ $checkTotal }}</strong></td>
                <td class="amount"><strong>{{ $rebate }}</strong></td>
                <td class="amount"><strong>{{ $netAmount }}</strong></td>
            </tr>
        </tfoot>
        @endif
    </table>

    <h2>Invoice Cross-Check</h2>
    <table class="entries">
        <thead>
            <tr>
                <th>Sl</th>
                <th>Date</th>
                <th>Branch ID</th>
                <th>Cheque No</th>
                <th>Invoice No</th>
                <th class="amount">Client Amount</th>
                <th class="amount">Branch Amount</th>
                <th class="amount">Difference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $index => $entry)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $entry['transaction_date'] }}</td>
                    <td>{{ $entry['branch_code'] ?? '-' }}</td>
                    <td>{{ $entry['cheque_number'] ?? '-' }}</td>
                    <td>{{ $entry['invoice_no'] }}</td>
                    <td class="amount">{{ $entry['amount'] }}</td>
                    <td class="amount">{{ $entry['branch_amount'] ?? '-' }}</td>
                    <td class="amount">{{ $entry['difference_amount'] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No entries for this period.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($entries->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="5">Total</td>
                    <td class="amount">{{ $clientTotal }}</td>
                    <td class="amount">{{ $branchTotal }}</td>
                    <td class="amount">{{ $differenceTotal }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>

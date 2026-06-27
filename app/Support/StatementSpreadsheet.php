<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StatementSpreadsheet
{
    /**
     * @param  Collection<int|string, mixed>  $row
     * @param  list<string>  $keys
     */
    public static function extractValue(Collection $row, array $keys): mixed
    {
        $normalized = self::normalizeRow($row);

        foreach ($keys as $key) {
            foreach (self::candidateKeys($key) as $candidate) {
                if (! $normalized->has($candidate)) {
                    continue;
                }

                $value = $normalized->get($candidate);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int|string, mixed>  $row
     * @return array{transaction_date: string, invoice_no: string, amount: float}|null
     */
    public static function parseEntryRow(Collection $row): ?array
    {
        $date = self::extractValue($row, ['date', 'transaction_date']);
        $invoiceNo = self::extractValue($row, [
            'invoice_no',
            'invoice',
            'invoice_number',
            'inv_no',
            'inv',
        ]);
        $amount = self::extractValue($row, [
            'amount',
            'client_amount',
            'total',
            'value',
        ]);

        if ($date === null || $invoiceNo === null || $amount === null) {
            return null;
        }

        if (in_array(strtolower(trim((string) $invoiceNo)), ['total', 'invoice', 'invoice no'], true)) {
            return null;
        }

        $parsedDate = StatementDate::parse($date);

        if ($parsedDate === null) {
            return null;
        }

        $parsedAmount = StatementAmount::parse($amount);

        if ($parsedAmount === null) {
            return null;
        }

        return [
            'transaction_date' => $parsedDate->toDateString(),
            'invoice_no' => trim((string) $invoiceNo),
            'amount' => $parsedAmount,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $row
     * @return Collection<string, mixed>
     */
    public static function normalizeRow(Collection $row): Collection
    {
        return $row->mapWithKeys(function ($value, $key): array {
            $normalized = Str::slug(str_replace('.', '', (string) $key), '_');

            return [$normalized => $value];
        });
    }

    /**
     * @return list<string>
     */
    private static function candidateKeys(string $key): array
    {
        $slug = Str::slug(str_replace('.', '', $key), '_');

        return array_values(array_unique([
            $key,
            $slug,
            str_replace('_', '', $slug),
        ]));
    }
}

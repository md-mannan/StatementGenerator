<?php

namespace App\Exports;

use App\Models\Branch;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlyStatementExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, \App\Models\StatementEntry>  $entries
     */
    public function __construct(
        private readonly Branch $branch,
        private readonly Collection $entries,
        private readonly int $year,
        private readonly int $month,
        private readonly float $total,
    ) {}

    public function collection(): Collection
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Date', 'Invoice No', 'Amount'];
    }

    /**
     * @param  \App\Models\StatementEntry  $entry
     * @return list<mixed>
     */
    public function map($entry): array
    {
        return [
            StatementDate::format($entry->transaction_date),
            $entry->invoice_no,
            StatementAmount::format($entry->amount),
        ];
    }

    public function title(): string
    {
        return sprintf('%s-%02d', $this->year, $this->month);
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->entries->count() + 2;

        return [
            1 => ['font' => ['bold' => true]],
            $lastRow + 1 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [];
    }
}

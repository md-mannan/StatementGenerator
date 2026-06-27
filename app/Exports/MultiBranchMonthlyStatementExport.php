<?php

namespace App\Exports;

use App\Models\Client;
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

class MultiBranchMonthlyStatementExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, \App\Models\StatementEntry>  $entries
     */
    public function __construct(
        private readonly Client $client,
        private readonly Collection $entries,
        private readonly string $periodLabel,
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
        return ['Sl', 'Branch ID', 'Date', 'Invoice No', 'Amount'];
    }

    /**
     * @param  \App\Models\StatementEntry  $entry
     * @return list<mixed>
     */
    public function map($entry): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $entry->branch->code,
            StatementDate::format($entry->transaction_date),
            $entry->invoice_no,
            StatementAmount::format($entry->amount),
        ];
    }

    public function title(): string
    {
        return str($this->periodLabel)->substr(0, 31)->toString();
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->entries->count() + 2;

        return [
            1 => ['font' => ['bold' => true]],
            $lastRow + 1 => ['font' => ['bold' => true]],
        ];
    }
}

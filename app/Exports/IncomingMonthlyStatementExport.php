<?php

namespace App\Exports;

use App\Support\StatementAmount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class IncomingMonthlyStatementExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function __construct(
        private readonly Collection $entries,
        private readonly int $year,
        private readonly int $month,
        private readonly float $total,
        private readonly float $branchTotal,
        private readonly float $totalDifference,
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
        return [
            'Sl',
            'Date',
            'Branch ID',
            'Invoice No',
            'Amount',
            'Branch Amount',
            'Difference',
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<mixed>
     */
    public function map($entry): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $entry['transaction_date'],
            $entry['branch_code'] ?? '',
            $entry['invoice_no'],
            $entry['amount'],
            $entry['branch_amount'] ?? '',
            $entry['difference_amount'] ?? '',
        ];
    }

    public function title(): string
    {
        return sprintf('%s-%02d', $this->year, $this->month);
    }

    public function styles(Worksheet $sheet): array
    {
        $totalRow = $this->entries->count() + 2;

        return [
            1 => ['font' => ['bold' => true]],
            $totalRow => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $totalRow = $this->entries->count() + 2;
                $sheet = $event->sheet->getDelegate();

                $sheet->setCellValue("A{$totalRow}", 'Total');
                $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
                $sheet->setCellValue("E{$totalRow}", StatementAmount::format($this->total));
                $sheet->setCellValue("F{$totalRow}", StatementAmount::format($this->branchTotal));
                $sheet->setCellValue("G{$totalRow}", StatementAmount::format($this->totalDifference));
            },
        ];
    }
}

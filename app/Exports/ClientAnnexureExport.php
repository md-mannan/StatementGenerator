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

class ClientAnnexureExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  list<array{check_number: string, amount: string, amount_value: float}>  $paymentChecks
     */
    public function __construct(
        private readonly Collection $entries,
        private readonly int $year,
        private readonly int $month,
        private readonly float $clientTotal,
        private readonly float $branchTotal,
        private readonly float $differenceTotal,
        private readonly float $rebate,
        private readonly float $netCollected,
        private readonly array $paymentChecks,
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
            'Cheque No',
            'Invoice No',
            'Client Amount',
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
            $entry['cheque_number'] ?? '',
            $entry['invoice_no'],
            $entry['amount'],
            $entry['branch_amount'] ?? '',
            $entry['difference_amount'] ?? '',
        ];
    }

    public function title(): string
    {
        return sprintf('Annexure-%s-%02d', $this->year, $this->month);
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
                $sheet = $event->sheet->getDelegate();
                $totalRow = $this->entries->count() + 2;
                $summaryRow = $totalRow + 2;

                $sheet->setCellValue("A{$totalRow}", 'Total');
                $sheet->mergeCells("A{$totalRow}:E{$totalRow}");
                $sheet->setCellValue("F{$totalRow}", StatementAmount::format($this->clientTotal));
                $sheet->setCellValue("G{$totalRow}", StatementAmount::format($this->branchTotal));
                $sheet->setCellValue("H{$totalRow}", StatementAmount::format($this->differenceTotal));

                $sheet->setCellValue("A{$summaryRow}", 'Rebate (Deduction)');
                $sheet->setCellValue("F{$summaryRow}", StatementAmount::format($this->rebate));

                $sheet->setCellValue('A'.($summaryRow + 1), 'Net Collected');
                $sheet->setCellValue('F'.($summaryRow + 1), StatementAmount::format($this->netCollected));

                $checkRow = $summaryRow + 3;
                $sheet->setCellValue("A{$checkRow}", 'Check Number');
                $sheet->setCellValue("F{$checkRow}", 'Amount');
                $sheet->getStyle("A{$checkRow}:F{$checkRow}")->getFont()->setBold(true);

                foreach ($this->paymentChecks as $index => $check) {
                    $row = $checkRow + 1 + $index;

                    if ($check['check_number'] === '' && $check['amount_value'] <= 0) {
                        continue;
                    }

                    $sheet->setCellValue("A{$row}", $check['check_number']);
                    $sheet->setCellValue("F{$row}", $check['amount']);
                }
            },
        ];
    }
}

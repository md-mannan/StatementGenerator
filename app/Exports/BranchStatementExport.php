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

class BranchStatementExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function __construct(
        private readonly Collection $entries,
        private readonly string $periodLabel,
        private readonly float $branchTotal,
        private readonly float $clientStatementTotal,
        private readonly float $clientDifferenceTotal,
        private readonly float $chequeReceivedTotal,
        private readonly float $differenceTotal,
        private readonly bool $includeBranchColumns,
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
        $headings = ['Sl'];

        if ($this->includeBranchColumns) {
            $headings[] = 'Branch ID';
            $headings[] = 'Branch Name';
        }

        return [
            ...$headings,
            'Invoice Date',
            'Invoice No',
            'Branch Amount',
            'Client Amount',
            'Client Diff',
            'Cheque No',
            'Cheque Received',
            'Cheque Diff',
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<mixed>
     */
    public function map($entry): array
    {
        $this->rowNumber++;

        $row = [$this->rowNumber];

        if ($this->includeBranchColumns) {
            $row[] = $entry['branch_code'] ?? '';
            $row[] = $entry['branch_name'] ?? '';
        }

        return [
            ...$row,
            $entry['transaction_date'],
            $entry['invoice_no'],
            $entry['amount'],
            $entry['client_statement_amount'] ?? '',
            $entry['client_difference_amount'] ?? '',
            $entry['cheque_number'] ?? '',
            $entry['cheque_received_amount'] ?? '',
            $entry['difference_amount'] ?? '',
        ];
    }

    public function title(): string
    {
        return str($this->periodLabel)->substr(0, 31)->toString();
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                if ($this->entries->isEmpty()) {
                    return;
                }

                $sheet = $event->sheet->getDelegate();
                $totalRow = $this->entries->count() + 2;
                $offset = $this->includeBranchColumns ? 2 : 0;
                $mergeEnd = chr(ord('C') + $offset);
                $branchAmountColumn = chr(ord('D') + $offset);
                $clientAmountColumn = chr(ord('E') + $offset);
                $clientDiffColumn = chr(ord('F') + $offset);
                $chequeReceivedColumn = chr(ord('H') + $offset);
                $chequeDiffColumn = chr(ord('I') + $offset);

                $sheet->setCellValue("A{$totalRow}", 'Total');
                $sheet->mergeCells("A{$totalRow}:{$mergeEnd}{$totalRow}");
                $sheet->setCellValue("{$branchAmountColumn}{$totalRow}", StatementAmount::format($this->branchTotal));
                $sheet->setCellValue("{$clientAmountColumn}{$totalRow}", StatementAmount::format($this->clientStatementTotal));
                $sheet->setCellValue("{$clientDiffColumn}{$totalRow}", StatementAmount::format($this->clientDifferenceTotal));
                $sheet->setCellValue("{$chequeReceivedColumn}{$totalRow}", StatementAmount::format($this->chequeReceivedTotal));
                $sheet->setCellValue("{$chequeDiffColumn}{$totalRow}", StatementAmount::format($this->differenceTotal));
                $sheet->getStyle("A{$totalRow}:{$chequeDiffColumn}{$totalRow}")->getFont()->setBold(true);
            },
        ];
    }
}

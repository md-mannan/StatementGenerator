<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class StatementPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function download(
        string $view,
        array $data,
        string $filename,
        int $rowCount = 0,
    ): Response {
        self::raiseMemoryLimit($rowCount);

        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => false,
            'isRemoteEnabled' => false,
            'enable_font_subsetting' => true,
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans',
        ]);

        return $pdf->download($filename);
    }

    public static function raiseMemoryLimit(int $rowCount): void
    {
        $memoryMb = max(256, min(1024, 128 + (int) ceil($rowCount * 0.25)));

        ini_set('memory_limit', $memoryMb.'M');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<list<string>>
     */
    public static function branchStatementRows(
        Collection $entries,
        bool $multipleBranches,
    ): array {
        $rows = [];
        $index = 0;

        foreach ($entries as $entry) {
            $index++;

            $row = [(string) $index];

            if ($multipleBranches) {
                $row[] = (string) ($entry['branch_code'] ?? '-');
                $row[] = (string) ($entry['branch_name'] ?? '-');
            }

            $rows[] = [
                ...$row,
                (string) $entry['transaction_date'],
                (string) $entry['invoice_no'],
                (string) $entry['amount'],
                (string) ($entry['client_statement_amount'] ?? '-'),
                (string) ($entry['client_difference_amount'] ?? '-'),
                (string) ($entry['cheque_number'] ?? '-'),
                (string) ($entry['cheque_received_amount'] ?? '-'),
                (string) ($entry['difference_amount'] ?? '-'),
            ];
        }

        return $rows;
    }
}

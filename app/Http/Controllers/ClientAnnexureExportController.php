<?php

namespace App\Http\Controllers;

use App\Exports\ClientAnnexureExport;
use App\Models\Client;
use App\Services\ClientAnnexureService;
use App\Support\StatementAmount;
use App\Support\StatementPdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ClientAnnexureExportController extends Controller
{
    public function __construct(
        private readonly ClientAnnexureService $annexureService,
    ) {}

    public function excel(Request $request, Client $client): BinaryFileResponse
    {
        $this->authorize('view', $client);

        $payload = $this->buildExportPayload($request, $client);

        $filename = sprintf(
            'client-annexure-%s-%s.xlsx',
            str($client->name)->slug(),
            str($payload['periodLabel'])->slug(),
        );

        return Excel::download(
            new ClientAnnexureExport(
                $payload['entries'],
                $payload['year'],
                $payload['month'],
                $payload['clientTotal'],
                $payload['branchTotal'],
                $payload['differenceTotal'],
                $payload['rebate'],
                $payload['netAmount'],
                $payload['paymentChecks'],
            ),
            $filename,
        );
    }

    public function pdf(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $payload = $this->buildExportPayload($request, $client);

        return StatementPdf::download(
            'statements.annexure-pdf',
            [
                'client' => $client,
                'entries' => $payload['entries'],
                'clientTotal' => StatementAmount::format($payload['clientTotal']),
                'branchTotal' => StatementAmount::format($payload['branchTotal']),
                'differenceTotal' => StatementAmount::format($payload['differenceTotal']),
                'rebate' => StatementAmount::format($payload['rebate']),
                'netAmount' => StatementAmount::format($payload['netAmount']),
                'checkTotal' => StatementAmount::format($payload['checkTotal']),
                'paymentChecks' => $payload['paymentChecks'],
                'periodLabel' => $payload['periodLabel'],
            ],
            sprintf(
                'client-annexure-%s-%s.pdf',
                str($client->name)->slug(),
                str($payload['periodLabel'])->slug(),
            ),
            $payload['entries']->count(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExportPayload(Request $request, Client $client): array
    {
        $resolved = $this->annexureService->resolve($request, $client);

        if ($resolved['selectedChequeIds']->isEmpty() && $resolved['cheques']->isNotEmpty()) {
            $resolved = $this->annexureService->resolve(
                $request->merge(['cheque_ids' => $resolved['cheques']->pluck('id')->all()]),
                $client,
            );
        }

        return [
            'year' => $resolved['year'],
            'month' => $resolved['month'],
            'periodLabel' => $resolved['periodLabel'],
            'entries' => $resolved['entries'],
            'clientTotal' => $resolved['clientTotal'],
            'branchTotal' => $resolved['branchTotal'],
            'differenceTotal' => $resolved['differenceTotal'],
            'rebate' => $resolved['rebate'],
            'netAmount' => $resolved['netAmount'],
            'checkTotal' => $resolved['checkTotal'],
            'paymentChecks' => $resolved['cheques']
                ->when(
                    $resolved['selectedChequeIds']->isNotEmpty(),
                    fn ($cheques) => $cheques->whereIn('id', $resolved['selectedChequeIds']->all()),
                )
                ->map(fn (array $cheque): array => [
                    'check_number' => $cheque['check_number'],
                    'amount' => $cheque['amount'],
                    'amount_value' => $cheque['amount_value'],
                ])
                ->values()
                ->all(),
        ];
    }
}

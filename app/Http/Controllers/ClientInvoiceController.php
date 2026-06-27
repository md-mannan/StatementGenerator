<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientPageSummaryService;
use App\Services\InvoiceDetailService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientInvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceDetailService $invoiceDetailService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function show(Request $request, Client $client, string $invoiceNo): Response
    {
        $this->authorize('view', $client);

        $detail = $this->invoiceDetailService->resolve(
            $client,
            urldecode($invoiceNo),
        );

        if ($detail === null) {
            abort(404);
        }

        return Inertia::render('clients/invoices/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forInvoiceDetail($detail),
            'invoice' => $detail,
        ]);
    }
}

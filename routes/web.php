<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClientAnnexureChequeController;
use App\Http\Controllers\ClientAnnexureController;
use App\Http\Controllers\ClientAnnexureEntryController;
use App\Http\Controllers\ClientAnnexureExportController;
use App\Http\Controllers\ClientAnnexureImportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientInvoiceController;
use App\Http\Controllers\ClientStatementController;
use App\Http\Controllers\CrossCheckController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\IncomingStatementController;
use App\Http\Controllers\IncomingStatementEntryController;
use App\Http\Controllers\IncomingStatementExportController;
use App\Http\Controllers\IncomingStatementImportController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\StatementEntryController;
use App\Http\Controllers\StatementExportController;
use App\Http\Controllers\StatementImportController;
use App\Http\Controllers\StatementInvoiceScanController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::bind('client', function (string $value) {
        return auth()->user()
            ->clients()
            ->whereKey($value)
            ->firstOrFail();
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('search', GlobalSearchController::class)->name('search');

    Route::get('clients/{client}/generate-statement', [ClientController::class, 'generateStatement'])
        ->name('clients.generate-statement');

    Route::get('clients/{client}/generate-statement/export/excel', [StatementExportController::class, 'clientExcel'])
        ->name('clients.generate-statement.export.excel');

    Route::get('clients/{client}/generate-statement/export/pdf', [StatementExportController::class, 'clientPdf'])
        ->name('clients.generate-statement.export.pdf');

    Route::get('clients/{client}/statement', [ClientStatementController::class, 'show'])
        ->name('clients.statement.show');

    Route::get('clients/{client}/received-statements', [IncomingStatementController::class, 'index'])
        ->name('clients.received-statements.index');

    Route::get('clients/{client}/received-statements/import', [IncomingStatementImportController::class, 'create'])
        ->name('clients.received-statements.import');

    Route::post('clients/{client}/received-statements/import', [IncomingStatementImportController::class, 'store'])
        ->name('clients.received-statements.import.store');

    Route::get('clients/{client}/received-statements/export/excel', [IncomingStatementExportController::class, 'excel'])
        ->name('clients.received-statements.export.excel');

    Route::get('clients/{client}/received-statements/export/pdf', [IncomingStatementExportController::class, 'pdf'])
        ->name('clients.received-statements.export.pdf');

    Route::post('clients/{client}/received-statements/entries', [IncomingStatementEntryController::class, 'bulkStore'])
        ->name('clients.received-statements.entries.bulk-store');

    Route::delete('clients/{client}/received-statements/entries', [IncomingStatementEntryController::class, 'bulkDestroy'])
        ->name('clients.received-statements.entries.bulk-destroy');

    Route::put('incoming-statement-entries/{incomingStatementEntry}', [IncomingStatementEntryController::class, 'update'])
        ->name('incoming-statement-entries.update');

    Route::patch('incoming-statement-entries/{incomingStatementEntry}/no-branch-expected', [IncomingStatementEntryController::class, 'updateNoBranchExpected'])
        ->name('incoming-statement-entries.no-branch-expected');

    Route::delete('incoming-statement-entries/{incomingStatementEntry}', [IncomingStatementEntryController::class, 'destroy'])
        ->name('incoming-statement-entries.destroy');

    Route::get('clients/{client}/annexure', [ClientAnnexureController::class, 'index'])
        ->name('clients.annexure.index');

    Route::get('clients/{client}/annexure/import', [ClientAnnexureImportController::class, 'create'])
        ->name('clients.annexure.import');

    Route::post('clients/{client}/annexure/import', [ClientAnnexureImportController::class, 'store'])
        ->name('clients.annexure.import.store');

    Route::post('client-annexure-cheques/{clientAnnexureCheque}/complete-review', [ClientAnnexureChequeController::class, 'completeReview'])
        ->name('client-annexure-cheques.complete-review');

    Route::put('client-annexure-cheques/{clientAnnexureCheque}', [ClientAnnexureChequeController::class, 'update'])
        ->name('client-annexure-cheques.update');

    Route::delete('client-annexure-cheques/{clientAnnexureCheque}', [ClientAnnexureChequeController::class, 'destroy'])
        ->name('client-annexure-cheques.destroy');

    Route::post('clients/{client}/annexure/entries', [ClientAnnexureEntryController::class, 'bulkStore'])
        ->name('clients.annexure.entries.bulk-store');

    Route::put('client-annexure-entries/{clientAnnexureEntry}', [ClientAnnexureEntryController::class, 'update'])
        ->name('client-annexure-entries.update');

    Route::patch('client-annexure-entries/{clientAnnexureEntry}/no-branch-expected', [ClientAnnexureEntryController::class, 'updateNoBranchExpected'])
        ->name('client-annexure-entries.no-branch-expected');

    Route::delete('client-annexure-entries/{clientAnnexureEntry}', [ClientAnnexureEntryController::class, 'destroy'])
        ->name('client-annexure-entries.destroy');

    Route::get('clients/{client}/annexure/export/excel', [ClientAnnexureExportController::class, 'excel'])
        ->name('clients.annexure.export.excel');

    Route::get('clients/{client}/annexure/export/pdf', [ClientAnnexureExportController::class, 'pdf'])
        ->name('clients.annexure.export.pdf');

    Route::get('clients/{client}/cross-check', [CrossCheckController::class, 'index'])
        ->name('clients.cross-check.index');

    Route::get('clients/{client}/invoices/{invoiceNo}', [ClientInvoiceController::class, 'show'])
        ->where('invoiceNo', '.*')
        ->name('clients.invoices.show');

    Route::resource('clients', ClientController::class);

    Route::post('clients/{client}/branches', [BranchController::class, 'store'])
        ->name('clients.branches.store');

    Route::put('branches/{branch}', [BranchController::class, 'update'])
        ->name('branches.update');

    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])
        ->name('branches.destroy');

    Route::get('branches/{branch}/statements', [StatementController::class, 'index'])
        ->name('branches.statements.index');

    Route::post('branches/{branch}/statement-entries', [StatementEntryController::class, 'store'])
        ->name('branches.statement-entries.store');

    Route::post('branches/{branch}/statement-entries/bulk', [StatementEntryController::class, 'bulkStore'])
        ->name('branches.statement-entries.bulk-store');

    Route::delete('branches/{branch}/statement-entries', [StatementEntryController::class, 'bulkDestroy'])
        ->name('branches.statement-entries.bulk-destroy');

    Route::get('branches/{branch}/statements/import', [StatementImportController::class, 'create'])
        ->name('branches.statements.import');

    Route::post('branches/{branch}/statements/import', [StatementImportController::class, 'store'])
        ->name('branches.statements.import.store');

    Route::get('branches/{branch}/statements/export/excel', [StatementExportController::class, 'excel'])
        ->name('branches.statements.export.excel');

    Route::get('branches/{branch}/statements/export/pdf', [StatementExportController::class, 'pdf'])
        ->name('branches.statements.export.pdf');

    Route::put('statement-entries/{statementEntry}', [StatementEntryController::class, 'update'])
        ->name('statement-entries.update');

    Route::patch('statement-entries/{statementEntry}/no-bill-expected', [StatementEntryController::class, 'updateNoBillExpected'])
        ->name('statement-entries.no-bill-expected');

    Route::post('statement-entries/{statementEntry}/invoice-scan', [StatementInvoiceScanController::class, 'store'])
        ->name('statement-entries.invoice-scan.store');

    Route::get('statement-entries/{statementEntry}/invoice-scan', [StatementInvoiceScanController::class, 'show'])
        ->name('statement-entries.invoice-scan.show');

    Route::delete('statement-entries/{statementEntry}/invoice-scan', [StatementInvoiceScanController::class, 'destroy'])
        ->name('statement-entries.invoice-scan.destroy');

    Route::delete('statement-entries/{statementEntry}', [StatementEntryController::class, 'destroy'])
        ->name('statement-entries.destroy');
});

require __DIR__.'/settings.php';

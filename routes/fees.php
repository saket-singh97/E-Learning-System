<?php

use App\Http\Controllers\Fees\DownloadInvoiceController;
use App\Http\Controllers\Fees\DownloadPaymentHistoryImportErrorReportController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices/{invoice}', DownloadInvoiceController::class)
    ->name('invoices.show');

// S21 B12 — same double-check posture as routes/batches.php's own
// /enrolment-imports/{importBatch}/error-report.
Route::get('/payment-history-imports/{importBatch}/error-report', DownloadPaymentHistoryImportErrorReportController::class)
    ->middleware(['auth', 'role:institute_admin'])
    ->name('payment-history-import.error-report.download');

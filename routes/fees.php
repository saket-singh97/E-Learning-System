<?php

use App\Http\Controllers\Fees\DownloadInvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices/{invoice}', DownloadInvoiceController::class)
    ->name('invoices.show');

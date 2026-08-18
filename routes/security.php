<?php

use App\Http\Controllers\Security\DownloadGdprExportController;
use Illuminate\Support\Facades\Route;

Route::get('/gdpr-exports/{gdprExportRequest}/download', DownloadGdprExportController::class)
    ->middleware('signed')
    ->name('gdpr-export.download');

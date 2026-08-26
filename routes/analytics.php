<?php

use App\Http\Controllers\Analytics\DownloadReportCardController;
use Illuminate\Support\Facades\Route;

Route::get('/report-cards/{monthlyReportCard}/download', DownloadReportCardController::class)
    ->middleware('signed')
    ->name('report-card.download');

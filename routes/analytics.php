<?php

use App\Http\Controllers\Analytics\DownloadReportCardController;
use Illuminate\Support\Facades\Route;

Route::get('/report-cards/{monthlyReportCard}/download', DownloadReportCardController::class)
    ->middleware('signed')
    ->name('report-card.download');

// S19 N18 pt.2 — no 'auth' middleware, same "guest sees a log-in prompt
// inside the component" reasoning Calendar's own route already established.
Route::get('/gamification', fn () => view('gamification'))
    ->name('gamification.show');

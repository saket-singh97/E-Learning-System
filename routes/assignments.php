<?php

use App\Http\Controllers\Assignments\DownloadGradebookExcelExportController;
use App\Http\Controllers\Assignments\DownloadGradebookExportController;
use App\Http\Controllers\Assignments\DownloadGradebookPdfExportController;
use App\Http\Controllers\Assignments\ShowAssignmentController;
use Illuminate\Support\Facades\Route;

Route::get('/assignments/{assignment}', ShowAssignmentController::class)
    ->name('assignments.show');

Route::get('/exports/gradebook', DownloadGradebookExportController::class)
    ->name('assignments.gradebook-export.download');

Route::get('/exports/gradebook.xlsx', DownloadGradebookExcelExportController::class)
    ->name('assignments.gradebook-excel-export.download');

Route::get('/exports/gradebook.pdf', DownloadGradebookPdfExportController::class)
    ->name('assignments.gradebook-pdf-export.download');

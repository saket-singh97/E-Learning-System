<?php

use App\Http\Controllers\Content\DownloadLessonPdfController;
use App\Http\Controllers\Content\JoinLiveClassController;
use App\Http\Controllers\Content\ShowSyllabusController;
use App\Http\Controllers\Content\StreamLessonController;
use App\Http\Controllers\Content\WatchHeartbeatController;
use App\Http\Controllers\Content\WatchLessonController;
use Illuminate\Support\Facades\Route;

Route::get('/watch/{lesson}', StreamLessonController::class)
    ->middleware('signed')
    ->name('watch.stream');

Route::get('/watch/{lesson}/pdf', DownloadLessonPdfController::class)
    ->middleware('signed')
    ->name('watch.pdf');

Route::post('/watch/{lesson}/heartbeat', WatchHeartbeatController::class)
    ->name('watch.heartbeat');

Route::get('/lessons/{lesson}', WatchLessonController::class)
    ->name('lessons.watch');

Route::get('/courses/{course}', ShowSyllabusController::class)
    ->name('courses.show');

Route::get('/live-sessions/{session}/join', JoinLiveClassController::class)
    ->name('live.join');

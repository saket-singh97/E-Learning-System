<?php

use Illuminate\Support\Facades\Route;

Route::get('/dashboard', fn () => view('student.dashboard'))
    ->name('student.dashboard');

Route::get('/batches', fn () => view('student.batches'))
    ->name('student.batches');

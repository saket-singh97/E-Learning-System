<?php

use App\Http\Controllers\Growth\ShowCourseInterestController;
use Illuminate\Support\Facades\Route;

// S20 R2 — no 'auth' middleware, same "guest sees a log-in prompt inside
// the component" reasoning Planner's own calendar route already
// established; a guest sees a log-in prompt rather than a 500.
Route::get('/affiliate', fn () => view('growth.affiliate-dashboard'))
    ->name('affiliate.dashboard');

// S20 R6 — public, unauthenticated, no publish-status gate at all (design
// doc §4 decision 8); this deliberately never touches ShowSyllabusController
// or LessonAccess::isCourseVisibleTo(), both of which stay exactly as
// hardened as the S5 B8 review left them for guests on a real syllabus page.
Route::get('/courses/{course}/interest', ShowCourseInterestController::class)
    ->name('course-interest.show');

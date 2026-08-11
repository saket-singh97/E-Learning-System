<?php

namespace App\Http\Controllers\Assessments;

use App\Domains\Assessments\Models\Quiz;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowQuizController extends Controller
{
    /**
     * Id is read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController/
     * WatchLessonController (CLAUDE.md).
     */
    public function __invoke(Request $request): View
    {
        $quiz = Quiz::with('lesson.section.course')->findOrFail($request->route('quiz'));

        // Two separate gates, same shape as WatchLessonController's
        // isVisibleTo()/canStream() split: a draft quiz's existence stays
        // hidden (404), but a published quiz's existence is not secret —
        // a guest just isn't allowed to open it (403), same distinction
        // LessonAccess::canStream() draws for lessons.
        abort_unless($quiz->isVisibleTo($request->user()), 404);
        abort_unless((bool) $request->user()?->hasAnyRole(['student', 'instructor', 'institute_admin']), 403);

        return view('assessments.quiz', ['quiz' => $quiz]);
    }
}

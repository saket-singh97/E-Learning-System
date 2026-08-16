<?php

namespace App\Http\Controllers\Assignments;

use App\Domains\Assignments\Models\Assignment;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowAssignmentController extends Controller
{
    /**
     * Id is read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController/
     * ShowQuizController (CLAUDE.md).
     */
    public function __invoke(Request $request): View
    {
        $assignment = Assignment::with('lesson.section.course')->findOrFail($request->route('assignment'));

        // Same 404-vs-403 split as ShowQuizController: a not-yet-visible
        // assignment's existence stays hidden, but an enrolled/staff
        // viewer being denied for a role reason gets a real 403.
        abort_unless($assignment->isVisibleTo($request->user()), 404);
        abort_unless((bool) $request->user()?->hasAnyRole(['student', 'instructor', 'institute_admin']), 403);

        return view('assignments.assignment', ['assignment' => $assignment]);
    }
}

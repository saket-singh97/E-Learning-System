<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\RecommendationService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowSyllabusController extends Controller
{
    /**
     * Slug is read off the route rather than method-injected — same
     * domain-route splicing gotcha as WatchLessonController/
     * StreamLessonController (CLAUDE.md).
     */
    public function __invoke(Request $request, LessonAccess $access, RecommendationService $recommendations): View
    {
        $course = Course::where('slug', $request->route('course'))->firstOrFail();

        // A draft course's title/section names were otherwise readable by
        // any guest who knew the slug, even though its lesson rows were
        // already correctly gated (found in the S5 B8 review).
        abort_unless($access->isCourseVisibleTo($course, $request->user()), 404);

        return view('content.syllabus', [
            'course' => $course,
            'recommendations' => $recommendations->forCourse($course, $request->user()),
        ]);
    }
}

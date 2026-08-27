<?php

namespace App\Http\Controllers\Growth;

use App\Domains\Content\Models\Course;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowCourseInterestController extends Controller
{
    /**
     * Slug read off the route rather than method-injected — same
     * domain-route splicing gotcha as ShowSyllabusController (CLAUDE.md).
     * No publish-status gate at all: this page exists specifically for a
     * course that isn't open yet, unlike the syllabus page it deliberately
     * doesn't touch (design doc §4 decision 8).
     */
    public function __invoke(Request $request): View
    {
        $course = Course::where('slug', $request->route('course'))->firstOrFail();

        return view('growth.course-interest', ['course' => $course]);
    }
}

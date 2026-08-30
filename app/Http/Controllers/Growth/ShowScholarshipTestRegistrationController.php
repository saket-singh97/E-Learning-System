<?php

namespace App\Http\Controllers\Growth;

use App\Domains\Growth\Models\ScholarshipTest;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowScholarshipTestRegistrationController extends Controller
{
    /**
     * Public, unauthenticated — same posture ShowCourseInterestController
     * already established for a guest-facing acquisition form (design doc
     * §4). Id read off the route rather than method-injected, same
     * domain-route splicing gotcha every other controller in this codebase
     * guards against (CLAUDE.md).
     */
    public function __invoke(Request $request): View
    {
        $test = ScholarshipTest::findOrFail($request->route('scholarshipTest'));

        return view('growth.scholarship-test-register', ['test' => $test]);
    }
}

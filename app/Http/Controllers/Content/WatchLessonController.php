<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class WatchLessonController extends Controller
{
    /**
     * The player page runs the same gate as the byte stream. It used to be a
     * bare closure with no gate at all, which handed anonymous visitors the
     * titles of draft and unreleased lessons. isPlayable() (LessonAccess) is
     * type-aware since S9 B6 — video/live_session still need a finished
     * upload, pdf/text/embed each need their own single column filled.
     */
    public function __invoke(Request $request, LessonAccess $access): View
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));

        $viewer = $request->user();

        abort_unless($access->isPlayable($lesson) && $access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);

        // Computed here rather than inside the view, same shape
        // LessonPlayer::mount() already uses for its own streamUrl — pdf is
        // the only one of the three new types that serves a local file
        // needing a signed, temporary URL; text/embed render straight from
        // already-validated columns with no file-serving concern.
        $pdfUrl = $lesson->type === 'pdf'
            ? URL::temporarySignedRoute('watch.pdf', now()->addMinutes(10), [
                'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                'lesson' => $lesson->id,
            ])
            : null;

        return view('content.watch', ['lesson' => $lesson, 'pdfUrl' => $pdfUrl]);
    }
}

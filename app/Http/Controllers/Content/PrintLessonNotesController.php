<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * S24 U25 — a plain print view, not a new PDF generator (design §4
 * decision 7): the browser's own print-to-PDF already handles pagination
 * for free-form, variable-length note content correctly, unlike the
 * fixed-layout documents `CertificateService`/`RankCardGenerator`/etc.
 * (dompdf-backed) actually need that for. Same two-gate/404-then-403
 * shape `WatchLessonController` already uses — a viewer without stream
 * access is denied the same way any other lesson-scoped page already is.
 * Content itself is the *existing* `content.lesson-notes` (the viewer's
 * own notes, already user-scoped) and `ai.lesson-revision-notes` Livewire
 * components, embedded here unchanged — no new query logic duplicating
 * what those already-tested components already do correctly.
 */
class PrintLessonNotesController extends Controller
{
    public function __invoke(Request $request, LessonAccess $access): View
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));
        $viewer = $request->user();

        abort_unless($access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);

        return view('content.lesson-notes-print', ['lesson' => $lesson]);
    }
}

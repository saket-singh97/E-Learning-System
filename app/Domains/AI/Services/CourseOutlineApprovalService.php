<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L9's approval step — creates real Content records via direct Eloquent
 * calls, not an event (S17 design §4 decision 1): LessonArtifact's own
 * belongsTo(Lesson) FK already precedents the AI domain relating directly
 * into Content's models, and approving a draft is a synchronous,
 * user-triggered click, not a domain reacting to another domain's state
 * change. Only Content's *models* are touched here, never a Content
 * service.
 *
 * Structure only — section/lesson titles, no lesson bodies (design §4
 * decision 6) — so each Lesson is created with type='text' and an empty
 * text_body for the instructor to fill in later; the outline's own
 * one-line description stays readable on the draft row itself, it isn't
 * copied into the new Lesson.
 */
class CourseOutlineApprovalService
{
    /**
     * Re-approving an already-decided draft (approved or rejected) is a
     * no-op rather than blocked with an exception — the review UI already
     * hides the approve action once status != draft, so the only realistic
     * way to hit this is a double-click race, and a no-op can never leave a
     * second, duplicate Course behind the way re-running creation would.
     */
    public function approve(CourseOutlineDraft $draft): CourseOutlineDraft
    {
        if ($draft->status !== 'draft') {
            return $draft;
        }

        $course = DB::transaction(function () use ($draft): Course {
            $course = Course::create([
                'title' => $draft->topic,
                'slug' => Str::slug($draft->topic).'-'.uniqid(),
                'status' => 'draft',
            ]);

            foreach ($draft->generated_outline ?? [] as $sectionIndex => $section) {
                $courseSection = CourseSection::create([
                    'course_id' => $course->id,
                    'title' => $section['section_title'] ?? '',
                    'position' => $sectionIndex,
                ]);

                foreach ($section['lessons'] ?? [] as $lessonIndex => $lesson) {
                    Lesson::create([
                        'course_section_id' => $courseSection->id,
                        'title' => $lesson['title'] ?? '',
                        'type' => 'text',
                        'position' => $lessonIndex,
                    ]);
                }
            }

            return $course;
        });

        $draft->update([
            'status' => 'approved',
            'created_course_id' => $course->id,
        ]);

        return $draft->fresh();
    }

    /** Same no-op-when-already-decided posture as approve() above. */
    public function reject(CourseOutlineDraft $draft): CourseOutlineDraft
    {
        if ($draft->status !== 'draft') {
            return $draft;
        }

        $draft->update(['status' => 'rejected']);

        return $draft->fresh();
    }
}

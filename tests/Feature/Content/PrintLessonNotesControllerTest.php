<?php

use App\Domains\AI\Models\LessonRevisionNote;
use App\Domains\Content\Models\LessonNote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Reuses gateFixture()/gateStaff() already defined in the sibling
// LessonAccessGateTest.php (same directory, same Pest run) — same
// precedent WeakTopicsTest already relies on for a sibling file's helpers.

it('404s on the print-notes page for a draft lesson, same as the watch page', function () {
    [, , $lesson] = gateFixture(['status' => 'draft']);

    $this->get("http://physics.lms.test/lessons/{$lesson->id}/notes/print")
        ->assertNotFound();
});

it('403s a guest on the print-notes page of a non-preview lesson, same as the watch page', function () {
    [, , $lesson] = gateFixture();

    $this->get("http://physics.lms.test/lessons/{$lesson->id}/notes/print")
        ->assertForbidden();
});

it('renders the viewer\'s own notes and the AI revision notes for an authorized viewer', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    LessonNote::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'timestamp_s' => 10, 'body' => 'My own note body']);
    LessonRevisionNote::create(['lesson_id' => $lesson->id, 'source_artifact_version' => 1, 'status' => 'ready', 'notes' => ['Key revision point']]);

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}/notes/print")
        ->assertOk()
        ->assertSee('My own note body')
        ->assertSee('Key revision point');
});

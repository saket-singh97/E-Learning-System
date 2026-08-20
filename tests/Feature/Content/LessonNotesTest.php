<?php

use App\Domains\Content\Livewire\LessonNotes;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonNote;
use App\Domains\Content\Services\PrerequisiteGraph;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0, 'video_status' => 'ready', 'video_duration_seconds' => 100]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('bookmarks a moment with a null body', function () {
    Livewire::actingAs($this->student)
        ->test(LessonNotes::class, ['lesson' => $this->lesson])
        ->call('bookmark', 42)
        ->assertSee('Bookmark');

    $note = LessonNote::where('lesson_id', $this->lesson->id)->where('user_id', $this->student->id)->sole();
    expect($note->timestamp_s)->toBe(42)
        ->and($note->body)->toBeNull();
});

it('adds an annotated note at a timestamp', function () {
    Livewire::actingAs($this->student)
        ->test(LessonNotes::class, ['lesson' => $this->lesson])
        ->set('newNoteBody', 'Key formula here')
        ->call('addNote', 77)
        ->assertSee('Key formula here');

    $note = LessonNote::where('lesson_id', $this->lesson->id)->where('user_id', $this->student->id)->sole();
    expect($note->timestamp_s)->toBe(77)
        ->and($note->body)->toBe('Key formula here');
});

it('no-ops bookmarking and note-adding for a guest', function () {
    Livewire::test(LessonNotes::class, ['lesson' => $this->lesson])
        ->call('bookmark', 10);

    Livewire::test(LessonNotes::class, ['lesson' => $this->lesson])
        ->set('newNoteBody', 'x')
        ->call('addNote', 10);

    expect(LessonNote::where('lesson_id', $this->lesson->id)->exists())->toBeFalse();
});

it('reuses LessonAccess::canStream() — denies a bookmark when a prerequisite is unmet', function () {
    $prerequisite = Lesson::create(['course_section_id' => $this->lesson->course_section_id, 'title' => 'Intro', 'type' => 'text', 'position' => 1, 'text_body' => 'Intro.']);
    app(PrerequisiteGraph::class)->addPrerequisite($this->lesson, $prerequisite);

    Livewire::actingAs($this->student)
        ->test(LessonNotes::class, ['lesson' => $this->lesson])
        ->call('bookmark', 5);

    expect(LessonNote::where('lesson_id', $this->lesson->id)->exists())->toBeFalse();
});

it('only shows the viewer own notes, not another student own', function () {
    $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $other->assignRole('student');

    LessonNote::create(['lesson_id' => $this->lesson->id, 'user_id' => $other->id, 'timestamp_s' => 15, 'body' => 'Someone else\'s note']);

    Livewire::actingAs($this->student)
        ->test(LessonNotes::class, ['lesson' => $this->lesson])
        ->assertDontSee('Someone else\'s note')
        ->assertSee('No notes or bookmarks yet.');
});

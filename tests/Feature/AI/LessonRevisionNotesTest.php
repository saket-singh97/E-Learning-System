<?php

use App\Domains\AI\Livewire\LessonRevisionNotes;
use App\Domains\AI\Models\LessonRevisionNote;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows an empty state for a visible lesson with no generated notes yet', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');
    $this->actingAs($student);

    Livewire::test(LessonRevisionNotes::class, ['lesson' => $lesson])
        ->assertSee('No revision notes yet');
});

it('shows the generated notes once ready', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');
    $this->actingAs($student);

    LessonRevisionNote::create([
        'lesson_id' => $lesson->id,
        'source_artifact_version' => 1,
        'status' => 'ready',
        'notes' => ['Velocity is a vector.', 'Speed has no direction.'],
    ]);

    Livewire::test(LessonRevisionNotes::class, ['lesson' => $lesson])
        ->assertSee('Velocity is a vector.')
        ->assertSee('Speed has no direction.');
});

it('gives the same 404 the stream route gives for a draft-course lesson, never leaking its notes', function () {
    // Livewire's initial mount runs through a real (temp) route via
    // Testable::create(), with exception handling disabled for everything
    // EXCEPT HttpException/AuthorizationException (RequestBroker's own
    // temporarilyDisableExceptionHandlingAndMiddleware()) — so
    // abort_unless(...,404) surfaces as a normal 404 response
    // (->assertNotFound()), not a thrown PHP exception the way a plain
    // service-method gate (DoubtChatService::ask()) does.
    [$tenant, , $lesson] = gateFixture(['status' => 'draft']);
    $student = gateStaff($tenant, 'student');
    $this->actingAs($student);

    LessonRevisionNote::create([
        'lesson_id' => $lesson->id,
        'source_artifact_version' => 1,
        'status' => 'ready',
        'notes' => ['SECRET DRAFT NOTE'],
    ]);

    Livewire::test(LessonRevisionNotes::class, ['lesson' => $lesson])
        ->assertNotFound()
        ->assertDontSee('SECRET DRAFT NOTE');
});

it('gives the same 404 for a guest on a not-yet-released lesson', function () {
    [, , $lesson] = gateFixture([], ['scheduled_at' => now()->addDay()]);

    Livewire::test(LessonRevisionNotes::class, ['lesson' => $lesson])
        ->assertNotFound();
});

it('lets staff preview notes on their own tenants unreleased lesson', function () {
    [$tenant, , $lesson] = gateFixture(['status' => 'draft']);
    $instructor = gateStaff($tenant, 'instructor');
    $this->actingAs($instructor);

    Livewire::test(LessonRevisionNotes::class, ['lesson' => $lesson])
        ->assertSuccessful();
});

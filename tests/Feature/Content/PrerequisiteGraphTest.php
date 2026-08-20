<?php

use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\PrerequisiteGraph;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\CircularPrerequisiteException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function prereqLesson(CourseSection $section, string $title, int $position): Lesson
{
    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => $title,
        'type' => 'text',
        'position' => $position,
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->graph = app(PrerequisiteGraph::class);
});

it('builds a valid non-cyclic chain A requires B requires C, plus a legitimate 4th edge', function () {
    $a = prereqLesson($this->section, 'A', 0);
    $b = prereqLesson($this->section, 'B', 1);
    $c = prereqLesson($this->section, 'C', 2);
    $d = prereqLesson($this->section, 'D', 3);

    $this->graph->addPrerequisite($a, $b);
    $this->graph->addPrerequisite($b, $c);

    // Legitimate, non-cyclic 4th edge — D requires C too, same target as
    // B's own prerequisite, not a chain extension. Proves the DFS rejects
    // only real cycles, not just any second prerequisite (design doc §5).
    $this->graph->addPrerequisite($d, $c);

    expect($a->prerequisites()->pluck('lessons.id'))->toEqual(collect([$b->id]));
    expect($b->prerequisites()->pluck('lessons.id'))->toEqual(collect([$c->id]));
    expect($d->prerequisites()->pluck('lessons.id'))->toEqual(collect([$c->id]));
});

it('rejects closing the cycle C requires A on top of an existing A requires B requires C chain', function () {
    $a = prereqLesson($this->section, 'A', 0);
    $b = prereqLesson($this->section, 'B', 1);
    $c = prereqLesson($this->section, 'C', 2);

    $this->graph->addPrerequisite($a, $b);
    $this->graph->addPrerequisite($b, $c);

    expect(fn () => $this->graph->addPrerequisite($c, $a))
        ->toThrow(CircularPrerequisiteException::class);

    expect($c->prerequisites()->pluck('lessons.id'))->toBeEmpty();
});

it('rejects a lesson requiring itself', function () {
    $a = prereqLesson($this->section, 'A', 0);

    expect(fn () => $this->graph->addPrerequisite($a, $a))
        ->toThrow(CircularPrerequisiteException::class);
});

it('rejects a cycle through the LessonResource form without writing the pivot row', function () {
    $this->seed(RoleSeeder::class);
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $a = prereqLesson($this->section, 'A', 0);
    $b = prereqLesson($this->section, 'B', 1);
    $this->graph->addPrerequisite($a, $b);

    // Warms the current Filament panel context (teach) so Livewire::test()
    // below resolves this resource's routes — same setup
    // LessonContentTypesTest's own Filament form tests already use.
    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$b->id}/edit")
        ->assertOk();

    Livewire::actingAs($instructor)
        ->test(EditLesson::class, ['record' => $b->id])
        ->fillForm(['prerequisites' => [$a->id]])
        ->call('save')
        ->assertHasFormErrors(['prerequisites']);

    expect($b->prerequisites()->pluck('lessons.id'))->toBeEmpty();
});

/**
 * S13 B17 — modifyQueryUsing only narrows the dropdown's own options;
 * Filament's relationship Select still syncs whatever raw ids the
 * request actually submits (verified against Filament's own
 * Select::saveRelationshipsUsing()). The ->rule() closure is the only
 * real server-side gate, and the review pass found it silently skipped
 * validation for a cross-course id — the dropdown just happened to never
 * offer one, so nothing forced the actual save-time check to exist.
 */
it('rejects a same-tenant, different-course prerequisite id submitted directly, bypassing the dropdown scoping', function () {
    $this->seed(RoleSeeder::class);
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $otherCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'published']);
    $otherSection = CourseSection::create(['course_id' => $otherCourse->id, 'title' => 'Reactions', 'position' => 0]);
    $foreignLesson = prereqLesson($otherSection, 'Foreign', 0);

    $b = prereqLesson($this->section, 'B', 0);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$b->id}/edit")
        ->assertOk();

    Livewire::actingAs($instructor)
        ->test(EditLesson::class, ['record' => $b->id])
        ->fillForm(['prerequisites' => [$foreignLesson->id]])
        ->call('save')
        ->assertHasFormErrors(['prerequisites']);

    expect($b->prerequisites()->pluck('lessons.id'))->toBeEmpty();
});

it('rejects a bogus prerequisite id instead of silently skipping it', function () {
    $this->seed(RoleSeeder::class);
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $b = prereqLesson($this->section, 'B', 0);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$b->id}/edit")
        ->assertOk();

    Livewire::actingAs($instructor)
        ->test(EditLesson::class, ['record' => $b->id])
        ->fillForm(['prerequisites' => [999999]])
        ->call('save')
        ->assertHasFormErrors(['prerequisites']);

    expect($b->prerequisites()->pluck('lessons.id'))->toBeEmpty();
});

<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages\CreateScholarshipTest;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages\EditScholarshipTest;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\RelationManagers\RegistrationsRelationManager;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\ScholarshipTestResource;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);
    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Scholarship Quiz',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);
    $this->course = $course;
});

it('creates a scholarship test linking an existing quiz and an open/close window', function () {
    $this->actingAs($this->admin)
        ->get(ScholarshipTestResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateScholarshipTest::class)
        ->fillForm([
            'title' => 'National Scholarship Test',
            'course_id' => $this->course->id,
            'quiz_id' => $this->quiz->id,
            'opens_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'closes_at' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $test = ScholarshipTest::sole();

    expect($test->title)->toBe('National Scholarship Test');
    expect($test->quiz_id)->toBe($this->quiz->id);
    expect($test->tenant_id)->toBe($this->tenant->id);
});

it('rejects a close window before the open window', function () {
    $this->actingAs($this->admin)
        ->get(ScholarshipTestResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateScholarshipTest::class)
        ->fillForm([
            'title' => 'Bad Window Test',
            'quiz_id' => $this->quiz->id,
            'opens_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'closes_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['closes_at']);
});

it('404s a cross-tenant admin trying to edit another tenant\'s scholarship test', function () {
    $test = ScholarshipTest::create([
        'title' => 'National Scholarship Test',
        'quiz_id' => $this->quiz->id,
        'opens_at' => now(),
        'closes_at' => now()->addWeek(),
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get(ScholarshipTestResource::getUrl('edit', ['record' => $test, 'subdomain' => 'chemistry'], panel: 'admin'))
        ->assertNotFound();
});

it('shows registrations sorted by score with conversion status, scoped to the owning test', function () {
    $test = ScholarshipTest::create([
        'title' => 'National Scholarship Test',
        'quiz_id' => $this->quiz->id,
        'opens_at' => now(),
        'closes_at' => now()->addWeek(),
    ]);

    $low = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'Low Scorer', 'email' => 'low@example.com', 'score' => 2]);
    $high = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'High Scorer', 'email' => 'high@example.com', 'score' => 8]);

    $this->actingAs($this->admin)
        ->get(ScholarshipTestResource::getUrl('edit', ['record' => $test, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(RegistrationsRelationManager::class, [
        'ownerRecord' => $test,
        'pageClass' => EditScholarshipTest::class,
    ])->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

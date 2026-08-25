<?php

use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\ListQuizzes;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\OmrTemplateGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Offline Mock', 'type' => 'quiz', 'position' => 0]);
    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Offline Mock Test',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
        'status' => 'draft',
    ]);
});

it('creates a template row and a printable PDF', function () {
    $template = app(OmrTemplateGenerator::class)->generate($this->quiz, 20, 4, 6);

    expect($template->quiz_id)->toBe($this->quiz->id);
    expect($template->question_count)->toBe(20);
    expect($template->layout['questions'])->toHaveCount(20);
    expect(Storage::disk('local')->exists("omr-templates/{$this->tenant->id}/{$template->id}.pdf"))->toBeTrue();
});

it('regenerates in place instead of duplicating a row for the same quiz', function () {
    $generator = app(OmrTemplateGenerator::class);
    $first = $generator->generate($this->quiz, 20, 4, 6);
    $second = $generator->generate($this->quiz, 30, 5, 8);

    expect($second->id)->toBe($first->id);
    expect($second->question_count)->toBe(30);
    expect(OmrTemplate::where('quiz_id', $this->quiz->id)->count())->toBe(1);
});

it('generates a template through the real Filament action on QuizResource', function () {
    $this->actingAs($this->instructor)
        ->get(\App\Domains\Assessments\Filament\Resources\Quizzes\QuizResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListQuizzes::class)
        ->callTableAction('generateOmrTemplate', $this->quiz, data: [
            'question_count' => 25,
            'options_per_question' => 4,
            'roll_number_digits' => 6,
        ]);

    $template = OmrTemplate::where('quiz_id', $this->quiz->id)->sole();
    expect($template->question_count)->toBe(25);
});

// B17 review pass — the generated PDF had no download path at all.
it('downloads the generated PDF through the real route', function () {
    $template = app(OmrTemplateGenerator::class)->generate($this->quiz, 20, 4, 6);

    $this->actingAs($this->instructor)
        ->get("http://physics.lms.test/omr-templates/{$template->id}/download")
        ->assertOk()
        ->assertDownload();
});

it('never resolves another tenants template — BelongsToTenants own scope hides it, 404 not a leak', function () {
    $template = app(OmrTemplateGenerator::class)->generate($this->quiz, 20, 4, 6);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $outsider = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $outsider->assignRole('instructor');

    $this->actingAs($outsider)
        ->get("http://chemistry.lms.test/omr-templates/{$template->id}/download")
        ->assertNotFound();
});

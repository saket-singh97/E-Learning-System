<?php

use App\Domains\Assessments\Filament\Resources\OmrSheetScans\OmrSheetScanResource;
use App\Domains\Assessments\Filament\Resources\OmrSheetScans\Pages\CreateOmrSheetScan;
use App\Domains\Assessments\Jobs\ProcessOmrSheetScan;
use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uploads a scan as pending and dispatches processing, from the real Admin-panel form', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    (new RoleSeeder)->run();

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Offline Mock',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
        'status' => 'published',
    ]);
    $template = OmrTemplate::create([
        'quiz_id' => $quiz->id,
        'question_count' => 2,
        'options_per_question' => 4,
        'roll_number_digits' => 6,
        'layout' => OmrTemplate::generateLayout(2, 4, 6),
        'question_ids' => [],
    ]);

    $this->actingAs($admin)
        ->get(OmrSheetScanResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateOmrSheetScan::class)
        ->fillForm([
            'omr_template_id' => $template->id,
            'image' => UploadedFile::fake()->image('sheet.png'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $scan = OmrSheetScan::sole();
    expect($scan->status)->toBe('pending');
    expect($scan->omr_template_id)->toBe($template->id);
    expect($scan->uploaded_by_user_id)->toBe($admin->id);

    Queue::assertPushed(ProcessOmrSheetScan::class, fn (ProcessOmrSheetScan $job) => $job->scanId === $scan->id);
});

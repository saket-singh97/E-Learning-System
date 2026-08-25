<?php

use App\Domains\Assessments\Jobs\ProcessOmrSheetScan;
use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function drawOmrSheetForRoll(OmrTemplate $template, string $rollNumber, array $questionOptionIndexes): string
{
    $digits = str_split($rollNumber);
    $image = imagecreatetruecolor(400, 500);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    foreach ($template->layout['roll_number'] as $columnIndex => $column) {
        $chosenDigit = (int) $digits[$columnIndex];
        $bubble = collect($column['digits'])->firstWhere('digit', $chosenDigit);
        imagefilledellipse($image, $bubble['x'], $bubble['y'], 12, 12, $black);
    }

    foreach ($template->layout['questions'] as $questionIndex => $question) {
        $chosenOption = $questionOptionIndexes[$questionIndex];
        $bubble = collect($question['options'])->firstWhere('option_index', $chosenOption);
        imagefilledellipse($image, $bubble['x'], $bubble['y'], 12, 12, $black);
    }

    ob_start();
    imagepng($image);
    $contents = ob_get_clean();
    imagedestroy($image);

    $path = 'omr-scans/test-'.uniqid().'.png';
    Storage::disk('local')->put($path, $contents);

    return $path;
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Section', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->questions = collect(range(1, 2))->map(fn () => Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Q '.uniqid(),
        'options' => ['A', 'B', 'C', 'D'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]));

    $this->quiz = Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Offline Mock',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
        'status' => 'published',
    ]);

    $this->template = OmrTemplate::create([
        'quiz_id' => $this->quiz->id,
        'question_count' => 2,
        'options_per_question' => 4,
        'roll_number_digits' => 6,
        'layout' => OmrTemplate::generateLayout(2, 4, 6),
        'question_ids' => $this->questions->pluck('id')->all(),
    ]);

    $this->uploader = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('resolves the roll number to a real enrolment and produces a gradeable QuizAttempt', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $rollNumber = str_pad((string) $enrolment->id, 6, '0', STR_PAD_LEFT);
    // Both questions answered 'A' — matches each question's own
    // correct_answer, so this should grade as a perfect score.
    $imagePath = drawOmrSheetForRoll($this->template, $rollNumber, [0, 0]);

    $scan = OmrSheetScan::create([
        'omr_template_id' => $this->template->id,
        'uploaded_by_user_id' => $this->uploader->id,
        'image_disk_path' => $imagePath,
        'status' => 'pending',
    ]);

    app(ProcessOmrSheetScan::class, ['scanId' => $scan->id])->handle(
        app(\App\Domains\Assessments\Services\OmrScanner::class),
        app(\App\Domains\Assessments\Services\QuizAttemptService::class),
    );

    $scan->refresh();
    expect($scan->status)->toBe('processed');
    expect($scan->matched_user_id)->toBe($student->id);
    expect($scan->quiz_attempt_id)->not->toBeNull();

    $attempt = QuizAttempt::findOrFail($scan->quiz_attempt_id);
    expect($attempt->user_id)->toBe($student->id);
    expect($attempt->status)->toBe('submitted');
    expect((float) $attempt->score)->toBe(2.0);
    expect(QuizAnswer::where('quiz_attempt_id', $attempt->id)->count())->toBe(2);
});

it('queues a non-matching roll number for manual resolution instead of crashing', function () {
    // No enrolment exists with this id at all.
    $imagePath = drawOmrSheetForRoll($this->template, '999999', [0, 0]);

    $scan = OmrSheetScan::create([
        'omr_template_id' => $this->template->id,
        'uploaded_by_user_id' => $this->uploader->id,
        'image_disk_path' => $imagePath,
        'status' => 'pending',
    ]);

    app(ProcessOmrSheetScan::class, ['scanId' => $scan->id])->handle(
        app(\App\Domains\Assessments\Services\OmrScanner::class),
        app(\App\Domains\Assessments\Services\QuizAttemptService::class),
    );

    $scan->refresh();
    expect($scan->status)->toBe('failed');
    expect($scan->matched_user_id)->toBeNull();
    expect($scan->quiz_attempt_id)->toBeNull();
    expect(QuizAttempt::count())->toBe(0);
});

it('queues a roll number matching an enrolment in a different course for manual resolution', function () {
    $otherCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry-'.uniqid(), 'status' => 'published']);
    $otherBatch = Batch::create([
        'course_id' => $otherCourse->id,
        'title' => 'Other Batch',
        'slug' => 'other-batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreignEnrolment = Enrolment::create([
        'batch_id' => $otherBatch->id,
        'user_id' => $otherStudent->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $rollNumber = str_pad((string) $foreignEnrolment->id, 6, '0', STR_PAD_LEFT);
    $imagePath = drawOmrSheetForRoll($this->template, $rollNumber, [0, 0]);

    $scan = OmrSheetScan::create([
        'omr_template_id' => $this->template->id,
        'uploaded_by_user_id' => $this->uploader->id,
        'image_disk_path' => $imagePath,
        'status' => 'pending',
    ]);

    app(ProcessOmrSheetScan::class, ['scanId' => $scan->id])->handle(
        app(\App\Domains\Assessments\Services\OmrScanner::class),
        app(\App\Domains\Assessments\Services\QuizAttemptService::class),
    );

    $scan->refresh();
    expect($scan->status)->toBe('failed');
});

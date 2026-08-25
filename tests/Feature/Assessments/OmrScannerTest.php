<?php

use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\OmrScanner;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $this->questions = collect(range(1, 3))->map(fn () => Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'Q '.uniqid(),
        'options' => ['A', 'B', 'C', 'D'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]));

    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Offline Mock',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
        'status' => 'published',
    ]);

    $this->template = OmrTemplate::create([
        'quiz_id' => $quiz->id,
        'question_count' => 3,
        'options_per_question' => 4,
        'roll_number_digits' => 3,
        'layout' => OmrTemplate::generateLayout(3, 4, 3),
        'question_ids' => $this->questions->pluck('id')->all(),
    ]);

    $this->uploader = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

/**
 * Draws a synthetic bubble sheet: a filled black circle at each chosen
 * bubble's own template coordinates, everything else left blank — proves
 * the scanner reads the template's own coordinate grid correctly, not
 * just returns hardcoded values.
 *
 * @param  array<int, int>  $rollDigits  chosen digit value per column
 * @param  array<int, int>  $questionOptionIndexes  chosen option_index per question_index
 */
function drawSyntheticOmrSheet(OmrTemplate $template, array $rollDigits, array $questionOptionIndexes): string
{
    $image = imagecreatetruecolor(400, 500);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    foreach ($template->layout['roll_number'] as $columnIndex => $column) {
        $chosenDigit = $rollDigits[$columnIndex];
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

it('reads the exact roll number and per-question answers off a synthetic bubble sheet', function () {
    $imagePath = drawSyntheticOmrSheet($this->template, [4, 2, 7], [0, 2, 1]);

    $scan = OmrSheetScan::create([
        'omr_template_id' => $this->template->id,
        'uploaded_by_user_id' => $this->uploader->id,
        'image_disk_path' => $imagePath,
        'status' => 'pending',
    ]);

    $result = (new OmrScanner)->scan($scan);

    expect($result['detected_roll_number'])->toBe('427');

    $questionIds = $this->questions->pluck('id')->all();
    expect($result['responses'][$questionIds[0]])->toBe('A');
    expect($result['responses'][$questionIds[1]])->toBe('C');
    expect($result['responses'][$questionIds[2]])->toBe('B');
});

it('reads an all-zero roll number and first-option answers correctly', function () {
    $imagePath = drawSyntheticOmrSheet($this->template, [0, 0, 0], [0, 0, 0]);

    $scan = OmrSheetScan::create([
        'omr_template_id' => $this->template->id,
        'uploaded_by_user_id' => $this->uploader->id,
        'image_disk_path' => $imagePath,
        'status' => 'pending',
    ]);

    $result = (new OmrScanner)->scan($scan);

    expect($result['detected_roll_number'])->toBe('000');

    $questionIds = $this->questions->pluck('id')->all();
    foreach ($questionIds as $questionId) {
        expect($result['responses'][$questionId])->toBe('A');
    }
});

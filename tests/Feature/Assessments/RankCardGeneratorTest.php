<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\RankCard;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\RankCardGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function rankCardQuizAttempt(Course $course, TestSeries $series, User $user, string $concept, float $score): QuizAttempt
{
    $section = CourseSection::where('course_id', $course->id)->first()
        ?? CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Quiz',
        'blueprint' => [],
        'time_limit_minutes' => 30,
        'max_attempts' => 3,
        'test_series_id' => $series->id,
    ]);
    $question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'Q',
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'concept' => $concept,
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $user->id,
        'question_ids' => [$question->id],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
        'submitted_at' => now(),
        'score' => $score,
        'max_score' => 1,
    ]);

    QuizAnswer::create([
        'quiz_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'response' => 'A',
        'is_correct' => true,
        'score' => $score,
        'status' => 'auto_graded',
    ]);

    return $attempt;
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()]);
    $this->series = TestSeries::create(['title' => 'Weekly Series']);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->generator = new RankCardGenerator;
});

it('generates a PDF and a rank_cards row with rank/percentile from TestSeriesRankingService', function () {
    rankCardQuizAttempt($this->course, $this->series, $this->student, 'Mechanics', 1.0);

    $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
    rankCardQuizAttempt($this->course, $this->series, $other, 'Mechanics', 0.0);

    $card = $this->generator->generate($this->series, $this->student);

    expect($card->test_series_id)->toBe($this->series->id);
    expect($card->user_id)->toBe($this->student->id);
    expect(Storage::disk('local')->exists($card->pdf_path))->toBeTrue();
});

it('sums the subject breakdown to the same total score TestSeriesRankingService reports', function () {
    rankCardQuizAttempt($this->course, $this->series, $this->student, 'Mechanics', 1.0);
    rankCardQuizAttempt($this->course, $this->series, $this->student, 'Optics', 0.5);

    $ranking = app(\App\Domains\Assessments\Services\TestSeriesRankingService::class)
        ->rankingsFor($this->series, $this->student)
        ->firstWhere('user_id', $this->student->id);

    $card = $this->generator->generate($this->series, $this->student);
    $pdfContent = Storage::disk('local')->get($card->pdf_path);

    expect($ranking['total_score'])->toBe(1.5);
    expect($pdfContent)->not->toBeEmpty();

    $breakdown = $this->generator->subjectBreakdown($this->series, $this->student);

    expect((float) $breakdown->sum())->toBe((float) $ranking['total_score']);
    expect($breakdown['Mechanics'])->toBe(1.0);
    expect($breakdown['Optics'])->toBe(0.5);
});

it('regenerating overwrites the same unique (test_series_id, user_id) row, not a duplicate', function () {
    rankCardQuizAttempt($this->course, $this->series, $this->student, 'Mechanics', 1.0);

    $first = $this->generator->generate($this->series, $this->student);

    // Student attempts another quiz — the rank card should reflect the new total on regenerate.
    rankCardQuizAttempt($this->course, $this->series, $this->student, 'Optics', 0.5);

    $second = $this->generator->generate($this->series, $this->student);

    expect($second->id)->toBe($first->id);
    expect(RankCard::where('test_series_id', $this->series->id)->where('user_id', $this->student->id)->count())->toBe(1);

    $ranking = app(\App\Domains\Assessments\Services\TestSeriesRankingService::class)
        ->rankingsFor($this->series, $this->student)
        ->firstWhere('user_id', $this->student->id);

    expect($ranking['total_score'])->toBe(1.5);
});

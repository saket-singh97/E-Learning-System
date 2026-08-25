<?php

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Services\CourseOutlineApprovalService;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->creator = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

function draftWithOutline(User $creator): CourseOutlineDraft
{
    return CourseOutlineDraft::create([
        'created_by_user_id' => $creator->id,
        'topic' => 'Newtonian Mechanics',
        'status' => 'draft',
        'generated_outline' => [
            [
                'section_title' => 'Kinematics',
                'lessons' => [
                    ['title' => 'Position and displacement', 'description' => 'Intro to position.'],
                    ['title' => 'Velocity and acceleration', 'description' => 'Rates of change.'],
                ],
            ],
            [
                'section_title' => 'Dynamics',
                'lessons' => [
                    ['title' => "Newton's laws", 'description' => 'The three laws.'],
                ],
            ],
        ],
    ]);
}

it('creates a real Course/CourseSection/Lesson structure matching the draft outline exactly', function () {
    $draft = draftWithOutline($this->creator);

    $approved = app(CourseOutlineApprovalService::class)->approve($draft);

    expect($approved->status)->toBe('approved')
        ->and($approved->created_course_id)->not->toBeNull();

    $course = Course::findOrFail($approved->created_course_id);

    expect($course->title)->toBe('Newtonian Mechanics')
        ->and($course->status)->toBe('draft')
        ->and($course->sections)->toHaveCount(2);

    $kinematics = $course->sections->firstWhere('title', 'Kinematics');
    $dynamics = $course->sections->firstWhere('title', 'Dynamics');

    expect($kinematics->lessons)->toHaveCount(2)
        ->and($kinematics->lessons->pluck('title')->all())->toBe(['Position and displacement', 'Velocity and acceleration'])
        ->and($dynamics->lessons)->toHaveCount(1)
        ->and($dynamics->lessons->first()->title)->toBe("Newton's laws");
});

it('creates nothing for a rejected draft', function () {
    $draft = draftWithOutline($this->creator);

    $rejected = app(CourseOutlineApprovalService::class)->reject($draft);

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->created_course_id)->toBeNull()
        ->and(Course::count())->toBe(0);
});

it('no-ops on an already-approved draft instead of creating a second Course', function () {
    $draft = draftWithOutline($this->creator);
    $service = app(CourseOutlineApprovalService::class);

    $first = $service->approve($draft);
    $second = $service->approve($first->fresh());

    expect(Course::count())->toBe(1)
        ->and($second->created_course_id)->toBe($first->created_course_id);
});

it('no-ops on an already-rejected draft instead of approving it', function () {
    $draft = draftWithOutline($this->creator);
    $service = app(CourseOutlineApprovalService::class);

    $rejected = $service->reject($draft);
    $result = $service->approve($rejected->fresh());

    expect($result->status)->toBe('rejected')
        ->and($result->created_course_id)->toBeNull()
        ->and(Course::count())->toBe(0);
});

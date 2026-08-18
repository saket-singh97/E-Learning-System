<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Security\Services\GdprDeletionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\GdprDeletionRequestNotPendingException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ada Lovelace', 'phone' => '+911234567890']);
    $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('schedules a pending deletion request the configured number of days out', function () {
    config(['security.gdpr_deletion_grace_days' => 30]);

    $request = app(GdprDeletionService::class)->request($this->subject, $this->staff);

    expect($request->tenant_id)->toBe($this->tenant->id)
        ->and($request->user_id)->toBe($this->subject->id)
        ->and($request->requested_by_id)->toBe($this->staff->id)
        ->and($request->status)->toBe(GdprDeletionRequest::STATUS_PENDING)
        ->and(abs($request->scheduled_for->diffInDays(now())))->toBeGreaterThanOrEqual(29)
        ->and(abs($request->scheduled_for->diffInDays(now())))->toBeLessThanOrEqual(30);
});

it('cancels a pending request by deleting the row', function () {
    $request = app(GdprDeletionService::class)->request($this->subject, $this->staff);

    app(GdprDeletionService::class)->cancel($request);

    expect(GdprDeletionRequest::find($request->id))->toBeNull();
});

it('refuses to cancel a request that is no longer pending', function () {
    $request = app(GdprDeletionService::class)->request($this->subject, $this->staff);
    $request->update(['status' => GdprDeletionRequest::STATUS_COMPLETED]);

    expect(fn () => app(GdprDeletionService::class)->cancel($request))
        ->toThrow(GdprDeletionRequestNotPendingException::class);

    expect(GdprDeletionRequest::find($request->id))->not->toBeNull();
});

it('anonymizes the users name, email, and phone, and marks the request completed', function () {
    $request = app(GdprDeletionService::class)->request($this->subject, $this->staff);

    app(GdprDeletionService::class)->execute($request);

    $fresh = $this->subject->fresh();
    expect($fresh->name)->toBe('Deleted User')
        ->and($fresh->email)->toBe("deleted-user-{$this->subject->id}@deleted.invalid")
        ->and($fresh->phone)->toBeNull();

    expect($request->fresh()->status)->toBe(GdprDeletionRequest::STATUS_COMPLETED)
        ->and($request->fresh()->completed_at)->not->toBeNull();
});

it('hard-deletes the users video-watch events, scoped to that user only', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-gdpr-test', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    $mine = VideoWatchEvent::create(['user_id' => $this->subject->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $notMine = VideoWatchEvent::create(['user_id' => $otherUser->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);

    $request = app(GdprDeletionService::class)->request($this->subject, $this->staff);
    app(GdprDeletionService::class)->execute($request);

    expect(VideoWatchEvent::find($mine->id))->toBeNull();
    expect(VideoWatchEvent::find($notMine->id))->not->toBeNull();
});

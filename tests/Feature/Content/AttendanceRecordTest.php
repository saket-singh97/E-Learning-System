<?php

use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('creates the attendance_records table with the three-writer polymorphic shape', function () {
    expect(Schema::hasTable('attendance_records'))->toBeTrue();

    foreach ([
        'id', 'tenant_id', 'attendable_type', 'attendable_id', 'user_id',
        'status', 'source', 'marked_by', 'recorded_at', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('attendance_records', $column))->toBeTrue();
    }
});

it('indexes the polymorphic attendable columns', function () {
    $indexes = collect(Schema::getIndexes('attendance_records'))
        ->pluck('columns')
        ->map(fn ($columns) => $columns);

    $hasAttendableIndex = $indexes->contains(fn ($columns) => $columns === ['attendable_type', 'attendable_id']);

    expect($hasAttendableIndex)->toBeTrue();
});

it('mass-assigns and persists an attendance record', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Unit 1', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson 1', 'type' => 'text', 'position' => 0]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $record = AttendanceRecord::create([
        'attendable_type' => 'lesson',
        'attendable_id' => $lesson->id,
        'user_id' => $student->id,
        'status' => 'present',
        'source' => 'video',
        'recorded_at' => now(),
    ]);

    expect($record->fresh())
        ->attendable_type->toBe('lesson')
        ->attendable_id->toBe($lesson->id)
        ->user_id->toBe($student->id)
        ->status->toBe('present')
        ->source->toBe('video')
        ->marked_by->toBeNull()
        ->tenant_id->toBe($this->tenant->id);
});

<?php

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function meteredLesson(string $subdomain, string $bytes = 'fake video bytes'): array
{
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, $bytes);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lesson, $student];
}

function watchUrl(Lesson $lesson, string $subdomain): string
{
    return URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => $subdomain,
        'lesson' => $lesson->id,
    ]);
}

beforeEach(fn () => Storage::fake('local'));

it('increments todays bandwidth row for the streaming tenant by the file size', function () {
    [$tenant, $lesson, $student] = meteredLesson('physics', 'fake video bytes');

    $this->actingAs($student)->get(watchUrl($lesson, 'physics'))->assertOk();

    $row = BandwidthUsageDaily::sole();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->date->toDateString())->toBe(now()->toDateString());
    expect($row->bytes_served)->toBe(strlen('fake video bytes'));
});

it('leaves a second tenants row untouched', function () {
    [$tenantA, $lessonA, $studentA] = meteredLesson('physics');

    app(CurrentTenant::class)->clear();
    [$tenantB, $lessonB, $studentB] = meteredLesson('chemistry');

    $this->actingAs($studentA)->get(watchUrl($lessonA, 'physics'))->assertOk();

    app(CurrentTenant::class)->set($tenantB);
    expect(BandwidthUsageDaily::count())->toBe(0);

    app(CurrentTenant::class)->set($tenantA);
    expect(BandwidthUsageDaily::sole()->tenant_id)->toBe($tenantA->id);
});

it('still streams normally while well under the daily quota', function () {
    // Regression for the pre-S9-B12 behavior this same scenario used to
    // assert the opposite of ("never blocks... no matter how much
    // bandwidth has already been used") — B12's whole point is that it
    // now DOES block once a tenant is actually over its cap (see the test
    // below), so this keeps a "normal usage still works" case in place
    // rather than deleting the coverage entirely.
    [$tenant, $lesson, $student] = meteredLesson('physics');

    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create(['date' => now()->toDateString(), 'bytes_served' => 1000]);

    $this->actingAs($student)->get(watchUrl($lesson, 'physics'))->assertOk();

    expect(BandwidthUsageDaily::sole()->bytes_served)->toBeGreaterThan(1000);
});

it('blocks a stream response once the tenant is over its daily bandwidth quota (S9 B12)', function () {
    [$tenant, $lesson, $student] = meteredLesson('physics');

    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create(['date' => now()->toDateString(), 'bytes_served' => 999_999_999_999]);

    $this->actingAs($student)
        ->get(watchUrl($lesson, 'physics'))
        ->assertStatus(409)
        ->assertSee('bandwidth quota');

    // Refused, not silently metered anyway — the byte count never lands.
    expect(BandwidthUsageDaily::sole()->bytes_served)->toBe(999_999_999_999);
});

it('meters only the bytes actually sent for a partial range request', function () {
    [$tenant, $lesson, $student] = meteredLesson('physics', str_repeat('x', 1000));

    // A browser seeking inside a video issues Range requests. Counting the
    // whole file for each one inflates a single watch into many full-file
    // charges.
    $this->actingAs($student)
        ->withHeaders(['Range' => 'bytes=0-99'])
        ->get(watchUrl($lesson, 'physics'))
        ->assertStatus(206);

    expect(BandwidthUsageDaily::sole()->bytes_served)->toBe(100);
});

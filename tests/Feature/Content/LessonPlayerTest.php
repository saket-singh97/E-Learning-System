<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\LessonPlayer;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('mounts with the viewing students name, email, and a signed stream url', function () {
    $this->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ada Lovelace']);
    $student->assignRole('student');

    Livewire::actingAs($student)
        ->test(LessonPlayer::class, ['lesson' => $lesson])
        ->assertSet('viewerName', 'Ada Lovelace')
        ->assertSet('viewerEmail', $student->email)
        ->assertSee('/watch/'.$lesson->id, false)
        ->assertSee($student->email);
});

it('serves the watch page with the player, volume init, and watermark markup', function () {
    $this->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    // A real stored file, not just a `ready` flag: the watch page gate refuses
    // a lesson whose video_disk_path is empty.
    Storage::fake('local');
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $response = $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}");

    $response->assertOk();
    $response->assertSee('this.$refs.video.volume = 0.7', false);
    $response->assertSee('setInterval(() => this.reposition(), 30000)', false);
});

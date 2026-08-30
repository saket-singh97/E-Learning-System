<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\SyllabusTopic;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\ParentPortal;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->parent = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'password' => bcrypt('secret-password'),
    ]);
    $this->parent->assignRole('parent');
});

it('tells the parent no students are linked yet, rather than showing an empty dashboard', function () {
    Livewire::test(ParentPortal::class)
        ->set('email', $this->parent->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSee('No students are linked to your account yet.');
});

it("shows the linked student's fee status and outstanding invoice after login", function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');
    ParentGuardianLink::create([
        'parent_user_id' => $this->parent->id,
        'student_user_id' => $student->id,
        'relationship' => 'father',
    ]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
        'is_paid' => true,
        'price' => 100,
    ]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
    FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-PARENT-TEST',
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => now()->addWeek()->toDateString(),
        'status' => 'pending',
    ]);

    Livewire::test(ParentPortal::class)
        ->set('email', $this->parent->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSee($student->name)
        ->assertSee('Pending')
        ->assertSee('INV-PARENT-TEST');
});

it('defaults to the first linked student and switches to another on request', function () {
    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Student A']);
    $studentA->assignRole('student');
    ParentGuardianLink::create(['parent_user_id' => $this->parent->id, 'student_user_id' => $studentA->id, 'relationship' => 'father']);

    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Student B']);
    $studentB->assignRole('student');
    ParentGuardianLink::create(['parent_user_id' => $this->parent->id, 'student_user_id' => $studentB->id, 'relationship' => 'father']);

    $component = Livewire::test(ParentPortal::class)
        ->set('email', $this->parent->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSee('Student A')
        ->assertSee('Student B')
        ->assertSet('selectedStudentId', $studentA->id);

    $component->call('selectStudent', $studentB->id)
        ->assertSet('selectedStudentId', $studentB->id);
});

it('shows the linked students syllabus coverage per enrolled course', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');
    ParentGuardianLink::create([
        'parent_user_id' => $this->parent->id,
        'student_user_id' => $student->id,
        'relationship' => 'father',
    ]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    $topic = SyllabusTopic::create(['course_id' => $course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $topic->lessons()->sync([$lesson->id]);
    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $student->id, 'start_s' => 0, 'end_s' => 10]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    Livewire::test(ParentPortal::class)
        ->set('email', $this->parent->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSee('Physics')
        ->assertSee('CBSE Class 10')
        ->assertSee('100.0% complete');
});

it('cannot select a student it is not linked to — no data leaks through the switcher', function () {
    $linkedStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'My Kid']);
    $linkedStudent->assignRole('student');
    ParentGuardianLink::create(['parent_user_id' => $this->parent->id, 'student_user_id' => $linkedStudent->id, 'relationship' => 'father']);

    $unlinkedStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Not My Kid']);
    $unlinkedStudent->assignRole('student');

    // selectStudent() itself doesn't validate ownership — render()'s own
    // firstWhere() against this parent's own linkedStudents() is what
    // actually stops the unlinked id from resolving to any data.
    Livewire::test(ParentPortal::class)
        ->set('email', $this->parent->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->call('selectStudent', $unlinkedStudent->id)
        ->assertSet('selectedStudentId', $unlinkedStudent->id)
        ->assertDontSee('Not My Kid');
});

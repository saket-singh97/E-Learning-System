<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Filament\Resources\FeeInvoices\FeeInvoiceResource;
use App\Domains\Fees\Filament\Resources\FeeInvoices\Pages\ListFeeInvoices;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    $enrolment = app(BatchEnrolmentService::class)->enrol($this->student, $batch);
    $this->invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();

    $this->actingAs($this->admin)
        ->get(FeeInvoiceResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('lists the invoice with its student, batch, amount and status', function () {
    Livewire::test(ListFeeInvoices::class)
        ->assertSee($this->invoice->invoice_number)
        ->assertSee('Morning Batch')
        ->assertSee($this->student->name)
        ->assertSee('1,180.00')
        ->assertSee('pending');
});

it('filters the table down to overdue invoices', function () {
    $this->invoice->update(['status' => 'overdue']);

    $paidCourse = Course::create(['title' => 'Chem 101', 'slug' => 'chem-101', 'status' => 'published']);
    $paidBatch = Batch::create([
        'course_id' => $paidCourse->id,
        'title' => 'Chem Batch',
        'slug' => 'chem-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 500,
    ]);
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStudent->assignRole('student');
    $otherEnrolment = app(BatchEnrolmentService::class)->enrol($otherStudent, $paidBatch);
    $paidInvoice = FeeInvoice::where('enrolment_id', $otherEnrolment->id)->sole();
    $paidInvoice->update(['status' => 'paid', 'paid_at' => now()]);

    Livewire::test(ListFeeInvoices::class)
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords([$this->invoice])
        ->assertCanNotSeeTableRecords([$paidInvoice]);
});

it('links the view action to the invoice download route for the current tenant', function () {
    Livewire::test(ListFeeInvoices::class)
        ->assertTableActionHasUrl('view', "http://physics.lms.test/invoices/{$this->invoice->id}", $this->invoice);
});

it('refuses a non-admin', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get(FeeInvoiceResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertForbidden();
});

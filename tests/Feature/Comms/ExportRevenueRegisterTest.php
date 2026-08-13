<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Comms\Jobs\ExportRevenueRegister;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->invoice = function (string $name = 'Ada Lovelace'): FeeInvoice {
        $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-'.uniqid(), 'status' => 'published']);
        $batch = Batch::create([
            'course_id' => $course->id,
            'title' => 'Morning Batch',
            'slug' => 'batch-'.uniqid(),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
            'is_paid' => true,
            'price' => 1000,
        ]);
        $student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => $name]);
        $student->assignRole('student');
        $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);

        return FeeInvoice::where('enrolment_id', $enrolment->id)->sole();
    };
});

it('writes a csv row per invoice with student, batch, amounts, status, and dates', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->addDays(3)->toDateString()]);

    ExportRevenueRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportRevenueRegister::pathFor($this->tenant->id));

    expect($csv)->toContain($invoice->invoice_number)
        ->toContain('Ada Lovelace')
        ->toContain($invoice->user->email)
        ->toContain('Morning Batch')
        ->toContain('pending');
});

it('reflects a paid invoice status and paid_at once captured', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['status' => 'paid', 'paid_at' => now()]);

    ExportRevenueRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportRevenueRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('paid')
        ->toContain($invoice->fresh()->paid_at->toDateTimeString());
});

it('escapes a student name that would otherwise become a live spreadsheet formula', function () {
    ($this->invoice)('=SUM(1+1)');

    ExportRevenueRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportRevenueRegister::pathFor($this->tenant->id));

    expect($csv)->toContain("'=SUM(1+1)")
        ->not->toContain(",=SUM(1+1)");
});

it('only exports the requested tenants own invoices', function () {
    $ownInvoice = ($this->invoice)('Own Tenant Student');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    ($this->invoice)('Other Tenant Student');
    app(CurrentTenant::class)->set($this->tenant);

    ExportRevenueRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportRevenueRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Own Tenant Student')
        ->not->toContain('Other Tenant Student');
});

it('has retry configured, matching ExportAttendanceRegister and ExportEngagementRegister', function () {
    $job = new ExportRevenueRegister(tenant_id: null);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

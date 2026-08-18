<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use App\Domains\Fees\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
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
        'course_id' => $course->id, 'title' => 'Morning Batch', 'slug' => 'morning-batch',
        'starts_on' => '2026-09-01', 'ends_on' => '2026-12-01', 'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published', 'is_paid' => true, 'price' => 1000,
    ]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    $enrolment = app(BatchEnrolmentService::class)->enrol($this->student, $batch);
    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();

    $this->payment = FeePayment::create([
        'fee_invoice_id' => $invoice->id,
        'provider' => 'razorpay',
        'provider_order_id' => 'order_'.uniqid(),
        'idempotency_key' => 'idem_'.uniqid(),
        'amount' => 1180,
        'status' => 'captured',
    ]);

    $this->actingAs($this->admin)
        ->get(FeePaymentResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('lists the payment with its invoice, student, amount and status', function () {
    Livewire::test(ListFeePayments::class)
        ->assertSee($this->student->name)
        ->assertSee('1,180.00')
        ->assertSee('captured');
});

it('hides a deleted payment by default, shows it under the Trashed filter, and restores it (S12 B13)', function () {
    $this->payment->delete();

    Livewire::test(ListFeePayments::class)
        ->assertCanNotSeeTableRecords([$this->payment]);

    Livewire::test(ListFeePayments::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$this->payment])
        ->callTableAction('restore', $this->payment);

    expect($this->payment->fresh()->deleted_at)->toBeNull();
});

it('refuses a non-admin', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get(FeePaymentResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertForbidden();
});

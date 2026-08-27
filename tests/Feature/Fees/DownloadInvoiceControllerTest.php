<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create([
        'subdomain' => 'physics',
        'gstin' => '27ABCDE1234F1Z5',
        'legal_name' => 'Physics Academy Pvt Ltd',
        'billing_address' => '221B Baker Street, Mumbai',
    ]);
    app(CurrentTenant::class)->set($this->tenant);

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
});

it('lets the invoices own student view it, with every GST-mandated field present', function () {
    $this->actingAs($this->student)
        ->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertOk()
        ->assertSee($this->invoice->invoice_number)
        ->assertSee('27ABCDE1234F1Z5')
        ->assertSee('Physics Academy Pvt Ltd')
        ->assertSee('221B Baker Street, Mumbai')
        ->assertSee('1,180.00')
        ->assertSee($this->student->name)
        ->assertSee($this->student->email);
});

it('lets an instructor view any students invoice', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertOk();
});

it('refuses a different student, even one that exists in the same tenant', function () {
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertForbidden();
});

it('refuses an instructor from a different tenant even though the role matches', function () {
    // Spatie roles carry no tenant_id, and SESSION_DOMAIN is a shared
    // parent domain (EnsureRoleTest's own precedent) — an instructor
    // logged in at a different tenant must not pass just because
    // "instructor" matches the role check.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $foreignInstructor = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $foreignInstructor->assignRole('instructor');

    $this->actingAs($foreignInstructor)
        ->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertForbidden();
});

it('refuses a guest', function () {
    $this->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertForbidden();
});

it('shows a discount line and the coupon code once one has been applied', function () {
    $coupon = FeeCoupon::create([
        'code' => 'WELCOME10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'status' => 'active',
    ]);
    app(FeeInvoiceService::class)->applyCoupon($this->invoice, $coupon);

    $this->actingAs($this->student)
        ->get("http://physics.lms.test/invoices/{$this->invoice->id}")
        ->assertOk()
        ->assertSee('WELCOME10')
        ->assertSee('100.00');
});

it('renders the tenants own configured tax label and currency once set, instead of GST/rupees', function () {
    $vatTenant = Tenant::factory()->create([
        'subdomain' => 'chemistry',
        'tax_rate' => 20,
        'tax_label' => 'VAT',
        'default_currency' => 'USD',
    ]);
    app(CurrentTenant::class)->set($vatTenant);

    $course = Course::create(['title' => 'Chem 101', 'slug' => 'chem-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
    ]);
    $student = User::factory()->create(['tenant_id' => $vatTenant->id]);
    $student->assignRole('student');
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();

    $this->actingAs($student)
        ->get("http://chemistry.lms.test/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('VAT (20%)')
        ->assertSee('$1,200.00')
        ->assertDontSee('GST (')
        ->assertDontSee('₹');
});

it('renders "Not registered" instead of a blank GSTIN for a tenant that has not set one', function () {
    $bareTenant = Tenant::factory()->create(['subdomain' => 'chemistry', 'gstin' => null]);
    app(CurrentTenant::class)->set($bareTenant);

    $course = Course::create(['title' => 'Chem 101', 'slug' => 'chem-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 500,
    ]);
    $student = User::factory()->create(['tenant_id' => $bareTenant->id]);
    $student->assignRole('student');
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();

    $this->actingAs($student)
        ->get("http://chemistry.lms.test/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('Not registered');
});

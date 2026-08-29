<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\FeeCheckout;
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

    $this->enrolment = app(BatchEnrolmentService::class)->enrol($this->student, $batch);
});

it('shows the pending invoices total with a Pay now button', function () {
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->assertSee('1,180.00')
        ->assertSee('Pay now');
});

it('starts a checkout order and shows the created order instead of the Pay now button', function () {
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->call('startCheckout')
        ->assertSee('Order created')
        ->assertDontSee('Pay now');

    expect(FeePayment::count())->toBe(1);
});

it('carries a selected EMI duration through to the created order (S21 B9)', function () {
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->set('emiDurationMonths', 6)
        ->call('startCheckout')
        ->assertSee('Order created');

    expect(FeePayment::sole()->emi_duration_months)->toBe(6);
});

it('leaves emi_duration_months null for a plain Pay now checkout (S21 B9)', function () {
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->call('startCheckout');

    expect(FeePayment::sole()->emi_duration_months)->toBeNull();
});

it('polls for a live refresh while a payment is genuinely in flight', function () {
    // No broadcasting infrastructure exists (BROADCAST_CONNECTION is
    // 'log') — wire:poll is this block's own pragmatic live-refresh
    // mechanism instead.
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->call('startCheckout')
        ->assertSee('wire:poll', false);
});

it('stops offering the poll once the invoice is fully paid, showing a paid message instead', function () {
    $invoice = FeeInvoice::where('enrolment_id', $this->enrolment->id)->sole();
    $invoice->update(['status' => 'paid', 'paid_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->assertDontSee('wire:poll', false)
        ->assertSee('All invoices for this enrolment are paid.');
});

it('applies a valid coupon code, reducing the shown total', function () {
    FeeCoupon::create([
        'code' => 'WELCOME10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'status' => 'active',
    ]);

    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->set('couponCode', 'welcome10')
        ->call('applyCoupon')
        ->assertHasNoErrors()
        ->assertSee('1,062.00')
        ->assertSee('Coupon applied');

    expect(FeeInvoice::where('enrolment_id', $this->enrolment->id)->sole()->coupon_id)->not->toBeNull();
});

it('shows an error for a coupon code that does not exist', function () {
    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->set('couponCode', 'NOTREAL')
        ->call('applyCoupon')
        ->assertHasErrors('couponCode');

    expect(FeeInvoice::where('enrolment_id', $this->enrolment->id)->sole()->coupon_id)->toBeNull();
});

it('shows the earliest-due instalment first, then the next one once the first is paid', function () {
    $batch = Batch::create([
        'course_id' => Course::create(['title' => 'Chem 101', 'slug' => 'chem-101', 'status' => 'published'])->id,
        'title' => 'Instalment Batch',
        'slug' => 'instalment-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
        'instalment_plan' => [
            ['percent' => 50, 'days_after_enrolment' => 0],
            ['percent' => 50, 'days_after_enrolment' => 30],
        ],
    ]);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);

    Livewire::actingAs($student)
        ->test(FeeCheckout::class, ['enrolment' => $enrolment])
        ->assertSee('Instalment 1 of 2')
        ->assertDontSee('Instalment 2 of 2');

    FeeInvoice::where('enrolment_id', $enrolment->id)
        ->where('description', 'Instalment 1 of 2')
        ->sole()
        ->update(['status' => 'paid', 'paid_at' => now()]);

    Livewire::actingAs($student)
        ->test(FeeCheckout::class, ['enrolment' => $enrolment])
        ->assertSee('Instalment 2 of 2');
});

it('lists the invoice with a link to view/print it', function () {
    $invoice = FeeInvoice::where('enrolment_id', $this->enrolment->id)->sole();

    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->assertSee('Your invoices')
        ->assertSee('View / print')
        ->assertSee("/invoices/{$invoice->id}", false);
});

it('shows an error for an expired coupon code', function () {
    FeeCoupon::create([
        'code' => 'EXPIRED',
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'status' => 'active',
        'expires_at' => now()->subDay(),
    ]);

    Livewire::actingAs($this->student)
        ->test(FeeCheckout::class, ['enrolment' => $this->enrolment])
        ->set('couponCode', 'EXPIRED')
        ->call('applyCoupon')
        ->assertHasErrors('couponCode');
});

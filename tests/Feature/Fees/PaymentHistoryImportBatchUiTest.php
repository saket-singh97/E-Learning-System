<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\Pages\CreatePaymentHistoryImportBatch;
use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\PaymentHistoryImportBatchResource;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\PaymentHistoryImportBatch;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'asha@example.com']);
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(PaymentHistoryImportBatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('uploads a CSV as a real run and creates the invoice/payment pair', function () {
    $tempPath = 'payment-history-imports/tmp/history.csv';
    Storage::disk('local')->put($tempPath, "email,batch,invoice_number,amount,gst_amount,total_amount,paid_at\nasha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n");

    Livewire::test(CreatePaymentHistoryImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PaymentHistoryImportBatch::sole()->status)->toBe('completed');
    expect(FeeInvoice::where('invoice_number', 'INV-OLD-1')->where('status', 'paid')->exists())->toBeTrue();
});

it('uploads a CSV as a dry run and creates nothing', function () {
    $tempPath = 'payment-history-imports/tmp/history.csv';
    Storage::disk('local')->put($tempPath, "email,batch,invoice_number,amount,gst_amount,total_amount,paid_at\nasha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n");

    Livewire::test(CreatePaymentHistoryImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PaymentHistoryImportBatch::sole()->status)->toBe('dry_run');
    expect(FeeInvoice::count())->toBe(0);
});

it('downloads the error report only for the tenant that owns it', function () {
    $tempPath = 'payment-history-imports/tmp/history.csv';
    Storage::disk('local')->put($tempPath, "email,batch,invoice_number,amount,gst_amount,total_amount,paid_at\nghost@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n");

    Livewire::test(CreatePaymentHistoryImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create');

    $batch = PaymentHistoryImportBatch::sole();

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/payment-history-imports/{$batch->id}/error-report")
        ->assertOk();

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/payment-history-imports/{$batch->id}/error-report")
        ->assertForbidden();
});

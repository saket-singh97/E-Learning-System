<?php

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Content\Models\Course;
use App\Domains\Security\Jobs\GenerateGdprExport;
use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Security\Models\LoginHistory;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->request = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'requested_by_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'status' => GdprExportRequest::STATUS_PENDING,
    ]);

    app(CurrentTenant::class)->clear();
});

function openGdprExportZip(GdprExportRequest $request): ZipArchive
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($request->fresh()->export_disk_path));

    return $zip;
}

it('marks the request ready with a zip containing every expected file, even for a user with no cross-domain data', function () {
    (new GenerateGdprExport($this->request->id, $this->tenant->id))->handle();

    $fresh = $this->request->fresh();
    expect($fresh->status)->toBe(GdprExportRequest::STATUS_READY)
        ->and($fresh->export_disk_path)->toBe(GenerateGdprExport::pathFor($this->request->id))
        ->and($fresh->expires_at)->not->toBeNull();

    Storage::disk('local')->assertExists($fresh->export_disk_path);

    $zip = openGdprExportZip($this->request);
    foreach ([
        'profile.csv', 'enrolments.csv', 'assignment_submissions.csv', 'certificates.csv',
        'fee_invoices.csv', 'fee_payments.csv', 'quiz_attempts.csv', 'login_history.csv', 'device_sessions.csv',
    ] as $entry) {
        expect($zip->locateName($entry))->not->toBeFalse();
    }
    expect($zip->getFromName('profile.csv'))->toContain($this->user->email);
    $zip->close();
});

it('includes only the subject users own rows, not another users', function () {
    LoginHistory::withoutEvents(fn () => LoginHistory::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'ip_address' => '203.0.113.5',
        'successful' => true,
    ]));
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    LoginHistory::withoutEvents(fn () => LoginHistory::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $otherUser->id,
        'ip_address' => '198.51.100.9',
        'successful' => true,
    ]));

    (new GenerateGdprExport($this->request->id, $this->tenant->id))->handle();

    $zip = openGdprExportZip($this->request);
    $csv = $zip->getFromName('login_history.csv');
    $zip->close();

    expect($csv)->toContain('203.0.113.5')->not->toContain('198.51.100.9');
});

it('includes a device session row scoped to the subject user', function () {
    DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'device_fingerprint' => 'fp-export-test',
        'device_label' => 'Test Device',
        'last_seen_at' => now(),
    ]));

    (new GenerateGdprExport($this->request->id, $this->tenant->id))->handle();

    $zip = openGdprExportZip($this->request);
    $csv = $zip->getFromName('device_sessions.csv');
    $zip->close();

    expect($csv)->toContain('Test Device');
});

it('includes a certificate row via the course join', function () {
    app(CurrentTenant::class)->set($this->tenant);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-export-test', 'status' => 'published']);
    Certificate::create([
        'user_id' => $this->user->id,
        'course_id' => $course->id,
        'verification_id' => 'CERT-EXPORTTEST1',
        'final_score' => 91.25,
        'pdf_disk_path' => 'certificates/x/y/z.pdf',
        'issued_at' => now(),
    ]);
    app(CurrentTenant::class)->clear();

    (new GenerateGdprExport($this->request->id, $this->tenant->id))->handle();

    $zip = openGdprExportZip($this->request);
    $csv = $zip->getFromName('certificates.csv');
    $zip->close();

    expect($csv)->toContain('CERT-EXPORTTEST1');
});

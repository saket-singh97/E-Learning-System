<?php

use App\Domains\Security\Jobs\PruneAuditLogEntries;
use App\Domains\Security\Models\AuditLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function auditLogEntryAgedDays(int $days): AuditLogEntry
{
    $entry = AuditLogEntry::create(['action' => 'test.action']);
    $entry->forceFill(['created_at' => now()->subDays($days)])->save();

    return $entry;
}

it('hard-deletes entries older than the configured retention window', function () {
    config(['security.audit_log_retention_days' => 365]);
    $old = auditLogEntryAgedDays(400);

    (new PruneAuditLogEntries)->handle();

    expect(AuditLogEntry::find($old->id))->toBeNull();
});

it('keeps entries within the retention window', function () {
    config(['security.audit_log_retention_days' => 365]);
    $recent = auditLogEntryAgedDays(30);

    (new PruneAuditLogEntries)->handle();

    expect(AuditLogEntry::find($recent->id))->not->toBeNull();
});

it('keeps an entry exactly at the cutoff boundary, only prunes strictly older', function () {
    config(['security.audit_log_retention_days' => 365]);
    $atCutoff = auditLogEntryAgedDays(365);

    (new PruneAuditLogEntries)->handle();

    expect(AuditLogEntry::find($atCutoff->id))->not->toBeNull();
});

it('respects a custom retention window from config', function () {
    config(['security.audit_log_retention_days' => 30]);
    $old = auditLogEntryAgedDays(31);
    $recent = auditLogEntryAgedDays(10);

    (new PruneAuditLogEntries)->handle();

    expect(AuditLogEntry::find($old->id))->toBeNull()
        ->and(AuditLogEntry::find($recent->id))->not->toBeNull();
});

it('runs cleanly with no entries at all', function () {
    expect(fn () => (new PruneAuditLogEntries)->handle())->not->toThrow(Throwable::class);
});

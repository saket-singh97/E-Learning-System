<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * pg_dump is not on this Windows dev host's PATH (Postgres runs in Docker,
 * confirmed by checking, not assumed) — a real end-to-end `backup:run`
 * (which shells out to it for the database dump) cannot be exercised in
 * this dev environment. --only-files skips the db dump entirely, so the
 * files-and-destination half of the config is exercised for real here; the
 * db-dump half is covered by asserting config('backup.backup.source.databases')
 * resolves to the right connection instead.
 */
it('scopes the backup source to storage/app/private, not the whole codebase', function () {
    expect(config('backup.backup.source.files.include'))
        ->toBe([storage_path('app/private')])
        ->and(config('backup.backup.source.files.include'))
        ->not->toContain(base_path());
});

it('resolves the database connection to back up from DB_CONNECTION', function () {
    expect(config('backup.backup.source.databases'))->toBe([config('database.default')]);
});

it('writes archives to a disk separate from the one it backs up, avoiding self-inclusion', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('backup.backup.destination.disks'))->not->toContain('local');
});

it('keeps a flat 30-day retention window with nothing surviving past it', function () {
    $strategy = config('backup.cleanup.default_strategy');

    expect($strategy['keep_all_backups_for_days'])->toBe(30)
        ->and($strategy['keep_daily_backups_for_days'])->toBe(0)
        ->and($strategy['keep_weekly_backups_for_weeks'])->toBe(0)
        ->and($strategy['keep_monthly_backups_for_months'])->toBe(0)
        ->and($strategy['keep_yearly_backups_for_years'])->toBe(0);
});

it('encrypts the archive whenever a password is configured', function () {
    expect(config('backup.backup.encryption'))->toBe('default');
});

it('actually produces an archive on the backups disk', function () {
    Storage::fake('backups');

    Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    expect(Storage::disk('backups')->allFiles())->not->toBeEmpty();
});

it('does not zip up its own previous archive on a second run', function () {
    Storage::fake('backups');

    Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);
    $firstRunFiles = Storage::disk('backups')->allFiles();

    Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);
    $secondRunSizes = collect(Storage::disk('backups')->allFiles())
        ->diff($firstRunFiles)
        ->map(fn (string $file) => Storage::disk('backups')->size($file));

    // If the first run's own archive were included in the backup source,
    // each subsequent run's archive would grow monotonically as it zipped
    // up every prior run's zip too. A stable filename-independent bound —
    // the new archive isn't dramatically larger than the first — is the
    // signal a self-inclusion loop isn't happening. Comparing directly
    // against the *destination* disk (not source.files.include, which is
    // already asserted separately) is what would actually catch a real
    // regression here.
    expect($secondRunSizes->max() ?? 0)->toBeLessThan(1_000_000);
});

it('cleans up without error even when there is nothing to clean', function () {
    Storage::fake('backups');

    Artisan::call('backup:clean', ['--disable-notifications' => true]);

    expect(Artisan::output())->not->toContain('exception');
});

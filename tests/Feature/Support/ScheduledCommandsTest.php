<?php

use Illuminate\Support\Facades\Artisan;

/**
 * S9 B14 review pass found docs/FEATURES.md's T2 row ("failed-job
 * retention") wasn't actually true — the failed_jobs table was
 * provisioned (Laravel's own default migration) but nothing ever pruned
 * it. Scheduled in bootstrap/app.php; this just confirms the underlying
 * vendor command itself runs cleanly, same posture
 * BackupConfigurationTest's own "cleans up without error" case already
 * established for backup:clean.
 */
it('prunes failed jobs without error even when there is nothing to prune', function () {
    Artisan::call('queue:prune-failed', ['--hours' => 720]);

    expect(Artisan::output())->not->toContain('exception');
});

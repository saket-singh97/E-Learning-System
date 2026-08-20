<?php

namespace App\Domains\Content\Jobs;

use App\Domains\Content\Models\LessonStreamSession;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * D9 decision 7 — a row past the staleness window already stopped
 * counting as "active" for ConcurrentStreamGuard's own read-time check;
 * this sweep just closes it (ended_at) for reporting/cleanliness. Same
 * unscoped, CurrentTenant-cleared, real-ShouldQueue shape every other
 * daily sweep in this app uses (ExpirePendingEnrolments, etc.) — see
 * that job's own docblock for why CurrentTenant must be explicitly
 * cleared rather than assumed unbound.
 */
class CloseStaleLessonStreamSessions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        LessonStreamSession::query()
            ->whereNull('ended_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds(config('content.stream_session_timeout_seconds')))
            ->update(['ended_at' => now()]);
    }
}

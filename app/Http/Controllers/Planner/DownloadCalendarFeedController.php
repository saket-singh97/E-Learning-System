<?php

namespace App\Http\Controllers\Planner;

use App\Domains\Planner\Services\PlannerAggregator;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S19 N12 pt.3 (design doc §4 decision 4) — `signed`-middleware-only, same
 * shape `DownloadReportCardController` already established: possessing the
 * exact signed URL is the access token, no separate auth/role check. Unlike
 * that one-time PDF download, this URL has **no expiration**
 * (`URL::signedRoute()`, not `temporarySignedRoute()`) — a calendar app
 * subscribes once and polls the same URL indefinitely, so a 7-day-style
 * TTL would silently break every subscription a week after it was added.
 *
 * `{user}` read off the route rather than method-injected — CLAUDE.md's
 * domain-route splicing gotcha on `Route::domain()` routes.
 */
class DownloadCalendarFeedController extends Controller
{
    public function __invoke(Request $request, PlannerAggregator $aggregator): Response
    {
        $user = User::findOrFail($request->route('user'));

        $events = $aggregator->eventsFor($user, now()->subMonths(3), now()->addMonths(6));

        $ics = $this->buildIcs($events);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendar.ics"',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{source: string, id: int, title: string, starts_at: \Carbon\Carbon, ends_at: ?\Carbon\Carbon}>  $events
     */
    protected function buildIcs($events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LMS//Calendar//EN', 'CALSCALE:GREGORIAN'];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:{$event['source']}-{$event['id']}@lms";
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$event['starts_at']->clone()->utc()->format('Ymd\THis\Z');

            if ($event['ends_at'] !== null) {
                $lines[] = 'DTEND:'.$event['ends_at']->clone()->utc()->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:'.$this->escapeIcsText($event['title']);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    protected function escapeIcsText(string $value): string
    {
        return str_replace([',', ';', "\n"], ['\,', '\;', '\\n'], $value);
    }
}

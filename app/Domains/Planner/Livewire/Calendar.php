<?php

namespace App\Domains\Planner\Livewire;

use App\Domains\Planner\Models\PlannerEvent;
use App\Domains\Planner\Services\PlannerAggregator;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * S19 N12 pt.2 — one month at a time, navigable, backed by
 * `PlannerAggregator`. Create/edit/delete only ever touch the viewer's own
 * `PlannerEvent` rows (`ownEvent()` re-verifies ownership on every call,
 * not just when a form first opens — same client-settable-id discipline
 * `SurveyRunner`/`HelpDeskTicketForm` already established); batch sessions
 * and assignment due dates are read-only here, they belong to other
 * domains.
 */
class Calendar extends Component
{
    public string $month;

    public ?int $editingEventId = null;

    public string $title = '';

    public string $description = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->addMonthNoOverflow()->format('Y-m');
    }

    public function events(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        $from = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $to = $from->clone()->endOfMonth();

        return app(PlannerAggregator::class)->eventsFor($viewer, $from, $to);
    }

    public function createEvent(): void
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return;
        }

        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'startsAt' => 'required|date',
            'endsAt' => 'nullable|date|after:startsAt',
        ]);

        PlannerEvent::create([
            'user_id' => $viewer->id,
            'title' => $this->title,
            'description' => $this->description !== '' ? $this->description : null,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt !== '' ? $this->endsAt : null,
        ]);

        $this->resetForm();
    }

    public function editEvent(int $eventId): void
    {
        $event = $this->ownEvent($eventId);

        if ($event === null) {
            return;
        }

        $this->editingEventId = $event->id;
        $this->title = $event->title;
        $this->description = (string) $event->description;
        $this->startsAt = $event->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $event->ends_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function updateEvent(): void
    {
        if ($this->editingEventId === null) {
            return;
        }

        $event = $this->ownEvent($this->editingEventId);

        if ($event === null) {
            return;
        }

        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'startsAt' => 'required|date',
            'endsAt' => 'nullable|date|after:startsAt',
        ]);

        $event->update([
            'title' => $this->title,
            'description' => $this->description !== '' ? $this->description : null,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt !== '' ? $this->endsAt : null,
        ]);

        $this->resetForm();
    }

    public function deleteEvent(int $eventId): void
    {
        $this->ownEvent($eventId)?->delete();

        if ($this->editingEventId === $eventId) {
            $this->resetForm();
        }
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    protected function ownEvent(int $eventId): ?PlannerEvent
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return null;
        }

        return PlannerEvent::where('user_id', $viewer->id)->find($eventId);
    }

    protected function resetForm(): void
    {
        $this->editingEventId = null;
        $this->title = '';
        $this->description = '';
        $this->startsAt = '';
        $this->endsAt = '';
    }

    /**
     * Permanently signed (design doc §4 decision 4) — a calendar app
     * subscribes once and polls this same URL indefinitely, unlike
     * `MonthlyReportCard`'s own 7-day-expiring one-time download link.
     */
    public function feedUrl(): ?string
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(CurrentTenant::class)->isBound()) {
            return null;
        }

        return URL::signedRoute('calendar.feed', [
            'subdomain' => app(CurrentTenant::class)->get()->subdomain,
            'user' => $viewer->id,
        ]);
    }

    public function render(): View
    {
        return view('planner::calendar', [
            'events' => $this->events(),
            'canRespond' => auth()->user() !== null,
            'feedUrl' => $this->feedUrl(),
        ]);
    }
}

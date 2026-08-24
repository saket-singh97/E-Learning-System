<?php

namespace App\Livewire;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Services\GeofenceCheckInService;
use App\Domains\Content\Models\AttendanceRecord;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S16 B3 (H11 pt.2) — one shared component, embedded wherever a student
 * needs to geofence check in for a BatchSession, same "one component,
 * embedded wherever needed" posture NoticeBoard (S15 B17) already
 * established. A batch with no BatchGeofence row has geofencing off —
 * every check-in is accepted (design doc §3.2's own opt-in framing), not
 * an error state.
 */
class GeofenceCheckIn extends Component
{
    public BatchSession $session;

    public ?string $error = null;

    public bool $checkedIn = false;

    public function mount(BatchSession $session): void
    {
        $this->session = $session;
    }

    /**
     * updateOrCreate keyed the same way BatchSessionsRelationManager's own
     * markAttendance action already does — a second check-in for the same
     * session refreshes the existing row instead of duplicating it.
     */
    public function checkIn(float $latitude, float $longitude): void
    {
        $this->error = null;

        $viewer = auth()->user();

        if ($viewer === null) {
            return;
        }

        $geofence = BatchGeofence::where('batch_id', $this->session->batch_id)->first();

        if ($geofence !== null && ! app(GeofenceCheckInService::class)->isWithinRadius($geofence, $latitude, $longitude)) {
            $this->error = "You're outside this session's check-in radius.";

            return;
        }

        AttendanceRecord::updateOrCreate(
            [
                'attendable_type' => BatchSession::class,
                'attendable_id' => $this->session->id,
                'user_id' => $viewer->id,
            ],
            [
                'role' => 'student',
                'status' => 'present',
                'source' => 'geofence_checkin',
                'recorded_at' => now(),
            ],
        );

        event(new AttendanceRecorded($viewer->id, $this->session->tenant_id, 'present'));

        $this->checkedIn = true;
    }

    public function geolocationUnavailable(): void
    {
        $this->error = "Could not read your location — check your browser's location permission and try again.";
    }

    public function render(): View
    {
        return view('livewire.geofence-check-in');
    }
}

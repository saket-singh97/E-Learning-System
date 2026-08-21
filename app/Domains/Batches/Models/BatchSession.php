<?php

namespace App\Domains\Batches\Models;

use App\Domains\Batches\Events\LiveSessionRoomCreated;
use App\Domains\Content\Models\Lesson;
use App\Integrations\Drivers\ExternalLinkProvider;
use App\Integrations\Drivers\GoogleMeetProvider;
use App\Integrations\Drivers\ZoomMeetingsProvider;
use App\Integrations\LiveClassProvider;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class BatchSession extends Model
{
    use BelongsToTenant;

    /**
     * Values `live_provider` may hold.
     *
     * @var array<int, string>
     */
    public const LIVE_PROVIDERS = ['external_link', 'zoom_video_sdk', 'zoom_meetings_oauth', 'google_meet'];

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_LIVE = 'live';

    public const STATE_ENDED = 'ended';

    public const STATE_RECORDED = 'recorded';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'lesson_id',
        'title',
        'starts_at',
        'duration_minutes',
        'position',
        'live_provider',
        'provider_meeting_ref',
        'room_created_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'position' => 'integer',
            'room_created_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Batches -> Content model read, same precedent as Batch::course() —
     * a live_session Lesson is the topic identity, this row is one batch's
     * scheduled occurrence of it (design doc §2.4 decision 2).
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Idempotent: only the first call mints a room and fires the event, so
     * saving an already-roomed session again (e.g. a title edit) is a no-op.
     */
    public function createLiveRoom(): void
    {
        if ($this->room_created_at !== null) {
            return;
        }

        $ref = $this->resolveLiveClassProvider()->createRoom($this);

        $this->update([
            'provider_meeting_ref' => $ref,
            'room_created_at' => now(),
        ]);

        event(new LiveSessionRoomCreated($this->id, $this->tenant_id));
    }

    /**
     * Derived, never stored — SCHEDULED/LIVE/ENDED/RECORDED purely from
     * wall-clock time vs starts_at/duration_minutes, plus the linked
     * Lesson's upload status once the window has passed (design doc §3.1
     * boundary table).
     */
    public function liveState(): string
    {
        $now = now();
        $endsAt = $this->starts_at->clone()->addMinutes($this->duration_minutes);

        if ($now->lt($this->starts_at)) {
            return self::STATE_SCHEDULED;
        }

        if ($now->lte($endsAt)) {
            return self::STATE_LIVE;
        }

        return $this->lesson?->video_status === 'ready' ? self::STATE_RECORDED : self::STATE_ENDED;
    }

    /**
     * Per-session provider choice, not a single app-wide default — a
     * teacher picks a provider per BatchSession, so the driver is resolved
     * from that column. external_link needs no account and always resolves
     * to the real class. zoom_video_sdk resolves through the app-wide
     * LiveClassProvider container binding instead of ZoomVideoSdkProvider
     * directly — that binding stays FakeLiveClassDriver until real
     * ZOOM_SDK_KEY/SECRET exist (AppServiceProvider, same posture as
     * AiProvider/VideoProvider), so choosing Zoom today safely degrades to
     * the Fake driver rather than throwing on missing credentials.
     * zoom_meetings_oauth and google_meet (G8) both resolve to their real
     * driver directly instead — no Fake-degrade precedent to preserve for
     * either, so a missing connection/credential throws a clear
     * RuntimeException instead of silently faking a room.
     */
    public function resolveLiveClassProvider(): LiveClassProvider
    {
        return match ($this->live_provider) {
            'external_link' => app(ExternalLinkProvider::class),
            'zoom_video_sdk' => app(LiveClassProvider::class),
            'zoom_meetings_oauth' => app(ZoomMeetingsProvider::class),
            'google_meet' => app(GoogleMeetProvider::class),
            default => throw new InvalidArgumentException("Unknown live_provider [{$this->live_provider}]."),
        };
    }
}

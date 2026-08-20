<?php

namespace App\Domains\Content\Models;

use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\StorageLimitExceededException;
use App\Integrations\VideoProvider;
use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Lesson extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const TYPES = ['video', 'pdf', 'text', 'embed', 'quiz', 'live_session', 'assignment'];

    public const VIDEO_STATUSES = ['uploading', 'ready', 'failed'];

    public const SUBTITLE_STATUSES = ['none', 'generating', 'ready', 'failed'];

    /**
     * S9 B6's own allow-list decision — arbitrary iframe embeds are an
     * XSS/clickjacking surface this app hasn't had to reason about before,
     * so `embed_url` is validated against this list at save time
     * (LessonResource's own form rule), not trusted at render time.
     */
    public const EMBEDDABLE_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'docs.google.com',
    ];

    protected array $sanitizable = ['text_body'];

    protected $fillable = [
        'tenant_id',
        'course_section_id',
        'title',
        'type',
        'position',
        'video_disk_path',
        'video_duration_seconds',
        'video_status',
        'scheduled_at',
        'is_free_preview',
        'text_body',
        'embed_url',
        'pdf_disk_path',
        'subtitle_disk_path',
        'subtitle_status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'is_free_preview' => 'boolean',
            'video_duration_seconds' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    /**
     * Lessons this one requires — writes go through PrerequisiteGraph, not
     * a direct sync()/attach() here, so the circular-dependency check
     * (design doc decision 6) can't be bypassed.
     */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'lesson_prerequisites', 'lesson_id', 'prerequisite_lesson_id');
    }

    /**
     * VideoProvider::delete() existed from Block 1 but nothing ever called
     * it: force-deleting a lesson, or replacing its video with a different
     * file extension (attachUploadedVideo() computes the stored path from
     * the extension, so mp4 -> webm produces a different filename), left
     * the previous file on disk forever. Both paths now call it.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (self $lesson): void {
            app(VideoProvider::class)->delete($lesson);

            if ($lesson->pdf_disk_path) {
                // S11 B4 — release storage only on a real delete (a force
                // delete), not a soft delete that leaves the file on disk
                // untouched (design doc §3.3's explicit rule).
                $bytes = Storage::disk('local')->exists($lesson->pdf_disk_path)
                    ? Storage::disk('local')->size($lesson->pdf_disk_path)
                    : 0;

                Storage::disk('local')->delete($lesson->pdf_disk_path);

                app(StorageQuota::class)->adjust(Tenant::findOrFail($lesson->tenant_id), -$bytes);
            }
        });
    }

    public function isScheduledForFuture(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isFuture();
    }

    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }

    /**
     * The video upload field stores the file to a temp path on the 'local'
     * disk during form dehydration, before this lesson's id is known. This
     * re-wraps that already-stored file as an UploadedFile so VideoProvider's
     * interface (built for raw HTTP uploads, matching BrandingService) can
     * move it to its final tenant/lesson-scoped path.
     */
    public function attachUploadedVideo(string $tempDiskPath): void
    {
        $file = new UploadedFile(
            Storage::disk('local')->path($tempDiskPath),
            basename($tempDiskPath),
            test: true,
        );

        $previousDiskPath = $this->video_disk_path;
        $video = app(VideoProvider::class);

        $diskPath = $video->store($file, $this);

        Storage::disk('local')->delete($tempDiskPath);

        // Re-uploading (e.g. mp4 replaced with webm) produces a different
        // stored filename since the path is derived from the extension —
        // without this the old file just sits on disk forever. $this still
        // carries the previous path until update() below overwrites it.
        if ($previousDiskPath !== null && $previousDiskPath !== $diskPath) {
            $video->delete($this);
        }

        $this->update([
            'video_disk_path' => $diskPath,
            'video_status' => 'ready',
            // A re-upload (mp4 replaced with webm, or just a different cut)
            // invalidates whatever duration the previous file reported —
            // the heartbeat re-populates it from the new file on next watch.
            'video_duration_seconds' => null,
        ]);

        event(new LessonVideoReady($this->id, $this->tenant_id));
    }

    /**
     * Same disk, same tenant/lesson-scoped directory shape
     * attachUploadedVideo() already uses — deliberately a separate column
     * (pdf_disk_path) rather than reusing video_disk_path, so a lesson's
     * stored file path always says what kind of file it actually is.
     */
    public function attachUploadedPdf(string $tempDiskPath): void
    {
        // S11 B4 (Q2) — same deliberate cross-domain call B3 already
        // established for SeatQuota: a Model resolving a Billing service
        // via app() (the same pattern this file's own attachUploadedVideo()
        // already uses for VideoProvider), since a Model can't take
        // constructor-injected dependencies.
        $storageQuota = app(StorageQuota::class);
        $tenant = Tenant::findOrFail($this->tenant_id);
        $newBytes = Storage::disk('local')->size($tempDiskPath);

        if ($newBytes > $storageQuota->remainingFor($tenant)) {
            throw StorageLimitExceededException::forTenant($tenant->id);
        }

        // Read the previous file's size before it's overwritten — the
        // computed path is the same for a same-extension re-upload
        // (overwrite in place), so without this the counter would grow on
        // every re-upload even though disk usage didn't actually increase.
        $previousBytes = ($this->pdf_disk_path && Storage::disk('local')->exists($this->pdf_disk_path))
            ? Storage::disk('local')->size($this->pdf_disk_path)
            : 0;

        $extension = pathinfo($tempDiskPath, PATHINFO_EXTENSION) ?: 'pdf';
        $diskPath = "pdfs/{$this->tenant_id}/{$this->id}.{$extension}";

        Storage::disk('local')->move($tempDiskPath, $diskPath);

        $this->update(['pdf_disk_path' => $diskPath]);

        $storageQuota->adjust($tenant, $newBytes - $previousBytes);
    }

    /**
     * Instructor-edited subtitle text (LessonResource's own Filament
     * textarea, S14 B3) overwrites the same VTT file
     * GenerateLessonSubtitles (S14 B2) auto-generates — same
     * subtitles/{tenant_id}/{lesson_id}.vtt convention, so the player's
     * existing <track> src never needs to change underneath it.
     */
    public function attachSubtitleText(string $vtt): void
    {
        $diskPath = "subtitles/{$this->tenant_id}/{$this->id}.vtt";

        Storage::disk('local')->put($diskPath, $vtt);

        $this->update([
            'subtitle_disk_path' => $diskPath,
            'subtitle_status' => 'ready',
        ]);
    }
}

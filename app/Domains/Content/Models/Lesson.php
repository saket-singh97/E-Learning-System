<?php

namespace App\Domains\Content\Models;

use App\Domains\Content\Events\LessonVideoReady;
use App\Integrations\VideoProvider;
use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Lesson extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const TYPES = ['video', 'pdf', 'text', 'embed', 'quiz', 'live_session', 'assignment'];

    public const VIDEO_STATUSES = ['uploading', 'ready', 'failed'];

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
                Storage::disk('local')->delete($lesson->pdf_disk_path);
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
        $extension = pathinfo($tempDiskPath, PATHINFO_EXTENSION) ?: 'pdf';
        $diskPath = "pdfs/{$this->tenant_id}/{$this->id}.{$extension}";

        Storage::disk('local')->move($tempDiskPath, $diskPath);

        $this->update(['pdf_disk_path' => $diskPath]);
    }
}

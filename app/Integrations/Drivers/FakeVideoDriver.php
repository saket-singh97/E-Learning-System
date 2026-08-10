<?php

namespace App\Integrations\Drivers;

use App\Domains\Content\Models\Lesson;
use App\Exceptions\InvalidFileTypeException;
use App\Integrations\VideoProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FakeVideoDriver implements VideoProvider
{
    /** @var array<int, string> */
    public const ALLOWED_EXTENSIONS = ['mp4', 'webm'];

    public function store(UploadedFile $file, Lesson $lesson): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw InvalidFileTypeException::forField('video', $extension, self::ALLOWED_EXTENSIONS);
        }

        return $file->storeAs(
            "videos/{$lesson->tenant_id}",
            "{$lesson->id}.{$extension}",
            'local'
        );
    }

    public function url(Lesson $lesson): string
    {
        return Storage::disk('local')->path($lesson->video_disk_path);
    }

    public function delete(Lesson $lesson): void
    {
        if ($lesson->video_disk_path) {
            Storage::disk('local')->delete($lesson->video_disk_path);
        }
    }
}

<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Jobs\ProcessPhotoDoubtQuery;
use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\PhotoTooLargeException;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * L14 pt.1 — student photographs a handwritten question, gets a
 * step-by-step solution. Plain web file upload, not native camera capture
 * (design §4 decision 7). Async — this creates a `pending` row and
 * dispatches a queued job; ProcessPhotoDoubtQuery fills in the result
 * later, same "row now, job fills it in" shape S16's ProcessOmrSheetScan
 * already established (design §4 decision 8).
 */
class PhotoDoubtService
{
    /** @var array<int, string> */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const MAX_SIZE_KB = 5120;

    public function submit(UploadedFile $image, User $student, ?int $courseId): PhotoDoubtQuery
    {
        $extension = strtolower((string) $image->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw InvalidFileTypeException::forField('image', $extension, self::ALLOWED_EXTENSIONS);
        }

        $sizeKb = (int) ceil($image->getSize() / 1024);

        if ($sizeKb > self::MAX_SIZE_KB) {
            throw PhotoTooLargeException::forSize($sizeKb, self::MAX_SIZE_KB);
        }

        $diskPath = $image->storeAs(
            "photo-doubts/{$student->tenant_id}/{$student->id}",
            uniqid().'.'.$extension,
            'local',
        );

        $query = PhotoDoubtQuery::create([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'image_disk_path' => $diskPath,
            'status' => 'pending',
        ]);

        ProcessPhotoDoubtQuery::dispatch($query->id, $query->tenant_id);

        return $query;
    }
}

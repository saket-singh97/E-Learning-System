<?php

namespace App\Integrations;

use App\Domains\Content\Models\Lesson;
use Illuminate\Http\UploadedFile;

interface VideoProvider
{
    /**
     * Store the uploaded video for the given lesson and return its disk path.
     */
    public function store(UploadedFile $file, Lesson $lesson): string;

    /**
     * Resolve a URL the player can stream the lesson's video from.
     */
    public function url(Lesson $lesson): string;

    /**
     * Remove the lesson's stored video.
     */
    public function delete(Lesson $lesson): void;
}

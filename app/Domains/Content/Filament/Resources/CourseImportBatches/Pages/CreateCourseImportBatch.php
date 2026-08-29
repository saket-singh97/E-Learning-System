<?php

namespace App\Domains\Content\Filament\Resources\CourseImportBatches\Pages;

use App\Domains\Content\Filament\Resources\CourseImportBatches\CourseImportBatchResource;
use App\Domains\Content\Services\CourseCsvImporter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateCourseImportBatch extends CreateRecord
{
    protected static string $resource = CourseImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $csvPath = is_array($data['csv']) ? array_values($data['csv'])[0] : $data['csv'];
        $csvContents = Storage::disk('local')->get($csvPath);

        $batch = app(CourseCsvImporter::class)->import($csvContents, auth()->user(), (bool) ($data['dry_run'] ?? false));

        Storage::disk('local')->delete($csvPath);

        return $batch;
    }
}

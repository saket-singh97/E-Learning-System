<?php

namespace App\Domains\Assessments\Filament\Resources\OmrSheetScans\Pages;

use App\Domains\Assessments\Filament\Resources\OmrSheetScans\OmrSheetScanResource;
use App\Domains\Assessments\Jobs\ProcessOmrSheetScan;
use Filament\Resources\Pages\CreateRecord;

class CreateOmrSheetScan extends CreateRecord
{
    protected static string $resource = OmrSheetScanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['image'] = is_array($data['image']) ? array_values($data['image'])[0] : $data['image'];
        $data['image_disk_path'] = $data['image'];
        unset($data['image']);

        $data['status'] = 'pending';
        $data['uploaded_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        ProcessOmrSheetScan::dispatch($this->record->id);
    }
}

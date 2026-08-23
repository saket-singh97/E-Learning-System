<?php

namespace App\Domains\Comms\Filament\Resources\Notices\Pages;

use App\Domains\Comms\Filament\Resources\Notices\NoticeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotice extends CreateRecord
{
    protected static string $resource = NoticeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}

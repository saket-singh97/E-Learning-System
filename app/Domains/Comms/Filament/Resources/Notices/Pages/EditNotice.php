<?php

namespace App\Domains\Comms\Filament\Resources\Notices\Pages;

use App\Domains\Comms\Filament\Resources\Notices\NoticeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNotice extends EditRecord
{
    protected static string $resource = NoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

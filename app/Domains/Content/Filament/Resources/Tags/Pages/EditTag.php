<?php

namespace App\Domains\Content\Filament\Resources\Tags\Pages;

use App\Domains\Content\Filament\Resources\Tags\TagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->courses()->doesntExist()),
        ];
    }
}

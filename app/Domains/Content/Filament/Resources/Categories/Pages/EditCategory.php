<?php

namespace App\Domains\Content\Filament\Resources\Categories\Pages;

use App\Domains\Content\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->children()->doesntExist() && $this->record->courses()->doesntExist()),
        ];
    }
}

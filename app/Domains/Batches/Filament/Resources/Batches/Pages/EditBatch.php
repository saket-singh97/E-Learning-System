<?php

namespace App\Domains\Batches\Filament\Resources\Batches\Pages;

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Filament\Teach\Widgets\BatchEngagementWidget;
use App\Filament\Teach\Widgets\BatchWeakTopicsWidget;
use App\Filament\Teach\Widgets\ConfusionSignalWidget;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * S17 B9 — InteractsWithRecord::getWidgetData() (Filament core) already
     * hands every footer widget ['record' => $this->getRecord()] for free.
     */
    protected function getFooterWidgets(): array
    {
        return [
            BatchWeakTopicsWidget::class,
            BatchEngagementWidget::class,
            ConfusionSignalWidget::class,
        ];
    }
}

<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Jobs\ExportEngagementRegister;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Same shape as AttendanceExport (S8 B6) — copied exactly, not a new
 * pattern (S9 B11's own plan note).
 */
class EngagementExport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Engagement Export';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate engagement register')
                ->action(function (): void {
                    ExportEngagementRegister::dispatch(app(CurrentTenant::class)->id());

                    Notification::make()
                        ->title('Export queued — refresh this page in a moment to download it.')
                        ->success()
                        ->send();
                }),
            Action::make('download')
                ->label('Download engagement register')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('comms.engagement-export.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Storage::disk('local')->exists(
                    ExportEngagementRegister::pathFor(app(CurrentTenant::class)->id())
                )),
        ];
    }
}

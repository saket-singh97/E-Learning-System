<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Jobs\ExportRevenueRegister;
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
class RevenueExport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Revenue Export';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate revenue register')
                ->action(function (): void {
                    ExportRevenueRegister::dispatch(app(CurrentTenant::class)->id());

                    Notification::make()
                        ->title('Export queued — refresh this page in a moment to download it.')
                        ->success()
                        ->send();
                }),
            Action::make('download')
                ->label('Download revenue register')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('comms.revenue-export.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Storage::disk('local')->exists(
                    ExportRevenueRegister::pathFor(app(CurrentTenant::class)->id())
                )),
        ];
    }
}

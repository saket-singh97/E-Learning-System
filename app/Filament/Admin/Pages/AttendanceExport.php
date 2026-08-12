<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Jobs\ExportAttendanceRegister;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * "Request export -> job runs -> download link, not a synchronous CSV
 * stream" (this plan's own B6 bullet). No new schema tracks whether an
 * export is ready — this app's own "no new schema" instruction for this
 * block rules out an exports table, so readiness is read straight off
 * Storage::exists() rather than a status column. Matches how this
 * codebase already surfaces an async job's result with no polling
 * anywhere in the app (S3 B?'s AI ingestion status badge just re-reads
 * DB/storage state on the next render — confirmed by reading
 * IngestionStatusUiTest before assuming polling infrastructure existed):
 * click Generate, then Download becomes visible once the file exists on
 * the next render of this page.
 */
class AttendanceExport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'Attendance Export';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate attendance register')
                ->action(function (): void {
                    ExportAttendanceRegister::dispatch(app(CurrentTenant::class)->id());

                    Notification::make()
                        ->title('Export queued — refresh this page in a moment to download it.')
                        ->success()
                        ->send();
                }),
            Action::make('download')
                ->label('Download attendance register')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('comms.attendance-export.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Storage::disk('local')->exists(
                    ExportAttendanceRegister::pathFor(app(CurrentTenant::class)->id())
                )),
        ];
    }
}

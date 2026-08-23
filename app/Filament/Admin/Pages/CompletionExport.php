<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Jobs\ExportCompletionRegister;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Same shape as EnrolmentExport (B18) — request export -> job runs ->
 * download link, no new schema (readiness read off Storage::exists()).
 */
class CompletionExport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'Completion Export';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate completion register')
                ->action(function (): void {
                    ExportCompletionRegister::dispatch(app(CurrentTenant::class)->id());

                    Notification::make()
                        ->title('Export queued — refresh this page in a moment to download it.')
                        ->success()
                        ->send();
                }),
            Action::make('download')
                ->label('Download completion register')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('comms.completion-export.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Storage::disk('local')->exists(
                    ExportCompletionRegister::pathFor(app(CurrentTenant::class)->id())
                )),
        ];
    }
}

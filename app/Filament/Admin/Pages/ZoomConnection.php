<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * G7 pt.1 (design doc decision 2) — connect/disconnect this institute's own
 * Zoom account (Meetings API OAuth), one row per tenant
 * (institute_zoom_connections). The actual authorize redirect and token
 * exchange happen in ZoomOAuthController — this page only starts the
 * redirect and shows current connection status, same "page triggers,
 * controller does the OAuth" split GoogleAuthController already
 * established for student/parent portal login.
 */
class ZoomConnection extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static ?string $navigationLabel = 'Zoom Connection';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => $this->connection()
                ? "Connected — Zoom account {$this->connection()->account_id}, connected by {$this->connection()->connectedBy?->name}."
                : 'Not connected — no Zoom account linked for this institute yet.'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // ->url(), not ->action(): this has to leave the app for Zoom's
            // own authorize screen, a full browser navigation rather than a
            // Livewire AJAX call.
            Action::make('connect')
                ->label('Connect Zoom')
                ->visible(fn (): bool => $this->connection() === null)
                ->url(fn (): string => route('zoom.oauth.redirect', ['subdomain' => app(CurrentTenant::class)->get()->subdomain])),
            Action::make('disconnect')
                ->label('Disconnect')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->connection() !== null)
                ->action(function (): void {
                    $this->connection()?->delete();

                    Notification::make()
                        ->title('Zoom account disconnected.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function connection(): ?InstituteZoomConnection
    {
        return InstituteZoomConnection::where('tenant_id', app(CurrentTenant::class)->id())->first();
    }
}

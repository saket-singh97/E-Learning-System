<?php

namespace App\Filament\Teach\Pages;

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Services\DeviceFingerprintService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * S12 B2 — Teach panel's own copy of `App\Filament\Admin\Pages\MyDevices`.
 * Not extracted into a shared base class: the whole page is ~30 lines of
 * plain Filament table config with no computation worth factoring out
 * (unlike `Gradebook`'s own `BuildsGradebookRows` trait, which exists
 * because that page's row-building logic is genuinely complex) — two
 * small, independently-readable classes over a premature abstraction.
 */
class MyDevices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'My Devices';

    public function table(Table $table): Table
    {
        $currentFingerprint = app(DeviceFingerprintService::class)->fingerprintFor(request());

        return $table
            ->query(
                DeviceSession::query()->where('user_id', Auth::id())
            )
            ->columns([
                TextColumn::make('device_label')
                    ->label('Device')
                    ->default('Unknown device'),
                TextColumn::make('ip_address')->label('IP address')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->sortable(),
                IconColumn::make('is_current')
                    ->label('This device')
                    ->state(fn (DeviceSession $record): bool => $record->device_fingerprint === $currentFingerprint)
                    ->boolean(),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (DeviceSession $record): string => $record->isRevoked() ? 'Logged out' : 'Active')
                    ->color(fn (DeviceSession $record): string => $record->isRevoked() ? 'gray' : 'success'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Log out')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This device will be signed out the next time it makes a request. It can log back in afterward, using up one of your device slots again.')
                    ->visible(fn (DeviceSession $record): bool => ! $record->isRevoked() && $record->device_fingerprint !== $currentFingerprint)
                    ->action(fn (DeviceSession $record) => $record->update(['revoked_at' => now()])),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}

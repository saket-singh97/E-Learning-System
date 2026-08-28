<?php

namespace App\Domains\Api\Filament\Resources\ApiKeys;

use App\Domains\Api\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Domains\Api\Models\ApiKey;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S21 B3 — institute's own integration credentials (T5). Admin-panel only:
 * a super_admin has no tenant of its own to issue a key for, and Api keys
 * are how an institute's external system authenticates against its own
 * data, not a platform-wide concern.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'API Keys';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('status')
                    ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'revoked' : 'active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('last_used_at')->dateTime()->placeholder('Never'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
        ];
    }
}

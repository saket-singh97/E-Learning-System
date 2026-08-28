<?php

namespace App\Domains\Api\Filament\Resources\WebhookEndpoints;

use App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Domains\Api\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Domains\Api\Models\WebhookEndpoint;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S21 B6 (T5 pt.3) — Admin-panel only, same reasoning as `ApiKeyResource`
 * (B3): a super_admin has no tenant of its own to register a webhook for.
 * `DeliveriesRelationManager` on the Edit page is the "delivery log" the
 * plan's own B6 checklist names — read-only, system-generated rows, no
 * form of its own.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Webhooks';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('url')
                ->url()
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('disable')
                    ->visible(fn (WebhookEndpoint $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->action(fn (WebhookEndpoint $record) => $record->update(['status' => 'disabled'])),
                Action::make('enable')
                    ->visible(fn (WebhookEndpoint $record): bool => ! $record->isActive())
                    ->action(fn (WebhookEndpoint $record) => $record->update(['status' => 'active'])),
                EditAction::make()->label('View log'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'edit' => EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}

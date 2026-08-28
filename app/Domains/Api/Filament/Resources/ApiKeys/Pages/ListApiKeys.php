<?php

namespace App\Domains\Api\Filament\Resources\ApiKeys\Pages;

use App\Domains\Api\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Domains\Api\Models\ApiKey;
use App\Domains\Api\Services\ApiKeyService;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue new key')
                ->schema([
                    TextInput::make('name')->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(ApiKeyService::class)->issue(app(CurrentTenant::class)->get(), $data['name']);

                    Notification::make()
                        ->title('API key created')
                        ->body("Copy this now — it will not be shown again:\n\n{$result['plainKey']}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return ApiKeyResource::table($table)->recordActions([
            Action::make('revoke')
                ->requiresConfirmation()
                ->visible(fn (ApiKey $record): bool => ! $record->isRevoked())
                ->action(function (ApiKey $record): void {
                    app(ApiKeyService::class)->revoke($record);

                    Notification::make()->title('API key revoked')->success()->send();
                }),
        ]);
    }
}

<?php

namespace App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages;

use App\Domains\Api\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    /**
     * Secret shown once here (design doc §4 decision 4's own "shown once,
     * stored as-is on the row" — HMAC needs the raw secret at delivery
     * time, unlike an API key which only ever needs a hash to compare
     * against), same header-action idiom `ListApiKeys` (B3) already used
     * instead of a separate Create page/form.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')
                ->label('Register endpoint')
                ->schema([
                    TextInput::make('url')->url()->required(),
                ])
                ->action(function (array $data): void {
                    $secret = Str::random(40);

                    WebhookEndpoint::create([
                        'tenant_id' => app(CurrentTenant::class)->id(),
                        'url' => $data['url'],
                        'secret' => $secret,
                    ]);

                    Notification::make()
                        ->title('Webhook endpoint registered')
                        ->body("Signing secret — copy this now, it will not be shown again:\n\n{$secret}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}

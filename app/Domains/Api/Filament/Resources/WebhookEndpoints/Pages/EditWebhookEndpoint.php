<?php

namespace App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages;

use App\Domains\Api\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;
}

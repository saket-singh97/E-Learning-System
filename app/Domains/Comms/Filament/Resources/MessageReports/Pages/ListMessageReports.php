<?php

namespace App\Domains\Comms\Filament\Resources\MessageReports\Pages;

use App\Domains\Comms\Filament\Resources\MessageReports\MessageReportResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageReports extends ListRecords
{
    protected static string $resource = MessageReportResource::class;
}

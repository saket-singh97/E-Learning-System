<?php

namespace App\Domains\Comms\Filament\Resources\HelpDeskTickets\Pages;

use App\Domains\Comms\Filament\Resources\HelpDeskTickets\HelpDeskTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListHelpDeskTickets extends ListRecords
{
    protected static string $resource = HelpDeskTicketResource::class;
}

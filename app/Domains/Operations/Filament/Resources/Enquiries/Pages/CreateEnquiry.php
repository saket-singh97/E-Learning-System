<?php

namespace App\Domains\Operations\Filament\Resources\Enquiries\Pages;

use App\Domains\Operations\Filament\Resources\Enquiries\EnquiryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEnquiry extends CreateRecord
{
    protected static string $resource = EnquiryResource::class;
}

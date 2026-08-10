<?php

namespace App\Domains\Content\Filament\Resources\CourseSections\Pages;

use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseSections extends ListRecords
{
    protected static string $resource = CourseSectionResource::class;
}

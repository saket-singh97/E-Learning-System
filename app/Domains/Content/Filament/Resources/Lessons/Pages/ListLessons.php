<?php

namespace App\Domains\Content\Filament\Resources\Lessons\Pages;

use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use Filament\Resources\Pages\ListRecords;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;
}

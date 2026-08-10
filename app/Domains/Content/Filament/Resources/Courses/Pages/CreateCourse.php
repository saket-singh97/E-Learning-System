<?php

namespace App\Domains\Content\Filament\Resources\Courses\Pages;

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;
}

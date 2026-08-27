<?php

namespace App\Domains\Growth\Filament\Resources\CourseInterestSignups\Pages;

use App\Domains\Growth\Filament\Resources\CourseInterestSignups\CourseInterestSignupResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseInterestSignups extends ListRecords
{
    protected static string $resource = CourseInterestSignupResource::class;
}

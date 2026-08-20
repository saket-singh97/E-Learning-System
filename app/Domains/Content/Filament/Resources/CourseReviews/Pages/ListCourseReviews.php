<?php

namespace App\Domains\Content\Filament\Resources\CourseReviews\Pages;

use App\Domains\Content\Filament\Resources\CourseReviews\CourseReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseReviews extends ListRecords
{
    protected static string $resource = CourseReviewResource::class;
}

<?php

namespace App\Domains\Assignments\Filament\Resources\AssignmentSubmissions\Pages;

use App\Domains\Assignments\Filament\Resources\AssignmentSubmissions\AssignmentSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListAssignmentSubmissions extends ListRecords
{
    protected static string $resource = AssignmentSubmissionResource::class;
}

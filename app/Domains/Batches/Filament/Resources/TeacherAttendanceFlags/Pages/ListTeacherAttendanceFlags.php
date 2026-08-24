<?php

namespace App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\Pages;

use App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\TeacherAttendanceFlagResource;
use Filament\Resources\Pages\ListRecords;

class ListTeacherAttendanceFlags extends ListRecords
{
    protected static string $resource = TeacherAttendanceFlagResource::class;
}

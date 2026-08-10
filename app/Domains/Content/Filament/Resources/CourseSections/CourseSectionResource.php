<?php

namespace App\Domains\Content\Filament\Resources\CourseSections;

use App\Domains\Content\Filament\Resources\CourseSections\Pages\EditCourseSection;
use App\Domains\Content\Filament\Resources\CourseSections\Pages\ListCourseSections;
use App\Domains\Content\Filament\Resources\CourseSections\RelationManagers\LessonsRelationManager;
use App\Domains\Content\Models\CourseSection;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CourseSectionResource extends Resource
{
    protected static ?string $model = CourseSection::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('course.title')->label('Course'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LessonsRelationManager::class,
        ];
    }

    /**
     * No create page on purpose — same reason as LessonResource. A section
     * only exists inside a course, and this form carries no course_id field,
     * so a standalone create hit a NOT NULL violation on submit. Sections
     * are created through CourseResource's SectionsRelationManager, which
     * supplies the parent.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCourseSections::route('/'),
            'edit' => EditCourseSection::route('/{record}/edit'),
        ];
    }
}

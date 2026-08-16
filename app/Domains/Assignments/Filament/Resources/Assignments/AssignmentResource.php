<?php

namespace App\Domains\Assignments\Filament\Resources\Assignments;

use App\Domains\Assignments\Filament\Resources\Assignments\Pages\CreateAssignment;
use App\Domains\Assignments\Filament\Resources\Assignments\Pages\EditAssignment;
use App\Domains\Assignments\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Content\Models\Lesson;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssignmentResource extends Resource
{
    protected static ?string $model = Assignment::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('lesson_id')
                ->label('Assignment topic')
                ->helperText('Only assignment-type lessons — the syllabus tree node students submit this assignment from.')
                ->options(fn (): array => Lesson::query()
                    ->where('type', 'assignment')
                    ->pluck('title', 'id')
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Textarea::make('instructions')
                ->rows(4),
            DateTimePicker::make('due_at')
                ->required(),
            TextInput::make('max_marks')
                ->numeric()
                ->minValue(1)
                ->required(),
            Select::make('allowed_file_types')
                ->multiple()
                ->options(array_combine(Assignment::FILE_TYPE_OPTIONS, Assignment::FILE_TYPE_OPTIONS))
                ->default(['pdf', 'doc', 'docx', 'jpg', 'png'])
                ->required(),
            TextInput::make('max_file_size_kb')
                ->label('Max file size (KB)')
                ->numeric()
                ->minValue(100)
                ->maxValue(51200)
                ->default(10240)
                ->required(),
            Repeater::make('rubric')
                ->label('Rubric — leave empty for marks-only grading')
                ->schema([
                    TextInput::make('criterion')->required()->columnSpan(3),
                    TextInput::make('max_points')->numeric()->minValue(1)->required(),
                ])
                ->columns(4)
                ->addActionLabel('Add rubric criterion'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('lesson.title')->label('Topic'),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('max_marks')->label('Max marks'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                // Same guard QuizResource (S6 B8) already learned the hard
                // way — once an assignment has real submissions, deleting it
                // would silently destroy every student's graded history with
                // no way back.
                DeleteAction::make()
                    ->visible(fn (Assignment $record): bool => $record->submissions()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssignments::route('/'),
            'create' => CreateAssignment::route('/create'),
            'edit' => EditAssignment::route('/{record}/edit'),
        ];
    }
}

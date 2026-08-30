<?php

namespace App\Domains\Content\Filament\Resources\Courses\RelationManagers;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\SyllabusTopic;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SyllabusTopicsRelationManager extends RelationManager
{
    protected static string $relationship = 'syllabusTopics';

    public function form(Schema $schema): Schema
    {
        $courseId = $this->getOwnerRecord()->id;

        return $schema->components([
            TextInput::make('board_or_exam')
                ->required()
                ->maxLength(255),
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            // Scoping the query only filters the dropdown's own rendered
            // options — Filament's relationship sync still writes whatever
            // raw ids the request submits, unfiltered by that query (same
            // gap CourseResource's own categories/tags fields had, S13
            // B17). The ->rule() below is the real server-side gate.
            Select::make('lessons')
                ->relationship(
                    'lessons',
                    'title',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas(
                        'section',
                        fn (Builder $q) => $q->where('course_id', $courseId),
                    ),
                )
                ->multiple()
                ->searchable()
                ->native(false)
                ->rule(function () use ($courseId): Closure {
                    return function (string $attribute, mixed $value, Closure $fail) use ($courseId): void {
                        $ids = array_map('intval', (array) $value);

                        $validCount = Lesson::whereIn('id', $ids)
                            ->whereHas('section', fn (Builder $q) => $q->where('course_id', $courseId))
                            ->count();

                        if ($validCount !== count($ids)) {
                            $fail('One of the selected lessons does not belong to this course.');
                        }
                    };
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->reorderable('sequence')
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('board_or_exam'),
                TextColumn::make('lessons_count')->counts('lessons')->label('Lessons'),
            ])
            ->headerActions([
                // Same "append after current last" reasoning as
                // SectionsRelationManager::position — reorderable() only
                // renumbers rows a user actually drags.
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['sequence'] = (int) SyllabusTopic::where('course_id', $this->getOwnerRecord()->id)->max('sequence') + 1;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

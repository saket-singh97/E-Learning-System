<?php

namespace App\Domains\Content\Filament\Resources\Courses\RelationManagers;

use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use App\Domains\Content\Models\CourseSection;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('lessons_count')->counts('lessons')->label('Lessons'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                // reorderable('position') only renumbers rows a user actually
                // drags — it never touches new rows, which keep the column's
                // DB default of 0. Without this, every section created
                // without ever using the drag handle ties at position 0 and
                // orderBy('position') (Course::sections()) breaks the tie
                // with whatever order Postgres happens to return, not
                // authoring order. Append new rows after the current last one.
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['position'] = (int) CourseSection::withoutGlobalScope(SoftDeletingScope::class)
                            ->where('course_id', $this->getOwnerRecord()->id)
                            ->max('position') + 1;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('manageLessons')
                    ->label('Manage lessons')
                    ->url(fn ($record) => CourseSectionResource::getUrl('edit', ['record' => $record])),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}

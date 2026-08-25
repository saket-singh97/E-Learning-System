<?php

namespace App\Domains\AI\Filament\Resources\CourseOutlineDrafts\Pages;

use App\Domains\AI\Filament\Resources\CourseOutlineDrafts\CourseOutlineDraftResource;
use App\Domains\AI\Services\CourseOutlineGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCourseOutlineDrafts extends ListRecords
{
    protected static string $resource = CourseOutlineDraftResource::class;

    /**
     * Same "header Action with its own schema, no separate create page"
     * shape L5's ListQuestions::getHeaderActions() already established for
     * QuestionGenerator — a draft is always an AiProvider::chat() call, not
     * a blank form a user fills in field-by-field.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateOutline')
                ->label('Generate outline')
                ->schema([
                    TextInput::make('topic')->required()->maxLength(255),
                    TextInput::make('target_level')
                        ->maxLength(255)
                        ->helperText('e.g. beginner, intermediate — optional.'),
                ])
                ->action(function (array $data): void {
                    app(CourseOutlineGenerator::class)->generate(
                        $data['topic'],
                        $data['target_level'] ?: null,
                        auth()->user(),
                    );

                    Notification::make()
                        ->title('Outline generated — review and approve it below.')
                        ->success()
                        ->send();
                }),
        ];
    }
}

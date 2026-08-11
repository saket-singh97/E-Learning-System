<?php

namespace App\Domains\Assessments\Filament\Resources\Questions\Pages;

use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use App\Domains\Assessments\Services\QuestionGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateWithAi')
                ->label('Generate with AI')
                ->schema([
                    Select::make('mode')
                        ->options([
                            'from_lesson' => "From a lesson's ingested notes",
                            'blueprint' => 'Custom blueprint',
                        ])
                        ->default('from_lesson')
                        ->required()
                        ->live(),
                    Select::make('lesson_id')
                        ->label('Lesson')
                        ->helperText('Promotes that lessons already-extracted seed questions — free, no AI call.')
                        ->options(fn (): array => Lesson::pluck('title', 'id')->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('mode') === 'from_lesson')
                        ->required(fn (Get $get): bool => $get('mode') === 'from_lesson'),
                    Select::make('course_id')
                        ->label('Course')
                        ->options(fn (): array => Course::pluck('title', 'id')->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('mode') === 'blueprint')
                        ->required(fn (Get $get): bool => $get('mode') === 'blueprint'),
                    Repeater::make('blueprint')
                        ->label('What to generate')
                        ->schema([
                            TextInput::make('concept')->required(),
                            Select::make('type')
                                ->options([
                                    'mcq' => 'MCQ',
                                    'true_false' => 'True/false',
                                    'short_answer' => 'Short answer',
                                    'numerical' => 'Numerical',
                                ])
                                ->default('mcq')
                                ->required(),
                            Select::make('difficulty')
                                ->options(['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'])
                                ->default('medium')
                                ->required(),
                            TextInput::make('count')
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->maxValue(20)
                                ->required(),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->visible(fn (Get $get): bool => $get('mode') === 'blueprint')
                        ->required(fn (Get $get): bool => $get('mode') === 'blueprint'),
                ])
                ->action(function (array $data): void {
                    if ($data['mode'] === 'from_lesson') {
                        $created = app(QuestionGenerator::class)
                            ->fromSeedQuestions(Lesson::findOrFail($data['lesson_id']));
                    } else {
                        $created = app(QuestionGenerator::class)
                            ->generate(Course::findOrFail($data['course_id']), $data['blueprint']);
                    }

                    Notification::make()
                        ->title($created->count() > 0
                            ? "Generated {$created->count()} draft question(s) — review and approve them below."
                            : 'No draft questions were generated — that lesson has no seed questions yet.')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}

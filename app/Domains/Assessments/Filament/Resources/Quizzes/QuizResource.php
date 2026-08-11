<?php

namespace App\Domains\Assessments\Filament\Resources\Quizzes;

use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\CreateQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\ListQuizzes;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Lesson;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('lesson_id')
                ->label('Quiz topic')
                ->helperText('Only quiz-type lessons — the syllabus tree node students open this quiz from.')
                ->options(fn (): array => Lesson::query()
                    ->where('type', 'quiz')
                    ->pluck('title', 'id')
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Repeater::make('blueprint')
                ->label('Blueprint — what each attempt draws from the approved question bank')
                ->schema([
                    TextInput::make('concept')->helperText('Optional — leave blank to draw from any concept.'),
                    Select::make('type')
                        ->options(array_combine(Question::TYPES, Question::TYPES))
                        ->helperText('Optional — leave blank to draw any type.'),
                    Select::make('difficulty')
                        ->options(['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'])
                        ->helperText('Optional — leave blank to draw any difficulty.'),
                    TextInput::make('count')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(20)
                        ->required(),
                ])
                ->columns(4)
                ->minItems(1)
                ->required(),
            TextInput::make('time_limit_minutes')
                ->label('Time limit (minutes)')
                ->numeric()
                ->default(30)
                ->minValue(1)
                ->maxValue(180)
                ->required(),
            TextInput::make('max_attempts')
                ->numeric()
                ->default(1)
                ->minValue(1)
                ->maxValue(10)
                ->required(),
            Select::make('status')
                ->options(array_combine(Quiz::STATUSES, Quiz::STATUSES))
                ->default('draft')
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('lesson.title')->label('Topic'),
                TextColumn::make('time_limit_minutes')->label('Time limit')->suffix(' min'),
                TextColumn::make('max_attempts')->label('Attempts'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Quiz::STATUSES, Quiz::STATUSES)),
            ])
            ->recordActions([
                EditAction::make(),
                // Quiz (unlike Question) has no soft-deletes — a plain
                // DeleteAction is a real, cascading DB delete
                // (quiz_attempts.quiz_id is cascadeOnDelete). Once a quiz
                // has real attempts, deleting it would silently destroy
                // every student's graded history with no way back. Found
                // in B8's review pass: nothing had ever gated this.
                DeleteAction::make()
                    ->visible(fn (Quiz $record): bool => $record->attempts()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuizzes::route('/'),
            'create' => CreateQuiz::route('/create'),
            'edit' => EditQuiz::route('/{record}/edit'),
        ];
    }
}

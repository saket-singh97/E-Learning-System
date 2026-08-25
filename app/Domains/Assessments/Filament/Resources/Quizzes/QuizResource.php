<?php

namespace App\Domains\Assessments\Filament\Resources\Quizzes;

use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\CreateQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\ListQuizzes;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\OmrTemplateGenerator;
use App\Domains\Content\Models\Lesson;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            Select::make('test_series_id')
                ->label('Test series (optional)')
                ->helperText('Bundles this quiz for All-India Rank/percentile comparison against every other quiz in the same series.')
                ->options(fn (): array => TestSeries::pluck('title', 'id')->all())
                ->searchable()
                ->nullable(),
            Toggle::make('proctoring_enabled')
                ->label('Enable proctoring')
                // Detection/evidence only, not prevention (design doc §4
                // decision 4) — same opt-in-per-quiz posture Lesson's own
                // print_disabled (S14) took for a similar browser-deterrent
                // toggle.
                ->helperText('Logs tab-switch/fullscreen-exit/copy/paste events during an attempt for instructor review — does not lock the browser.')
                ->default(false),
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
                // S16 B14 (I11 pt.1) — creates/regenerates a fixed,
                // app-generated bubble-grid template for an offline
                // (paper) sitting of this quiz. No freeform designer
                // (design doc §4 decision 8) — the admin supplies only
                // the three counts, layout is computed deterministically.
                Action::make('generateOmrTemplate')
                    ->label(fn (Quiz $record): string => $record->omrTemplate ? 'Regenerate OMR template' : 'Generate OMR template')
                    ->schema([
                        TextInput::make('question_count')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(200)
                            ->required()
                            ->default(fn (Quiz $record): int => $record->omrTemplate?->question_count ?? 50),
                        TextInput::make('options_per_question')
                            ->numeric()
                            ->minValue(2)
                            ->maxValue(8)
                            ->required()
                            ->default(fn (Quiz $record): int => $record->omrTemplate?->options_per_question ?? 4),
                        TextInput::make('roll_number_digits')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12)
                            ->required()
                            ->default(fn (Quiz $record): int => $record->omrTemplate?->roll_number_digits ?? 6),
                    ])
                    ->action(fn (Quiz $record, array $data) => app(OmrTemplateGenerator::class)->generate(
                        $record,
                        (int) $data['question_count'],
                        (int) $data['options_per_question'],
                        (int) $data['roll_number_digits'],
                    )),
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

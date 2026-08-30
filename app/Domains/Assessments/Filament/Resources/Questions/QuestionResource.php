<?php

namespace App\Domains\Assessments\Filament\Resources\Questions;

use App\Domains\Assessments\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\EditQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\ListQuestions;
use App\Domains\Assessments\Models\Question;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $recordTitleAttribute = 'stem';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_id')
                ->label('Course')
                ->options(fn (): array => Course::pluck('title', 'id')->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('lesson_id')
                ->label('Topic (optional)')
                ->helperText('Which lesson this question relates to, if any.')
                ->options(fn (Get $get): array => Lesson::query()
                    ->whereHas('section', fn ($query) => $query->where('course_id', $get('course_id')))
                    ->pluck('title', 'id')
                    ->all())
                ->searchable(),
            Select::make('type')
                ->options(array_combine(Question::TYPES, Question::TYPES))
                ->required()
                ->live(),
            Textarea::make('stem')
                ->label('Question text')
                ->required()
                ->maxLength(65535),
            TagsInput::make('options')
                ->label('Options')
                ->helperText('The answer choices a student picks from — add at least 2.')
                ->live()
                ->rules(['min:2'])
                ->visible(fn (Get $get): bool => in_array($get('type'), ['mcq', 'multi_select'], true))
                ->required(fn (Get $get): bool => in_array($get('type'), ['mcq', 'multi_select'], true)),
            Select::make('correct_answer_mcq')
                ->label('Correct option')
                ->options(fn (Get $get): array => collect($get('options') ?? [])
                    ->mapWithKeys(fn (string $option): array => [$option => $option])
                    ->all())
                ->visible(fn (Get $get): bool => $get('type') === 'mcq')
                ->required(fn (Get $get): bool => $get('type') === 'mcq'),
            CheckboxList::make('correct_answer_multi_select')
                ->label('Correct options')
                ->helperText('Every option a student must select to earn full credit — partial credit for a partially-correct selection (design doc §4 decision 3).')
                ->options(fn (Get $get): array => collect($get('options') ?? [])
                    ->mapWithKeys(fn (string $option): array => [$option => $option])
                    ->all())
                ->visible(fn (Get $get): bool => $get('type') === 'multi_select')
                ->required(fn (Get $get): bool => $get('type') === 'multi_select'),
            Select::make('correct_answer_true_false')
                ->label('Correct answer')
                ->options(['True' => 'True', 'False' => 'False'])
                ->visible(fn (Get $get): bool => $get('type') === 'true_false')
                ->required(fn (Get $get): bool => $get('type') === 'true_false'),
            Textarea::make('correct_answer_short')
                ->label('Model answer')
                ->helperText('For the instructor during review — short answers are never auto-graded.')
                ->visible(fn (Get $get): bool => $get('type') === 'short_answer')
                ->required(fn (Get $get): bool => $get('type') === 'short_answer'),
            TextInput::make('correct_answer_numerical_value')
                ->label('Correct value')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === 'numerical')
                ->required(fn (Get $get): bool => $get('type') === 'numerical'),
            TextInput::make('correct_answer_numerical_tolerance')
                ->label('Tolerance (±)')
                ->numeric()
                ->default(0)
                ->visible(fn (Get $get): bool => $get('type') === 'numerical'),
            TagsInput::make('correct_answer_fill_in_blank')
                ->label('Accepted answers')
                ->helperText('Every acceptable answer — matched case-insensitively, exact only, not fuzzy.')
                ->visible(fn (Get $get): bool => $get('type') === 'fill_in_blank')
                ->required(fn (Get $get): bool => $get('type') === 'fill_in_blank')
                ->rules(['min:1']),
            Repeater::make('matching_pairs')
                ->label('Pairs')
                ->helperText('Each row is one correct left-to-right pair — the right-hand side is shuffled for the student, add at least 2 pairs.')
                ->schema([
                    TextInput::make('left')->required(),
                    TextInput::make('right')->required(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('type') === 'matching')
                ->required(fn (Get $get): bool => $get('type') === 'matching')
                ->rules(['min:2']),
            Repeater::make('ordering_items')
                ->label('Items, in the correct order')
                ->helperText('Add each step/item in its correct order — the student sees them shuffled and puts them back in order.')
                ->schema([
                    TextInput::make('label')->required(),
                ])
                ->reorderable()
                ->visible(fn (Get $get): bool => $get('type') === 'ordering')
                ->required(fn (Get $get): bool => $get('type') === 'ordering')
                ->rules(['min:2']),
            Textarea::make('explanation')
                ->helperText('Shown to the student alongside their result.')
                ->maxLength(65535),
            Select::make('difficulty')
                ->options(array_combine(Question::DIFFICULTIES, Question::DIFFICULTIES))
                ->required()
                ->default('easy')
                ->native(false),
            TextInput::make('concept')
                ->maxLength(255),
            // U11 — previous-year-question tagging (design doc §3.2): no
            // new table, 3 columns on the existing question, exam
            // name/year only meaningful once is_pyq is on.
            Toggle::make('is_pyq')
                ->label('Previous-year question')
                ->live(),
            TextInput::make('pyq_exam_name')
                ->label('Exam name')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => (bool) $get('is_pyq'))
                ->required(fn (Get $get): bool => (bool) $get('is_pyq')),
            TextInput::make('pyq_exam_year')
                ->label('Exam year')
                ->numeric()
                ->minValue(1900)
                ->maxValue(2100)
                ->visible(fn (Get $get): bool => (bool) $get('is_pyq'))
                ->required(fn (Get $get): bool => (bool) $get('is_pyq')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stem')->limit(60)->searchable(),
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('type')->badge(),
                TextColumn::make('difficulty')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'easy' => 'success',
                        'medium' => 'warning',
                        'hard' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),
                TextColumn::make('source')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('course_id')->label('Course')->options(fn (): array => Course::pluck('title', 'id')->all()),
                SelectFilter::make('type')->options(array_combine(Question::TYPES, Question::TYPES)),
                SelectFilter::make('status')->options(array_combine(Question::STATUSES, Question::STATUSES)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Question $record): bool => $record->status === 'draft')
                    ->action(fn (Question $record) => $record->update(['status' => 'approved'])),
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestions::route('/'),
            'create' => CreateQuestion::route('/create'),
            'edit' => EditQuestion::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Domains\AI\Filament\Resources\CourseOutlineDrafts;

use App\Domains\AI\Filament\Resources\CourseOutlineDrafts\Pages\EditCourseOutlineDraft;
use App\Domains\AI\Filament\Resources\CourseOutlineDrafts\Pages\ListCourseOutlineDrafts;
use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Services\CourseOutlineApprovalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * L9 pt.2 — instructor review UI. generate() (B1) already produced the
 * draft outline via AiProvider::chat(); this resource is where an
 * instructor edits the generated section/lesson titles + descriptions and
 * then approves (creating real Course/CourseSection/Lesson rows via
 * CourseOutlineApprovalService, S17 design §4 decision 1) or rejects.
 */
class CourseOutlineDraftResource extends Resource
{
    protected static ?string $model = CourseOutlineDraft::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'topic';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('topic')
                ->required()
                ->maxLength(255),
            TextInput::make('target_level')
                ->maxLength(255)
                ->helperText('e.g. beginner, intermediate — optional.'),
            Repeater::make('generated_outline')
                ->label('Outline')
                ->disabled(fn (?CourseOutlineDraft $record): bool => $record?->status !== 'draft')
                ->schema([
                    TextInput::make('section_title')->required(),
                    Repeater::make('lessons')
                        ->schema([
                            TextInput::make('title')->required(),
                            Textarea::make('description')->rows(2),
                        ])
                        ->columns(2)
                        ->defaultItems(0),
                ])
                ->defaultItems(0)
                ->reorderable()
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('topic')->searchable(),
                TextColumn::make('target_level')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('creator.name')->label('Requested by'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(CourseOutlineDraft::STATUSES, CourseOutlineDraft::STATUSES)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CourseOutlineDraft $record): bool => $record->status === 'draft')
                    ->action(fn (CourseOutlineDraft $record) => app(CourseOutlineApprovalService::class)->approve($record)),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CourseOutlineDraft $record): bool => $record->status === 'draft')
                    ->action(fn (CourseOutlineDraft $record) => app(CourseOutlineApprovalService::class)->reject($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * No 'create' route — a draft only ever comes from
     * CourseOutlineGenerator::generate(), triggered by ListCourseOutlineDrafts'
     * own "Generate outline" header action (same shape L5's own
     * "Generate with AI" action on ListQuestions already established), not
     * a blank Filament create form.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCourseOutlineDrafts::route('/'),
            'edit' => EditCourseOutlineDraft::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Domains\Content\Filament\Resources\Lessons;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
use App\Domains\Content\Filament\Resources\Lessons\Pages\ListLessons;
use App\Domains\Content\Filament\Resources\Lessons\Pages\ViewLessonArtifact;
use App\Domains\Content\Models\Lesson;
use BackedEnum;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

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
            Select::make('type')
                ->options(array_combine(Lesson::TYPES, Lesson::TYPES))
                ->live()
                ->required(),
            FileUpload::make('video_upload')
                ->label('Video file')
                // live_session lessons attach their recording through this
                // same field after class (S5 B6) — not just video lessons.
                ->visible(fn (Get $get): bool => in_array($get('type'), ['video', 'live_session'], true))
                ->disk('local')
                ->directory('videos/tmp')
                ->acceptedFileTypes(['video/mp4', 'video/webm'])
                ->rules(['mimes:mp4,webm'])
                ->dehydrated(),
            FileUpload::make('pdf_upload')
                ->label('PDF file')
                ->visible(fn (Get $get): bool => $get('type') === 'pdf')
                ->disk('local')
                ->directory('pdfs/tmp')
                ->acceptedFileTypes(['application/pdf'])
                ->rules(['mimes:pdf'])
                ->dehydrated(),
            Textarea::make('text_body')
                ->label('Lesson text')
                ->visible(fn (Get $get): bool => $get('type') === 'text')
                ->rows(10)
                // Sanitized again on save via Lesson's own SanitizesHtml
                // trait regardless of what this textarea submits — this
                // isn't the only line of defense.
                ->helperText('Basic formatting only (bold, italic, lists) — other HTML is stripped on save.'),
            TextInput::make('embed_url')
                ->label('Embed URL')
                ->url()
                ->visible(fn (Get $get): bool => $get('type') === 'embed')
                ->helperText('Allowed sources: YouTube, Vimeo, Google Docs/Slides.')
                ->rule(function (): Closure {
                    return function (string $attribute, mixed $value, Closure $fail): void {
                        $host = parse_url((string) $value, PHP_URL_HOST);

                        if ($host === null || ! in_array($host, Lesson::EMBEDDABLE_HOSTS, true)) {
                            $fail('The embed URL must be from an allowed source (YouTube, Vimeo, or Google Docs/Slides).');
                        }
                    };
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('section.title')->label('Section'),
                static::aiStatusColumn(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('ai_status')
                ->label('AI status')
                ->state(fn (Lesson $record): string => LessonArtifact::latestFor($record->id)?->status ?? 'none')
                ->badge(),
            TextEntry::make('transcript')
                ->label('Transcript')
                ->state(fn (Lesson $record): string => LessonArtifact::latestReadyFor($record->id)?->transcript ?? 'Not ready yet.')
                ->columnSpanFull(),
            TextEntry::make('concepts')
                ->label('Concepts')
                ->state(fn (Lesson $record): string => collect(LessonArtifact::latestReadyFor($record->id)?->concepts ?? [])
                    ->pluck('name')
                    ->implode(', ') ?: 'Not ready yet.')
                ->columnSpanFull(),
        ]);
    }

    /**
     * Shared by LessonResource's own table and LessonsRelationManager's —
     * the actual teacher-facing lesson list, per CLAUDE.md's note that a
     * relation-manager-only resource still needs this reused, not redefined.
     */
    public static function aiStatusColumn(): TextColumn
    {
        return TextColumn::make('ai_status')
            ->label('AI status')
            ->state(fn (Lesson $record): string => LessonArtifact::latestFor($record->id)?->status ?? 'none')
            ->badge()
            ->color(fn (string $state): string => match ($state) {
                'ready' => 'success',
                'processing', 'pending' => 'warning',
                'failed' => 'danger',
                default => 'gray',
            });
    }

    /**
     * No create page on purpose. A lesson only exists inside a section, and
     * this form carries no course_section_id field, so a standalone create
     * hit a NOT NULL violation on submit. Lessons are created through
     * CourseSections' LessonsRelationManager, which supplies the parent.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
            'edit' => EditLesson::route('/{record}/edit'),
            'artifact' => ViewLessonArtifact::route('/{record}/artifact'),
        ];
    }
}

<?php

namespace App\Domains\Content\Filament\Resources\Lessons;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
use App\Domains\Content\Filament\Resources\Lessons\Pages\ListLessons;
use App\Domains\Content\Filament\Resources\Lessons\Pages\ViewLessonArtifact;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\PrerequisiteGraph;
use BackedEnum;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

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
            Textarea::make('subtitle_text')
                ->label('Subtitles (WebVTT)')
                // Same video/live_session visibility as video_upload — only
                // those types ever get a subtitle track (design doc D13 pt.2).
                ->visible(fn (Get $get): bool => in_array($get('type'), ['video', 'live_session'], true))
                ->rows(10)
                ->dehydrated()
                // Not a Lesson column (subtitle_disk_path/subtitle_status
                // are) — this textarea's own state has to be hydrated from
                // the stored VTT file by hand, same reason FileUpload's temp
                // path is captured in mutateFormDataBeforeSave rather than
                // relying on default model-attribute hydration.
                ->afterStateHydrated(function (Textarea $component, ?Lesson $record): void {
                    if ($record?->subtitle_disk_path && Storage::disk('local')->exists($record->subtitle_disk_path)) {
                        $component->state(Storage::disk('local')->get($record->subtitle_disk_path));
                    }
                })
                ->helperText('Raw WebVTT text (WEBVTT header + timestamped cues). Auto-generated once ingestion finishes — edit here to fix wording or timing, not a timeline-scrubbing cue editor.'),
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
            Toggle::make('print_disabled')
                ->label('Disable print/save')
                // Only pdf/text render differently on this flag (design doc
                // decision 9) — video's own S9 B6 protection stays
                // unconditional, embed has no protection to gate.
                ->visible(fn (Get $get): bool => in_array($get('type'), ['pdf', 'text'], true))
                ->helperText('Suppresses the PDF toolbar / blocks right-click and printing for this lesson. A deterrent, not real DRM — off by default so free-preview and low-stakes content isn\'t taxed with extra friction (decision 10).'),
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
            // Options scoped to the same course (sequential unlocking is a
            // within-course concept, C8) — no create page on this resource
            // (see getPages()'s own docblock), so $record is always present
            // here; the -1 fallback is defensive only, never reached.
            Select::make('prerequisites')
                ->relationship(
                    'prerequisites',
                    'title',
                    modifyQueryUsing: fn (Builder $query, ?Lesson $record): Builder => $record
                        ? $query->whereHas('section', fn ($query) => $query->where('course_id', $record->section->course_id))
                            ->whereKeyNot($record->id)
                        : $query->whereKey(-1),
                )
                ->multiple()
                ->searchable()
                ->native(false)
                ->helperText('A student must complete every selected lesson before this one unlocks.')
                // modifyQueryUsing above only narrows the dropdown's own
                // options — Filament's relationship Select still syncs
                // whatever raw ids the request actually submits
                // (Select::saveRelationshipsUsing() calls
                // $relationship->sync($state, ...) directly, never
                // re-filtered by modifyQueryUsing; confirmed against
                // Filament's own source by S13 B17's review pass). This
                // rule() is therefore the only real server-side gate — it
                // must fail loudly on a cross-course or unresolvable id,
                // not silently skip it, or a tampered payload against
                // Livewire's own unguarded /livewire/update endpoint
                // could attach a prerequisite the student can never
                // complete, permanently locking the lesson.
                ->rule(fn (?Lesson $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if (! $record) {
                        return;
                    }

                    foreach ((array) $value as $prerequisiteId) {
                        // Lesson::find() is tenant-scoped (BelongsToTenant)
                        // — null means a bogus id or one from another
                        // tenant, either way rejected, not skipped.
                        $prerequisite = Lesson::find($prerequisiteId);

                        if (! $prerequisite) {
                            $fail('One of the selected prerequisites is invalid.');

                            continue;
                        }

                        if ($prerequisite->section?->course_id !== $record->section?->course_id) {
                            $fail("\"{$prerequisite->title}\" is not a lesson in this same course.");

                            continue;
                        }

                        if (app(PrerequisiteGraph::class)->wouldCreateCycle($record, $prerequisite)) {
                            $fail("Adding \"{$prerequisite->title}\" as a prerequisite would create a circular dependency.");
                        }
                    }
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

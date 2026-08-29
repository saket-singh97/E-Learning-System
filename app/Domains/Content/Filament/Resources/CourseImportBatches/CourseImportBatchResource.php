<?php

namespace App\Domains\Content\Filament\Resources\CourseImportBatches;

use App\Domains\Content\Filament\Resources\CourseImportBatches\Pages\CreateCourseImportBatch;
use App\Domains\Content\Filament\Resources\CourseImportBatches\Pages\ListCourseImportBatches;
use App\Domains\Content\Models\CourseImportBatch;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S21 B10 (V14 pt.1) — Admin-panel only, explicitly registered
 * (AdminPanelProvider) same as CategoryResource/ProgramResource: Content's
 * own resource directory isn't auto-discovered (it also holds
 * CourseResource, Teach-only), so this needs the same explicit-list
 * treatment.
 */
class CourseImportBatchResource extends Resource
{
    protected static ?string $model = CourseImportBatch::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Course Import';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv')
                ->label('Course CSV')
                ->disk('local')
                ->directory('course-imports/tmp')
                ->acceptedFileTypes(['text/csv', 'text/plain'])
                ->rules(['mimes:csv,txt'])
                ->maxSize(2048)
                ->required()
                ->dehydrated()
                ->helperText('Columns: title, slug, category (optional). Up to 5000 rows.'),
            Checkbox::make('dry_run')
                ->label('Dry run (preview only — reports errors, creates nothing)')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'dry_run' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('dry_run')->boolean(),
                TextColumn::make('total_rows')->label('Rows'),
                TextColumn::make('error_rows')->label('Errors'),
                TextColumn::make('uploadedBy.name')->label('Uploaded by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('downloadErrorReport')
                    ->label('Download error report')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (CourseImportBatch $record): bool => $record->error_rows > 0 && $record->error_report_path !== null)
                    ->url(fn (CourseImportBatch $record): string => route('course-import.error-report.download', [
                        'importBatch' => $record->id,
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseImportBatches::route('/'),
            'create' => CreateCourseImportBatch::route('/create'),
        ];
    }
}

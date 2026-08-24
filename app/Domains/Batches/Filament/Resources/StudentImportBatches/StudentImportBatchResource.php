<?php

namespace App\Domains\Batches\Filament\Resources\StudentImportBatches;

use App\Domains\Batches\Filament\Resources\StudentImportBatches\Pages\CreateStudentImportBatch;
use App\Domains\Batches\Filament\Resources\StudentImportBatches\Pages\ListStudentImportBatches;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin-panel only (F9 pt.2, per this block's own plan text) — same
 * "not in Teach's explicit resources() list" precedent B3's
 * TimetableTemplateResource already established, no canAccess() override
 * needed since Teach only registers what it explicitly names.
 */
class StudentImportBatchResource extends Resource
{
    protected static ?string $model = StudentImportBatch::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Bulk Student Import';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv')
                ->label('Student CSV')
                ->disk('local')
                ->directory('student-imports/tmp')
                ->acceptedFileTypes(['text/csv', 'text/plain'])
                ->rules(['mimes:csv,txt'])
                // Phase 1 (StudentCsvImporter::parse()) reads the whole
                // file into memory and processes every line synchronously,
                // in-request — this caps it well above what 5000 short
                // rows needs (roughly 1MB), so the upload UI's own "Up to
                // 5000 rows" claim is actually enforced, not just stated.
                ->maxSize(2048)
                ->required()
                ->dehydrated()
                ->helperText('Columns: name, email, phone (optional), batch (the batch\'s own slug). Up to 5000 rows.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'done' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')->label('Rows'),
                TextColumn::make('success_count')->label('Enrolled'),
                TextColumn::make('error_count')->label('Errors'),
                TextColumn::make('uploadedBy.name')->label('Uploaded by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('downloadErrorReport')
                    ->label('Download error report')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (StudentImportBatch $record): bool => $record->error_count > 0 && $record->error_report_disk_path !== null)
                    ->url(fn (StudentImportBatch $record): string => route('student-import.error-report.download', [
                        'importBatch' => $record->id,
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentImportBatches::route('/'),
            'create' => CreateStudentImportBatch::route('/create'),
        ];
    }
}

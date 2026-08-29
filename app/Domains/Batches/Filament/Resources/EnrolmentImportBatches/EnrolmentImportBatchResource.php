<?php

namespace App\Domains\Batches\Filament\Resources\EnrolmentImportBatches;

use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\Pages\CreateEnrolmentImportBatch;
use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\Pages\ListEnrolmentImportBatches;
use App\Domains\Batches\Models\EnrolmentImportBatch;
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
 * S21 B11 (V14 pt.2) — same shape as CourseImportBatchResource (B10), but
 * lives under Batches/Filament/Resources, which AdminPanelProvider already
 * auto-discovers (no explicit registration needed the way Content's
 * directory requires).
 */
class EnrolmentImportBatchResource extends Resource
{
    protected static ?string $model = EnrolmentImportBatch::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Enrolment Import';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv')
                ->label('Enrolment CSV')
                ->disk('local')
                ->directory('enrolment-imports/tmp')
                ->acceptedFileTypes(['text/csv', 'text/plain'])
                ->rules(['mimes:csv,txt'])
                ->maxSize(2048)
                ->required()
                ->dehydrated()
                ->helperText('Columns: email (existing student), batch (the batch\'s own slug). Up to 5000 rows.'),
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
                    ->visible(fn (EnrolmentImportBatch $record): bool => $record->error_rows > 0 && $record->error_report_path !== null)
                    ->url(fn (EnrolmentImportBatch $record): string => route('enrolment-import.error-report.download', [
                        'importBatch' => $record->id,
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrolmentImportBatches::route('/'),
            'create' => CreateEnrolmentImportBatch::route('/create'),
        ];
    }
}

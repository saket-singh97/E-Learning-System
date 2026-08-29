<?php

namespace App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches;

use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\Pages\CreatePaymentHistoryImportBatch;
use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\Pages\ListPaymentHistoryImportBatches;
use App\Domains\Fees\Models\PaymentHistoryImportBatch;
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
 * S21 B12 (V14 pt.3) — same shape as CourseImportBatchResource (B10) /
 * EnrolmentImportBatchResource (B11); lives under Fees/Filament/Resources,
 * which AdminPanelProvider already auto-discovers.
 */
class PaymentHistoryImportBatchResource extends Resource
{
    protected static ?string $model = PaymentHistoryImportBatch::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Payment History Import';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv')
                ->label('Payment History CSV')
                ->disk('local')
                ->directory('payment-history-imports/tmp')
                ->acceptedFileTypes(['text/csv', 'text/plain'])
                ->rules(['mimes:csv,txt'])
                ->maxSize(2048)
                ->required()
                ->dehydrated()
                ->helperText('Columns: email, batch, invoice_number, amount, gst_amount, total_amount, paid_at. The student\'s enrolment in that batch must already exist. Up to 5000 rows.'),
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
                    ->visible(fn (PaymentHistoryImportBatch $record): bool => $record->error_rows > 0 && $record->error_report_path !== null)
                    ->url(fn (PaymentHistoryImportBatch $record): string => route('payment-history-import.error-report.download', [
                        'importBatch' => $record->id,
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentHistoryImportBatches::route('/'),
            'create' => CreatePaymentHistoryImportBatch::route('/create'),
        ];
    }
}

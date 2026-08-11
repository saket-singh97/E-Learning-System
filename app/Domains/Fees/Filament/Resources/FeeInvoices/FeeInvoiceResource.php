<?php

namespace App\Domains\Fees\Filament\Resources\FeeInvoices;

use App\Domains\Fees\Filament\Resources\FeeInvoices\Pages\ListFeeInvoices;
use App\Domains\Fees\Models\FeeInvoice;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-only — like BatchResource, billing/operational data isn't a
 * /teach concern, and AdminPanelProvider is the only panel that discovers
 * `Domains/Fees/Filament/Resources` (added B3). Read-only: FeeInvoice rows
 * only ever come from FeeInvoiceService, so no create/edit pages exist,
 * matching QuizAnswerResource's S6 precedent for a browse-only ledger.
 */
class FeeInvoiceResource extends Resource
{
    protected static ?string $model = FeeInvoice::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Fee invoices';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->searchable(),
                TextColumn::make('batch.title')->label('Batch'),
                TextColumn::make('user.name')->label('Student')->searchable(),
                TextColumn::make('description'),
                TextColumn::make('total_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn (FeeInvoice $record): string => '₹'.number_format((float) $record->total_amount, 2)),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('coupon.code')->label('Coupon')->placeholder('—'),
            ])
            ->defaultSort('due_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(FeeInvoice::STATUSES, FeeInvoice::STATUSES)),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View / print')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (FeeInvoice $record): string => route('invoices.show', [
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                        'invoice' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['batch', 'user', 'coupon']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeInvoices::route('/'),
        ];
    }
}

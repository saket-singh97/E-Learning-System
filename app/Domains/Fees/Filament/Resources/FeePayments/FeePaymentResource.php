<?php

namespace App\Domains\Fees\Filament\Resources\FeePayments;

use App\Domains\Fees\Filament\Resources\FeePayments\Pages\ListFeePayments;
use App\Domains\Fees\Models\FeePayment;
use BackedEnum;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * S12 B13 (design doc §3.2, V3) — the SoftDeletes gap-closure block's own
 * "admin restore UI on all 4" requirement needed a real Filament surface
 * to attach to, and none existed for FeePayment before this block
 * (confirmed by grep — it only ever appeared in Services/Livewire/
 * Integrations code, no Resource, no relation manager). Deliberately
 * minimal and browse-only, same shape `FeeInvoiceResource` already
 * established (S9 B3): no create/edit pages, since every real
 * `FeePayment` row only ever comes from the payment webhook/checkout
 * flow, never a manual admin entry.
 */
class FeePaymentResource extends Resource
{
    protected static ?string $model = FeePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Fee payments';

    protected static ?string $recordTitleAttribute = 'provider_payment_id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice.invoice_number')->label('Invoice')->searchable(),
                TextColumn::make('invoice.user.name')->label('Student')->searchable(),
                TextColumn::make('provider'),
                TextColumn::make('provider_payment_id')->label('Provider payment ID')->searchable()->placeholder('—'),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (FeePayment $record): string => '₹'.number_format((float) $record->amount, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'captured' => 'success',
                        'failed' => 'danger',
                        'refunded' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('webhook_verified_at')->label('Verified')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(FeePayment::STATUSES, FeePayment::STATUSES)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.user'])->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeePayments::route('/'),
        ];
    }
}

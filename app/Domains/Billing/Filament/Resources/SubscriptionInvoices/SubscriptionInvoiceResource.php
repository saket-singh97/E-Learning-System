<?php

namespace App\Domains\Billing\Filament\Resources\SubscriptionInvoices;

use App\Domains\Billing\Filament\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Domains\Billing\Models\SubscriptionInvoice;
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
 * S12 B13 (design doc §3.2, V3) — same "no existing Filament surface"
 * reasoning `TenantSubscriptionResource` (sitting beside this one)
 * already gives. Super panel, same placement as `PlanResource`/
 * `TenantSubscriptionResource`. `tenant` is reached through `subscription`
 * (this model has no direct `tenant()` relation of its own — confirmed
 * by reading the model — `TenantSubscription::tenant()` already covers
 * it, no need to add a duplicate relation method here).
 */
class SubscriptionInvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Subscription invoices';

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription.tenant.name')->label('Institute')->searchable(),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (SubscriptionInvoice $record): string => ($record->amount < 0 ? '-₹' : '₹').number_format(abs((float) $record->amount), 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SubscriptionInvoice::STATUS_PAID => 'success',
                        SubscriptionInvoice::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('period_start')->label('Period start')->date(),
                TextColumn::make('period_end')->label('Period end')->date(),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    SubscriptionInvoice::STATUS_PENDING => 'Pending',
                    SubscriptionInvoice::STATUS_PAID => 'Paid',
                    SubscriptionInvoice::STATUS_FAILED => 'Failed',
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscription.tenant', 'plan'])->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionInvoices::route('/'),
        ];
    }
}

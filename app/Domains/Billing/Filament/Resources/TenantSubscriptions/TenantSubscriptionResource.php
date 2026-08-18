<?php

namespace App\Domains\Billing\Filament\Resources\TenantSubscriptions;

use App\Domains\Billing\Filament\Resources\TenantSubscriptions\Pages\ListTenantSubscriptions;
use App\Domains\Billing\Models\TenantSubscription;
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
 * S12 B13 (design doc §3.2, V3) — same "no existing Filament surface,
 * minimal browse-only Resource built to give the restore requirement a
 * real UI" reasoning `FeePaymentResource` (Fees domain) already
 * establishes. Super panel, not Admin — matches `PlanResource`'s own
 * placement (the only other Billing-domain resource), discovered via
 * `SuperPanelProvider`'s `Domains/Billing/Filament/Resources` scan, not
 * `AdminPanelProvider` (which has no Billing discovery line at all).
 *
 * No explicit cross-tenant unscoping needed the way `InstituteSubscriptions`
 * (the existing Super-panel page) uses — `BelongsToTenant`'s own global
 * scope only filters when `CurrentTenant` is bound, and a super_admin
 * viewing this panel never has one bound, so the query is naturally
 * cross-tenant already.
 */
class TenantSubscriptionResource extends Resource
{
    protected static ?string $model = TenantSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')->label('Institute')->searchable(),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TenantSubscription::STATUS_ACTIVE => 'success',
                        TenantSubscription::STATUS_TRIALING => 'info',
                        TenantSubscription::STATUS_GRACE => 'warning',
                        TenantSubscription::STATUS_SUSPENDED, TenantSubscription::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('current_period_end')->label('Period ends')->dateTime()->sortable(),
                TextColumn::make('grace_ends_at')->label('Grace ends')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    TenantSubscription::STATUS_TRIALING => 'Trialing',
                    TenantSubscription::STATUS_ACTIVE => 'Active',
                    TenantSubscription::STATUS_GRACE => 'Grace',
                    TenantSubscription::STATUS_SUSPENDED => 'Suspended',
                    TenantSubscription::STATUS_CANCELLED => 'Cancelled',
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tenant', 'plan'])->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantSubscriptions::route('/'),
        ];
    }
}

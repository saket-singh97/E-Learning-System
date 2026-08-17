<?php

namespace App\Filament\Pages;

use App\Domains\Billing\Models\TenantSubscription;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S11 B12 — Super panel, cross-tenant by nature (a platform operator needs
 * every institute's subscription in one list, not one at a time via
 * TenantResource). Lives in the same app/Filament/Pages/ ImpersonateUser
 * already uses — the Super panel's own pages directory, despite the name
 * not following Admin/Teach's Filament/{Panel}/Pages/ convention; matching
 * existing precedent here over the naming convention used elsewhere.
 *
 * ->withoutGlobalScope('tenant') applied directly in the query, not left
 * to "CurrentTenant happens to be unbound on the Super panel anyway" —
 * S9 B10's sweep-job fix and S10 B13's gradebook fix both exist because a
 * cross-tenant read relying on ambient state instead of an explicit scope
 * bypass got missed until a later review pass. Built in from the start
 * here instead (B12's own plan note).
 */
class InstituteSubscriptions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Institute Subscriptions';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TenantSubscription::withoutGlobalScope('tenant')
                    ->with(['tenant', 'plan'])
            )
            ->columns([
                TextColumn::make('tenant.name')->label('Institute')->searchable(),
                TextColumn::make('tenant.subdomain')->label('Subdomain')->searchable(),
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
                TextColumn::make('current_period_end')->label('Renews')->date()->sortable(),
                TextColumn::make('grace_ends_at')->label('Grace ends')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    TenantSubscription::STATUS_TRIALING => 'Trialing',
                    TenantSubscription::STATUS_ACTIVE => 'Active',
                    TenantSubscription::STATUS_GRACE => 'Grace',
                    TenantSubscription::STATUS_SUSPENDED => 'Suspended',
                    TenantSubscription::STATUS_CANCELLED => 'Cancelled',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}

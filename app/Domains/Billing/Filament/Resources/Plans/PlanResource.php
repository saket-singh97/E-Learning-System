<?php

namespace App\Domains\Billing\Filament\Resources\Plans;

use App\Domains\Billing\Filament\Resources\Plans\Pages\CreatePlan;
use App\Domains\Billing\Filament\Resources\Plans\Pages\EditPlan;
use App\Domains\Billing\Filament\Resources\Plans\Pages\ListPlans;
use App\Domains\Billing\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(50)
                ->unique(Plan::class, 'code', ignoreRecord: true)
                ->disabled(fn (?Plan $record): bool => $record !== null)
                ->dehydrated(fn (?Plan $record): bool => $record === null)
                ->helperText('Locked once created — changing it would orphan any tenant, subscription, or invoice already referencing this plan.'),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('seat_limit')
                ->label('Seat limit')
                ->helperText('Active admin + instructor accounts. Leave blank for unlimited.')
                ->numeric()
                ->minValue(1),
            TextInput::make('storage_limit_gb')
                ->label('Storage limit (GB)')
                ->numeric()
                ->minValue(1)
                ->required(),
            TextInput::make('bandwidth_limit_gb_per_day')
                ->label('Bandwidth limit (GB/day)')
                ->numeric()
                ->minValue(1)
                ->required(),
            TextInput::make('ai_spend_cap_usd_per_day')
                ->label('AI spend cap (USD/day)')
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('price_inr_per_month')
                ->label('Price (INR/month)')
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('razorpay_plan_id')
                ->label('Razorpay plan ID')
                ->helperText('Populated once a real Razorpay account exists — not wired to a live subscription yet.')
                ->maxLength(255),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('seat_limit')->label('Seats')->placeholder('Unlimited'),
                TextColumn::make('storage_limit_gb')->label('Storage (GB)'),
                TextColumn::make('bandwidth_limit_gb_per_day')->label('Bandwidth (GB/day)'),
                TextColumn::make('price_inr_per_month')->label('Price/mo')->money('INR'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                // A plan already assigned to a tenant, or with any billing
                // history, must not be deletable outright — the DB's own
                // FK on subscription_invoices.plan_id/tenant_subscriptions.
                // plan_id would already refuse it (they're left at the
                // default restrict, not cascade/null), but hiding the
                // action gives a real "deactivate instead" path rather
                // than a raw constraint-violation error.
                DeleteAction::make()
                    ->visible(fn (Plan $record): bool => $record->tenants()->doesntExist() && $record->subscriptions()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Domains\Fees\Filament\Resources\FeeCoupons;

use App\Domains\Fees\Filament\Resources\FeeCoupons\Pages\CreateFeeCoupon;
use App\Domains\Fees\Filament\Resources\FeeCoupons\Pages\EditFeeCoupon;
use App\Domains\Fees\Filament\Resources\FeeCoupons\Pages\ListFeeCoupons;
use App\Domains\Fees\Models\FeeCoupon;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeeCouponResource extends Resource
{
    protected static ?string $model = FeeCoupon::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(50)
                // Stored upper-cased so lookup at redemption time (a
                // simple case-insensitive match in FeeCheckout) never
                // depends on how the admin happened to type it here.
                ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
            Select::make('discount_type')
                ->options(array_combine(FeeCoupon::DISCOUNT_TYPES, FeeCoupon::DISCOUNT_TYPES))
                ->required()
                ->native(false),
            TextInput::make('discount_value')
                ->label('Discount value')
                ->helperText('A percentage (e.g. 10 for 10%) or a flat rupee amount, depending on the type above.')
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('usage_limit')
                ->numeric()
                ->minValue(1)
                ->helperText('Leave blank for unlimited redemptions.'),
            DateTimePicker::make('expires_at')
                ->native(false)
                ->helperText('Leave blank for a coupon that never expires.'),
            Select::make('status')
                ->options(array_combine(FeeCoupon::STATUSES, FeeCoupon::STATUSES))
                ->default('active')
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('discount_type')->badge(),
                TextColumn::make('discount_value')
                    ->formatStateUsing(fn (FeeCoupon $record): string => $record->discount_type === 'percentage'
                        ? number_format((float) $record->discount_value, 2).'%'
                        : '₹'.number_format((float) $record->discount_value, 2)),
                TextColumn::make('used_count')->label('Used'),
                TextColumn::make('usage_limit')->label('Limit')->placeholder('Unlimited'),
                TextColumn::make('expires_at')->dateTime()->placeholder('Never')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(FeeCoupon::STATUSES, FeeCoupon::STATUSES)),
            ])
            ->recordActions([
                EditAction::make(),
                // Safe as a plain delete, unlike Quiz (S6 B8) — fee_invoices.
                // coupon_id is nullOnDelete(), not cascadeOnDelete(), so
                // removing a coupon never destroys the invoices it was
                // once applied to.
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeCoupons::route('/'),
            'create' => CreateFeeCoupon::route('/create'),
            'edit' => EditFeeCoupon::route('/{record}/edit'),
        ];
    }
}

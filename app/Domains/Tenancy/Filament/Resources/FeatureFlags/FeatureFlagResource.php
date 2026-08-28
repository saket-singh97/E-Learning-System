<?php

namespace App\Domains\Tenancy\Filament\Resources\FeatureFlags;

use App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages\CreateFeatureFlag;
use App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * S21 B2 — discovered by both Admin and Super panels (same
 * Tenancy/Filament/Resources directory every panel provider scans). Super
 * grants/revokes per tenant per key; Admin gets a read-only list of its own
 * tenant's flags (BelongsToTenant's own global scope filters that
 * automatically — no CurrentTenant bound on the Super panel, so it sees
 * every tenant instead, same "no tenant context = no scope filter" shape
 * the trait already established). canCreate() is the only gate needed:
 * with no Edit/Delete pages registered, there's nothing else to lock down.
 */
class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Feature Flags';

    public static function canCreate(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'super';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tenant_id')
                ->label('Institute')
                ->options(fn (): array => Tenant::query()->pluck('subdomain', 'id')->all())
                ->searchable()
                ->required(),
            TextInput::make('key')
                ->required(),
            Toggle::make('enabled')
                ->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        $isSuper = Filament::getCurrentPanel()?->getId() === 'super';

        return $table
            ->columns([
                TextColumn::make('tenant.subdomain')
                    ->label('Institute')
                    ->visible($isSuper),
                TextColumn::make('key'),
                ToggleColumn::make('enabled')
                    ->disabled(! $isSuper),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeatureFlags::route('/'),
            'create' => CreateFeatureFlag::route('/create'),
        ];
    }
}

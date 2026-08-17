<?php

namespace App\Domains\Tenancy\Filament\Resources\Tenants;

use App\Domains\Tenancy\Filament\Resources\Tenants\Pages\EditTenant;
use App\Domains\Tenancy\Filament\Resources\Tenants\Pages\ListTenants;
use App\Domains\Tenancy\Models\Tenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S11 B6 — the Super panel had no Tenant resource at all before this block
 * (confirmed by reading ImpersonateUser's own docblock, the panel's only
 * other page: "there is no Tenant/User Resource anywhere in this panel
 * yet"). Deliberately minimal, scoped to what B6 actually needs — name/
 * status editing and the new "Plan" section — not the full tenant
 * provisioning/branding surface FEATURES.md's A5 eventually wants
 * (creating a tenant, custom domains, branding colours). That gap is
 * noted, not silently expanded into here.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('subdomain')
                ->disabled()
                ->dehydrated(false)
                ->helperText('Not editable here — changing it would break every existing link/bookmark for this institute.'),
            Select::make('status')
                ->options([
                    Tenant::STATUS_ACTIVE => 'Active',
                    Tenant::STATUS_SUSPENDED => 'Suspended',
                ])
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('subdomain')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger'),
                TextColumn::make('plan.name')->label('Plan')->placeholder('None assigned'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}

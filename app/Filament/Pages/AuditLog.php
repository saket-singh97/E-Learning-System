<?php

namespace App\Filament\Pages;

use App\Domains\Security\Models\AuditLogEntry;
use App\Domains\Tenancy\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
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
 * S12 B6 (FEATURES.md B13) — read-only viewer over the general
 * `audit_log_entries` sink B5's `AuditLogger` writes to. A plain page, not
 * a Filament Resource — same reasoning `MyDevices` (B2) already gives for
 * "table config with nothing worth a full Resource's Create/Edit
 * scaffolding": there is nothing to create or edit here, an audit entry
 * is append-only by design (B0), so a Resource's own Create/Edit pages
 * would exist for no reason.
 *
 * No tenant filter defaulting to the viewer's own tenant — this is a
 * Super-panel-only page (`app/Filament/Pages/` is Super's exclusive
 * discovery path, `SuperPanelProvider`'s own `discoverPages` call), and a
 * platform-level actor genuinely needs to see every tenant's entries plus
 * the platform-level ones (`tenant_id === null`) side by side.
 */
class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit Log';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLogEntry::query())
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
                TextColumn::make('tenant.name')->label('Tenant')->placeholder('Platform-level'),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null ? class_basename($state) : null)
                    ->placeholder('—'),
                TextColumn::make('ip_address')->label('IP address')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLogEntry::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->schema([
                        TextEntry::make('action'),
                        TextEntry::make('created_at')->label('When')->dateTime(),
                        TextEntry::make('actor.name')->label('Actor')->placeholder('System'),
                        TextEntry::make('tenant.name')->label('Tenant')->placeholder('Platform-level'),
                        TextEntry::make('subject_type')->label('Subject type')->placeholder('—'),
                        TextEntry::make('subject_id')->label('Subject ID')->placeholder('—'),
                        TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                        KeyValueEntry::make('metadata'),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
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

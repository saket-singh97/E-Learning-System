<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Security\Services\GdprExportService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * S12 B8 (FEATURES.md B15 pt.2) — staff-initiated GDPR/DPDP data export
 * on behalf of a data subject (`requested_by_id` vs `user_id`, a real
 * distinct actor pair per B0's own schema), not a self-service "download
 * my data" flow — no such surface exists anywhere in this app yet, and
 * the plan text names the export mechanism itself, not a new self-service
 * portal page.
 *
 * A table, not a single generate/download action pair like `RevenueExport`
 * (S9 B11) — that job produces one file per tenant; this one produces one
 * per *request*, and a tenant can have several in flight for different
 * users at once, so a list is the only shape that actually works here.
 * Same "no Users Resource exists yet, so pick a target via a searchable
 * Select" reasoning `ImpersonateUser` (S9 B3) already established, scoped
 * to the current tenant here instead of that page's own cross-tenant one.
 */
class GdprExports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?string $navigationLabel = 'GDPR Data Export';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')
                ->label('Request export')
                ->schema([
                    Select::make('user_id')
                        ->label('User')
                        ->options(fn (): array => User::where('tenant_id', app(CurrentTenant::class)->id())
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $subject = User::where('tenant_id', app(CurrentTenant::class)->id())->findOrFail($data['user_id']);

                    app(GdprExportService::class)->request($subject, Auth::user());

                    Notification::make()
                        ->title('Export queued — refresh this list in a moment to download it.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(GdprExportRequest::query())
            ->columns([
                TextColumn::make('user.name')->label('User'),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Requested at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (GdprExportRequest $record): string => URL::temporarySignedRoute(
                        'gdpr-export.download',
                        $record->expires_at ?? now()->addDays(7),
                        ['subdomain' => app(CurrentTenant::class)->get()->subdomain, 'gdprExportRequest' => $record->id],
                    ))
                    ->openUrlInNewTab()
                    ->visible(fn (GdprExportRequest $record): bool => $record->status === GdprExportRequest::STATUS_READY
                        && ($record->expires_at === null || $record->expires_at->isFuture())),
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

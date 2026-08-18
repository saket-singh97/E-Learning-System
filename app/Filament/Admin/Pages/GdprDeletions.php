<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Security\Services\GdprDeletionService;
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

/**
 * S12 B9 (FEATURES.md B15 pt.3) — same shape as `GdprExports` (B8): a
 * table (several requests, for different users, can be in flight at
 * once), a target user picked via a searchable `Select` scoped to the
 * current tenant (no Users Resource exists yet — `ImpersonateUser`'s own
 * S9 B3 reasoning). Staff-initiated, `requested_by_id` a real distinct
 * actor from `user_id`, same as B8.
 *
 * Unlike B8, requesting here does not queue anything immediately —
 * design doc §4 decision 8's own grace window means the row just sits at
 * `pending` until `ProcessGdprDeletions`' own daily sweep finds it due.
 * The `cancel` row action is that grace window's whole point made usable.
 */
class GdprDeletions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?string $navigationLabel = 'GDPR Data Deletion';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')
                ->label('Request deletion')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The user\'s name, email, and phone will be anonymized, and their quiz/video-watch history hard-deleted, once the grace window passes. Financial records are kept for legal retention, stripped of identity.')
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

                    $request = app(GdprDeletionService::class)->request($subject, Auth::user());

                    Notification::make()
                        ->title("Deletion scheduled for {$request->scheduled_for->toFormattedDateString()} — cancel any time before then from this list.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(GdprDeletionRequest::query())
            ->columns([
                TextColumn::make('user.name')->label('User'),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('status')->badge(),
                TextColumn::make('scheduled_for')->label('Scheduled for')->dateTime()->sortable(),
                TextColumn::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (GdprDeletionRequest $record): bool => $record->status === GdprDeletionRequest::STATUS_PENDING)
                    ->action(fn (GdprDeletionRequest $record) => app(GdprDeletionService::class)->cancel($record)),
            ])
            ->defaultSort('scheduled_for', 'desc');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}

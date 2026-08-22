<?php

namespace App\Domains\Tenancy\Filament\Resources\InstructorInvitations;

use App\Domains\Batches\Models\Batch;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages\CreateInstructorInvitation;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages\ListInstructorInvitations;
use App\Domains\Tenancy\Models\InstructorInvitation;
use App\Domains\Tenancy\Services\InstructorInvitationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S15 B1 — closes docs/FEATURES.md §S.S1's real gap (no Admin-panel
 * user-management surface for instructors existed anywhere before this
 * block, per the design doc's own verification). Lives under Tenancy
 * (same domain as InstructorInvitation), but that resource directory is
 * discovered by all three panel providers (Admin/Teach/Super) — canAccess()
 * below restricts this to the Admin panel only, since only institute_admin
 * invites/deactivates instructors (RoleHierarchy::CAN_ASSIGN_ROLES).
 */
class InstructorInvitationResource extends Resource
{
    protected static ?string $model = InstructorInvitation::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Instructors';

    protected static ?string $recordTitleAttribute = 'email';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->email()
                ->required(),
            Select::make('batch_ids')
                ->label('Assign batches')
                ->multiple()
                ->options(fn (): array => Batch::query()->pluck('title', 'id')->all())
                ->searchable()
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('invitedBy.name')->label('Invited by'),
                TextColumn::make('expires_at')->dateTime(),
                TextColumn::make('acceptedUser.name')->label('Instructor')->placeholder('Not yet accepted'),
                TextColumn::make('acceptedUser.is_active')
                    ->label('Active')
                    ->badge()
                    ->state(fn (InstructorInvitation $record): ?string => $record->accepted_user_id === null
                        ? null
                        : ($record->acceptedUser->is_active ? 'active' : 'deactivated'))
                    ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'danger')
                    ->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->requiresConfirmation()
                    ->visible(fn (InstructorInvitation $record): bool => $record->acceptedUser?->is_active === true)
                    ->action(function (InstructorInvitation $record): void {
                        app(InstructorInvitationService::class)->deactivate($record->acceptedUser);

                        Notification::make()->title('Instructor deactivated')->success()->send();
                    }),
                Action::make('reactivate')
                    ->visible(fn (InstructorInvitation $record): bool => $record->acceptedUser?->is_active === false)
                    ->action(function (InstructorInvitation $record): void {
                        app(InstructorInvitationService::class)->reactivate($record->acceptedUser);

                        Notification::make()->title('Instructor reactivated')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorInvitations::route('/'),
            'create' => CreateInstructorInvitation::route('/create'),
        ];
    }
}

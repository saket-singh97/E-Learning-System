<?php

namespace App\Domains\Growth\Filament\Resources\InstructorPayouts;

use App\Domains\Growth\Filament\Resources\InstructorPayouts\Pages\CreateInstructorPayout;
use App\Domains\Growth\Filament\Resources\InstructorPayouts\Pages\ListInstructorPayouts;
use App\Domains\Growth\Models\InstructorPayout;
use App\Domains\Growth\Services\InstructorPayoutService;
use App\Exceptions\InstructorPayoutActionException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S20 R3 pt.1 — salaried basis only, same shape B1's own
 * `FeeRefundResource`/B9's `AffiliatePayoutRequestResource` established:
 * a Filament queue over a service that does the real work. Revenue-share
 * creation (B11) gets its own computation, not a form field here — this
 * one only ever creates a `salaried` row.
 */
class InstructorPayoutResource extends Resource
{
    protected static ?string $model = InstructorPayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Instructor payouts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('instructor_id')
                ->label('Instructor')
                ->options(fn (): array => User::role('instructor')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            DatePicker::make('period_start')->required()->native(false),
            DatePicker::make('period_end')->required()->native(false)->afterOrEqual('period_start'),
            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instructor.name')->label('Instructor')->searchable(),
                TextColumn::make('period_start')->label('Period')->date(),
                TextColumn::make('period_end')->label('to')->date(),
                TextColumn::make('basis')->badge(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (InstructorPayout $record): string => '₹'.number_format((float) $record->amount, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved', 'paid' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(InstructorPayout::STATUSES, InstructorPayout::STATUSES)),
                SelectFilter::make('basis')->options(array_combine(InstructorPayout::BASES, InstructorPayout::BASES)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->requiresConfirmation()
                    ->visible(fn (InstructorPayout $record): bool => $record->status === 'pending')
                    ->action(function (InstructorPayout $record): void {
                        try {
                            app(InstructorPayoutService::class)->approve($record, auth()->user());
                            Notification::make()->title('Payout approved')->success()->send();
                        } catch (InstructorPayoutActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->requiresConfirmation()
                    ->visible(fn (InstructorPayout $record): bool => $record->status === 'pending')
                    ->action(function (InstructorPayout $record): void {
                        try {
                            app(InstructorPayoutService::class)->reject($record);
                            Notification::make()->title('Payout rejected')->success()->send();
                        } catch (InstructorPayoutActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->requiresConfirmation()
                    ->visible(fn (InstructorPayout $record): bool => $record->status === 'approved')
                    ->action(function (InstructorPayout $record): void {
                        try {
                            app(InstructorPayoutService::class)->markPaid($record);
                            Notification::make()->title('Payout marked paid')->success()->send();
                        } catch (InstructorPayoutActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorPayouts::route('/'),
            'create' => CreateInstructorPayout::route('/create'),
        ];
    }
}

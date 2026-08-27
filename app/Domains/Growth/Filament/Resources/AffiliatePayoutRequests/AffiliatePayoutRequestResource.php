<?php

namespace App\Domains\Growth\Filament\Resources\AffiliatePayoutRequests;

use App\Domains\Growth\Filament\Resources\AffiliatePayoutRequests\Pages\ListAffiliatePayoutRequests;
use App\Domains\Growth\Models\AffiliatePayoutRequest;
use App\Domains\Growth\Services\AffiliatePayoutService;
use App\Exceptions\AffiliatePayoutActionException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Staff-facing approve/reject queue (design doc R2) — same shape B1's own
 * `FeeRefundResource` established: rows only ever come from an affiliate's
 * own dashboard request, never a manual admin entry, so no create page.
 */
class AffiliatePayoutRequestResource extends Resource
{
    protected static ?string $model = AffiliatePayoutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Affiliate payouts';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('affiliateCode.code')->label('Affiliate code')->searchable(),
                TextColumn::make('affiliateCode.owner.name')->label('Affiliate')->searchable(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (AffiliatePayoutRequest $record): string => '₹'.number_format((float) $record->amount, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved', 'paid' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('requested_at')->dateTime(),
                TextColumn::make('decidedBy.name')->label('Decided by')->placeholder('—'),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(AffiliatePayoutRequest::STATUSES, AffiliatePayoutRequest::STATUSES)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->requiresConfirmation()
                    ->visible(fn (AffiliatePayoutRequest $record): bool => $record->status === 'requested')
                    ->action(function (AffiliatePayoutRequest $record): void {
                        try {
                            app(AffiliatePayoutService::class)->approve($record, auth()->user());
                            Notification::make()->title('Payout approved')->success()->send();
                        } catch (AffiliatePayoutActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->requiresConfirmation()
                    ->visible(fn (AffiliatePayoutRequest $record): bool => $record->status === 'requested')
                    ->action(function (AffiliatePayoutRequest $record): void {
                        try {
                            app(AffiliatePayoutService::class)->reject($record, auth()->user());
                            Notification::make()->title('Payout rejected')->success()->send();
                        } catch (AffiliatePayoutActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliatePayoutRequests::route('/'),
        ];
    }
}

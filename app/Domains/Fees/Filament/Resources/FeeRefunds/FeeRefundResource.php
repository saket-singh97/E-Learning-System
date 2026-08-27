<?php

namespace App\Domains\Fees\Filament\Resources\FeeRefunds;

use App\Domains\Fees\Filament\Resources\FeeRefunds\Pages\CreateFeeRefund;
use App\Domains\Fees\Filament\Resources\FeeRefunds\Pages\ListFeeRefunds;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\FeeRefund;
use App\Domains\Fees\Services\RefundApprovalService;
use App\Exceptions\RefundActionException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Staff-facing request/approve/reject queue (design doc §4 decision 1) — a
 * refund is real money leaving the institute's account, so every decision
 * routes through here rather than a self-service reversal.
 */
class FeeRefundResource extends Resource
{
    protected static ?string $model = FeeRefund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $navigationLabel = 'Fee refunds';

    protected static ?string $recordTitleAttribute = 'reason';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fee_payment_id')
                ->label('Payment')
                ->options(fn (): array => FeePayment::query()
                    ->where('status', 'captured')
                    ->with('invoice')
                    ->get()
                    ->mapWithKeys(fn (FeePayment $payment): array => [
                        $payment->id => "{$payment->invoice?->invoice_number} — ₹".number_format((float) $payment->amount, 2),
                    ])
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required(),
            Textarea::make('reason')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment.invoice.invoice_number')->label('Invoice')->searchable(),
                TextColumn::make('payment.invoice.user.name')->label('Student')->searchable(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (FeeRefund $record): string => $record->payment->invoice->currencySymbol().number_format((float) $record->amount, 2)),
                TextColumn::make('reason')->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('gateway_refund_id')->label('Gateway ref')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(FeeRefund::STATUSES, FeeRefund::STATUSES)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->requiresConfirmation()
                    ->visible(fn (FeeRefund $record): bool => $record->status === 'pending')
                    ->action(function (FeeRefund $record): void {
                        try {
                            app(RefundApprovalService::class)->approve($record);
                            Notification::make()->title('Refund processed')->success()->send();
                        } catch (RefundActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->requiresConfirmation()
                    ->visible(fn (FeeRefund $record): bool => $record->status === 'pending')
                    ->action(function (FeeRefund $record): void {
                        try {
                            app(RefundApprovalService::class)->reject($record);
                            Notification::make()->title('Refund rejected')->success()->send();
                        } catch (RefundActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeRefunds::route('/'),
            'create' => CreateFeeRefund::route('/create'),
        ];
    }
}

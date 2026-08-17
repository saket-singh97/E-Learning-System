<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\SeatQuota;
use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Content\Services\BandwidthQuota;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * S11 B11 — read-only. Same "standalone Page, Placeholder-only form for
 * static display" shape CertificateSettings already established, combined
 * with Gradebook's own "EmbeddedTable in content()" shape for the invoice
 * history — first page in this app to need both at once.
 *
 * Reuses SeatQuota/StorageQuota/BandwidthQuota directly rather than
 * re-deriving usage numbers here — this page is a *view* onto numbers
 * those services already own, not a second source of truth for them.
 */
class BillingOverview extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Billing';

    public function form(Schema $schema): Schema
    {
        $tenant = app(CurrentTenant::class)->get();
        $plan = $tenant->plan;
        $subscription = TenantSubscription::latest('id')->first();

        return $schema->components([
            Placeholder::make('plan')
                ->label('Current plan')
                ->content($plan?->name ?? 'No plan assigned'),
            Placeholder::make('status')
                ->label('Subscription status')
                ->content($this->statusLabel($subscription)),
            Placeholder::make('seats')
                ->label('Staff seats')
                ->content(fn () => $this->usageBar(
                    app(SeatQuota::class)->usedNow($tenant),
                    app(SeatQuota::class)->capFor($tenant),
                    fn (int $n) => (string) $n,
                )),
            Placeholder::make('storage')
                ->label('Storage')
                ->content(fn () => $this->usageBar(
                    app(StorageQuota::class)->usedNow($tenant),
                    app(StorageQuota::class)->capFor($tenant),
                    fn (int $bytes) => number_format($bytes / 1024 / 1024 / 1024, 2).' GB',
                )),
            Placeholder::make('bandwidth')
                ->label('Bandwidth (today)')
                ->content(fn () => $this->usageBar(
                    app(BandwidthQuota::class)->usedToday($tenant),
                    app(BandwidthQuota::class)->capFor($tenant),
                    fn (int $bytes) => number_format($bytes / 1024 / 1024 / 1024, 2).' GB',
                )),
        ])->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SubscriptionInvoice::query()->latest('id'))
            ->heading('Invoice history')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SubscriptionInvoice::STATUS_PAID => 'success',
                        SubscriptionInvoice::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('period_start')->label('Period')->date(),
                TextColumn::make('period_end')->label('to')->date(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            EmbeddedTable::make(),
        ]);
    }

    protected function statusLabel(?TenantSubscription $subscription): string
    {
        if ($subscription === null) {
            return 'Not subscribed';
        }

        return match ($subscription->status) {
            TenantSubscription::STATUS_GRACE => 'Payment failed — grace period ends '.$subscription->grace_ends_at?->toFormattedDateString(),
            default => ucfirst($subscription->status),
        };
    }

    /**
     * @param  callable(int): string  $format
     */
    protected function usageBar(int $used, ?int $cap, callable $format): HtmlString
    {
        if ($cap === null) {
            return new HtmlString(e($format($used)).' used (unlimited)');
        }

        $percent = $cap > 0 ? min(100, (int) round($used / $cap * 100)) : 100;
        $color = $percent >= 90 ? '#dc2626' : ($percent >= 70 ? '#d97706' : '#16a34a');
        $label = e($format($used).' / '.$format($cap));

        return new HtmlString(
            '<div style="max-width:320px;">'
            .'<div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:4px;">'
            ."<span>{$label}</span><span>{$percent}%</span>"
            .'</div>'
            .'<div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">'
            ."<div style=\"background:{$color};width:{$percent}%;height:100%;\"></div>"
            .'</div>'
            .'</div>'
        );
    }
}

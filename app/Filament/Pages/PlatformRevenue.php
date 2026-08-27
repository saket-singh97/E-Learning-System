<?php

namespace App\Filament\Pages;

use App\Domains\Billing\Services\PlatformRevenueAnalytics;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * S20 Q6 — Super panel, cross-tenant by nature, same
 * `app/Filament/Pages/` home `InstituteSubscriptions` (S11 B12) already
 * established for this panel's own cross-tenant pages. Read-only, no
 * interactive table: `perInstituteRevenue()` returns a plain array, not an
 * Eloquent query, so a static HTML table (same `HtmlString` idiom
 * `BillingOverview::usageBar()` already uses) is simpler than forcing
 * Filament's table component onto a non-Eloquent-backed dataset.
 */
class PlatformRevenue extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Platform Revenue';

    public function form(Schema $schema): Schema
    {
        $analytics = app(PlatformRevenueAnalytics::class);
        $rate = $analytics->paymentSuccessFailureRate();
        // Computed once and reused for ARR — calling both mrr() and arr()
        // would run the same active-subscriptions query twice, caught in
        // B14's own review pass.
        $mrr = $analytics->mrr();

        return $schema->components([
            Placeholder::make('mrr')
                ->label('MRR')
                ->content('₹'.number_format($mrr, 2)),
            Placeholder::make('arr')
                ->label('ARR')
                ->content('₹'.number_format($mrr * 12, 2)),
            Placeholder::make('success_rate')
                ->label('Payment success rate')
                ->content($rate['success_rate'] === null
                    ? 'No settled payments yet.'
                    : "{$rate['success_rate']}% ({$rate['captured']} captured, {$rate['failed']} failed)"),
            Placeholder::make('per_institute_revenue')
                ->label('Revenue by institute')
                ->content(fn () => $this->revenueTable($analytics->perInstituteRevenue())),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    /**
     * @param  Collection<int, array{tenant_id: int, tenant_name: string, currency: string, amount: float}>  $rows
     */
    protected function revenueTable(Collection $rows): HtmlString
    {
        if ($rows->isEmpty()) {
            return new HtmlString('No captured payments yet.');
        }

        $lines = $rows
            ->sortByDesc('amount')
            ->map(fn (array $row): string => '<tr>'
                .'<td style="padding:4px 16px 4px 0;">'.e($row['tenant_name']).'</td>'
                .'<td style="padding:4px 0;">'.e($row['currency']).' '.number_format($row['amount'], 2).'</td>'
                .'</tr>')
            ->implode('');

        return new HtmlString('<table>'.$lines.'</table>');
    }
}

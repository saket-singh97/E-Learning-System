<?php

namespace App\Domains\Tenancy\Filament\Resources\Tenants\Pages;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\PlanChangeService;
use App\Domains\Security\Services\AuditLogger;
use App\Domains\Tenancy\Filament\Resources\Tenants\TenantResource;
use App\Domains\Tenancy\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * S11 B6 — decision 10: plan assignment is Super-panel-initiated,
     * through PlanChangeService::subscribe(), never a raw plan_id field
     * edit on this form — a bare column write would leave plan_id set
     * with no TenantSubscription row behind it, an inconsistent state
     * nothing else in Billing expects. Hidden once a plan already exists;
     * changing an *existing* plan is B10's changePlan(), a different
     * operation (proration), not this one.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('assignPlan')
                ->label('Assign plan')
                ->visible(fn (Tenant $record): bool => $record->plan_id === null)
                ->schema([
                    Select::make('plan_id')
                        ->label('Plan')
                        ->options(fn (): array => Plan::where('is_active', true)->pluck('name', 'id')->all())
                        ->required(),
                ])
                ->action(function (Tenant $record, array $data): void {
                    app(PlanChangeService::class)->subscribe($record, Plan::findOrFail($data['plan_id']));

                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
                }),
            // S11 B10 — the complement to assignPlan above: visible once a
            // plan already exists, goes through changePlan() (proration),
            // not subscribe() (which explicitly refuses a tenant that's
            // already subscribed).
            Action::make('changePlan')
                ->label('Change plan')
                ->visible(fn (Tenant $record): bool => $record->plan_id !== null)
                ->schema([
                    Select::make('plan_id')
                        ->label('New plan')
                        ->options(fn (Tenant $record): array => Plan::where('is_active', true)
                            ->where('id', '!=', $record->plan_id)
                            ->pluck('name', 'id')
                            ->all())
                        ->required(),
                ])
                ->action(function (Tenant $record, array $data): void {
                    $fromPlanId = $record->plan_id;
                    $newPlan = Plan::findOrFail($data['plan_id']);

                    app(PlanChangeService::class)->changePlan($record, $newPlan);

                    app(AuditLogger::class)->record(
                        action: 'plan.updated',
                        subject: $record,
                        actor: Auth::user(),
                        tenantId: $record->id,
                        metadata: ['from_plan_id' => $fromPlanId, 'to_plan_id' => $newPlan->id],
                    );

                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
                }),
            // S11 B13 — same visibility as changePlan (a live subscription
            // is required for either), destructive so it asks first;
            // reverts to plan_id = null, decision 11's own legacy-fallback
            // state, not a special "cancelled" plan.
            Action::make('cancelSubscription')
                ->label('Cancel subscription')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The institute reverts to the free-tier fallback limits immediately. This does not delete any billing history.')
                ->visible(fn (Tenant $record): bool => $record->plan_id !== null)
                ->action(function (Tenant $record): void {
                    app(PlanChangeService::class)->cancel($record);

                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
                }),
        ];
    }
}

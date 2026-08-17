<?php

namespace App\Domains\Tenancy\Filament\Resources\Tenants\Pages;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\PlanChangeService;
use App\Domains\Tenancy\Filament\Resources\Tenants\TenantResource;
use App\Domains\Tenancy\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;

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
        ];
    }
}

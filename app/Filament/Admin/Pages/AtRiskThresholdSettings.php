<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Services\StudentMetrics;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * S16 B1 (H10) — same single-field settings-page shape CustomDomainSettings
 * (S9 B4) established. A blank value clears the override, falling back to
 * StudentMetrics::AT_RISK_THRESHOLD (zero blast radius, design doc §4
 * decision 12).
 */
class AtRiskThresholdSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'At-Risk Threshold';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'at_risk_threshold_percent' => app(CurrentTenant::class)->get()->at_risk_threshold_percent,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('at_risk_threshold_percent')
                    ->label('At-risk threshold (%)')
                    ->helperText('Students below this attendance or coverage percentage are flagged at-risk. Leave blank to use the default of '.StudentMetrics::AT_RISK_THRESHOLD.'%.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->nullable(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save threshold')
                ->action(function (): void {
                    $tenant = app(CurrentTenant::class)->get();
                    $tenant->update([
                        'at_risk_threshold_percent' => $this->form->getState()['at_risk_threshold_percent'] ?: null,
                    ]);

                    Notification::make()
                        ->title('At-risk threshold saved.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }
}

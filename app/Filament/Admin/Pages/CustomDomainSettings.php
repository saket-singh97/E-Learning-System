<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Tenancy\Services\CustomDomainService;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

/**
 * DNS-verification-only (design doc §2.4 decision 3) — this page proves
 * domain ownership and lets ResolveTenant recognize it; actually serving
 * HTTPS on it stays a documented manual step (SETUP.md).
 */
class CustomDomainSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Custom Domain';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'custom_domain' => app(CurrentTenant::class)->get()->custom_domain,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $tenant = app(CurrentTenant::class)->get();
        $service = app(CustomDomainService::class);

        return $schema
            ->components([
                TextInput::make('custom_domain')
                    ->label('Custom domain')
                    ->placeholder('learn.yourschool.com')
                    ->rule(Rule::unique('tenants', 'custom_domain')->ignore($tenant->id))
                    ->nullable(),
                Placeholder::make('verification_status')
                    ->label('Status')
                    ->content(fn (): string => $tenant->hasVerifiedCustomDomain() ? 'Verified' : ($tenant->custom_domain ? 'Not verified yet' : 'No domain set')),
                Placeholder::make('txt_record')
                    ->label('DNS TXT record to add')
                    ->content(fn (): string => filled($tenant->custom_domain)
                        ? "{$service->challengeHost($tenant)} = {$service->verificationToken($tenant)}"
                        : 'Save a domain first to get its verification record.')
                    ->visible(fn (): bool => filled($tenant->custom_domain) && ! $tenant->hasVerifiedCustomDomain()),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save domain')
                ->action(function (): void {
                    $tenant = app(CurrentTenant::class)->get();
                    $domain = $this->form->getState()['custom_domain'] ?: null;

                    app(CustomDomainService::class)->setDomain($tenant, $domain);

                    Notification::make()
                        ->title($domain ? 'Domain saved — add the TXT record shown below, then verify.' : 'Domain removed.')
                        ->success()
                        ->send();

                    $this->form->fill(['custom_domain' => $tenant->fresh()->custom_domain]);
                }),
            Action::make('verify')
                ->label('Verify domain')
                ->visible(fn (): bool => app(CurrentTenant::class)->get()->fresh()?->custom_domain !== null
                    && ! app(CurrentTenant::class)->get()->fresh()?->hasVerifiedCustomDomain())
                ->action(function (): void {
                    $tenant = app(CurrentTenant::class)->get();
                    $verified = app(CustomDomainService::class)->verify($tenant);

                    Notification::make()
                        ->title($verified
                            ? 'Domain verified.'
                            : 'TXT record not found yet — DNS changes can take time to propagate.')
                        ->color($verified ? 'success' : 'danger')
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

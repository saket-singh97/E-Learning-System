<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Tenancy\Services\BrandingService;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * K2's per-institute certificate customisation (signature, background) —
 * same "standalone Filament Page implementing HasForms, EmbeddedSchema in
 * content(), a manual save Action rather than an EditRecord lifecycle"
 * shape CustomDomainSettings (S9 B4) established for this app's first
 * tenant-settings UI of any kind. Logo/primary colour already come from
 * the certificate template reading BrandingService directly — this page
 * only adds the two fields BrandingService had no upload surface for at
 * all (confirmed by searching: storeLogo()/storeFavicon() existed since
 * S1 but no Filament page anywhere ever called either).
 *
 * The two FileUpload fields never prefill with the already-stored path —
 * same posture LessonResource's own `video_upload` field takes (checked
 * before assuming otherwise): that field's own configured disk is the
 * *temp* upload disk ('local'), not the disk the final file actually
 * lives on ('public') once BrandingService moves it, so handing it a
 * 'public'-disk path to preview resolves against the wrong disk and
 * silently reads back empty (found running this exact page's own test —
 * assertFormSet() failed with null instead of the stored path). Current
 * status is shown via a plain Placeholder instead, decoupled from the
 * upload field's own state.
 */
class CertificateSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Certificate Branding';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $tenant = app(CurrentTenant::class)->get();

        return $schema
            ->components([
                Placeholder::make('current_signature')
                    ->label('Current signature')
                    ->content(fn (): string => $tenant->certificate_signature_path ? 'Uploaded' : 'None uploaded yet'),
                FileUpload::make('signature')
                    ->label('Replace signature image')
                    ->helperText('Shown on every certificate this institute issues.')
                    ->disk('local')
                    ->directory('certificate-branding/tmp')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->image()
                    ->dehydrated(),
                Placeholder::make('current_background')
                    ->label('Current background')
                    ->content(fn (): string => $tenant->certificate_background_path ? 'Uploaded' : 'None uploaded yet'),
                FileUpload::make('background')
                    ->label('Replace background image')
                    ->helperText('Optional — a full-bleed background behind the certificate text.')
                    ->disk('local')
                    ->directory('certificate-branding/tmp')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->image()
                    ->dehydrated(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save certificate branding')
                ->action(function (): void {
                    $tenant = app(CurrentTenant::class)->get();
                    $state = $this->form->getState();
                    $branding = app(BrandingService::class);

                    if (filled($state['signature'] ?? null)) {
                        $branding->storeCertificateSignature($tenant, $state['signature']);
                    }

                    if (filled($state['background'] ?? null)) {
                        $branding->storeCertificateBackground($tenant, $state['background']);
                    }

                    Notification::make()
                        ->title('Certificate branding saved.')
                        ->success()
                        ->send();

                    $this->form->fill();
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

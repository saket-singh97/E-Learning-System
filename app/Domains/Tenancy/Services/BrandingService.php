<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidFileTypeException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BrandingService
{
    /** @var array<int, string> */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'webp'];

    public const DEFAULT_PRIMARY_COLOR = '#f59e0b';

    public const DEFAULT_ACCENT_COLOR = '#f59e0b';

    public function __construct(protected CurrentTenant $currentTenant) {}

    /**
     * Filament panels evaluate their ->colors()/->favicon()/->brandLogo()
     * closures during provider boot, which runs before route middleware —
     * so ResolveTenant hasn't bound CurrentTenant yet on those panels' own
     * first request. Fall back to resolving straight from the request host,
     * the same lookup ResolveTenant itself does, so branding is still
     * correct even when read before the middleware runs.
     */
    public function tenant(): ?Tenant
    {
        if ($this->currentTenant->isBound()) {
            return $this->currentTenant->get();
        }

        if (! app()->bound('request')) {
            return null;
        }

        $subdomain = explode('.', request()->getHost())[0];

        return Tenant::where('subdomain', $subdomain)->first();
    }

    public function primaryColor(): string
    {
        return $this->tenant()?->primary_color ?? self::DEFAULT_PRIMARY_COLOR;
    }

    public function accentColor(): string
    {
        return $this->tenant()?->accent_color ?? self::DEFAULT_ACCENT_COLOR;
    }

    public function cssVariables(): string
    {
        return "--color-primary: {$this->primaryColor()}; --color-accent: {$this->accentColor()};";
    }

    public function logoUrl(): ?string
    {
        return $this->url($this->tenant()?->logo_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->url($this->tenant()?->favicon_path);
    }

    public function storeLogo(Tenant $tenant, UploadedFile $file): void
    {
        $tenant->update(['logo_path' => $this->storeUpload($tenant, $file, 'logo')]);
    }

    public function storeFavicon(Tenant $tenant, UploadedFile $file): void
    {
        $tenant->update(['favicon_path' => $this->storeUpload($tenant, $file, 'favicon')]);
    }

    /**
     * S10 K2 — same allow-list/disk posture as the logo/favicon uploads
     * above, but takes the FileUpload field's own dehydrated temp path
     * (a string), not a raw UploadedFile — this class's callers are a
     * standalone Filament Page's manual save action (CertificateSettings,
     * B12), not an EditRecord form whose framework hands a real
     * UploadedFile through. Re-wraps it the same way
     * Lesson::attachUploadedVideo() already does for the identical
     * "FileUpload dehydrates to a temp disk path" gotcha (EditLesson's own
     * docblock: reading $this->data instead of the dehydrated form data
     * hands an array of TemporaryUploadedFile objects, not a path string,
     * and 500s the save).
     */
    public function storeCertificateSignature(Tenant $tenant, string $tempDiskPath): void
    {
        $tenant->update(['certificate_signature_path' => $this->storeTempUpload($tenant, $tempDiskPath, 'certificate-signature')]);
    }

    public function storeCertificateBackground(Tenant $tenant, string $tempDiskPath): void
    {
        $tenant->update(['certificate_background_path' => $this->storeTempUpload($tenant, $tempDiskPath, 'certificate-background')]);
    }

    /**
     * dompdf renders locally and reads images straight off disk — an
     * absolute filesystem path, not a public URL, is what its own `<img
     * src="...">` resolution needs (no "isRemoteEnabled" fetch-over-HTTP
     * detour for an asset that's already sitting on the same disk).
     */
    public function certificateSignaturePath(Tenant $tenant): ?string
    {
        return $tenant->certificate_signature_path
            ? Storage::disk('public')->path($tenant->certificate_signature_path)
            : null;
    }

    public function certificateBackgroundPath(Tenant $tenant): ?string
    {
        return $tenant->certificate_background_path
            ? Storage::disk('public')->path($tenant->certificate_background_path)
            : null;
    }

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    protected function storeTempUpload(Tenant $tenant, string $tempDiskPath, string $field): string
    {
        $file = new UploadedFile(
            Storage::disk('local')->path($tempDiskPath),
            basename($tempDiskPath),
            test: true,
        );

        $diskPath = $this->storeUpload($tenant, $file, $field);

        Storage::disk('local')->delete($tempDiskPath);

        return $diskPath;
    }

    protected function storeUpload(Tenant $tenant, UploadedFile $file, string $field): string
    {
        $extension = strtolower((string) $file->extension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw InvalidFileTypeException::forField($field, $extension, self::ALLOWED_EXTENSIONS);
        }

        return $file->storeAs("branding/{$tenant->id}", "{$field}.{$extension}", 'public');
    }
}

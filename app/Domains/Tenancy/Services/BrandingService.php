<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\StorageLimitExceededException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BrandingService
{
    /** @var array<int, string> */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'webp'];

    public const DEFAULT_PRIMARY_COLOR = '#f59e0b';

    public const DEFAULT_ACCENT_COLOR = '#f59e0b';

    public function __construct(
        protected CurrentTenant $currentTenant,
        // S11 B4 (Q2) — same deliberate cross-domain call B3/B4's other
        // sites already establish and document once each; not repeated
        // at length here.
        private readonly StorageQuota $storageQuota,
    ) {}

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
        $tenant->update(['logo_path' => $this->storeUpload($tenant, $file, 'logo', $tenant->logo_path)]);
    }

    public function storeFavicon(Tenant $tenant, UploadedFile $file): void
    {
        $tenant->update(['favicon_path' => $this->storeUpload($tenant, $file, 'favicon', $tenant->favicon_path)]);
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
        $tenant->update(['certificate_signature_path' => $this->storeTempUpload($tenant, $tempDiskPath, 'certificate-signature', $tenant->certificate_signature_path)]);
    }

    public function storeCertificateBackground(Tenant $tenant, string $tempDiskPath): void
    {
        $tenant->update(['certificate_background_path' => $this->storeTempUpload($tenant, $tempDiskPath, 'certificate-background', $tenant->certificate_background_path)]);
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

    protected function storeTempUpload(Tenant $tenant, string $tempDiskPath, string $field, ?string $previousPath): string
    {
        $file = new UploadedFile(
            Storage::disk('local')->path($tempDiskPath),
            basename($tempDiskPath),
            test: true,
        );

        $diskPath = $this->storeUpload($tenant, $file, $field, $previousPath);

        Storage::disk('local')->delete($tempDiskPath);

        return $diskPath;
    }

    /**
     * S11 B4 (Q2) — $previousPath is the field's current value, read by
     * every caller *before* it overwrites it. storeAs() below always
     * computes "{$field}.{$extension}" — a same-extension re-upload lands
     * on the exact same path (overwrite in place, no net disk growth), so
     * without reading the old size first the running counter would grow
     * on every re-upload of an unchanged logo/favicon/signature/background.
     */
    protected function storeUpload(Tenant $tenant, UploadedFile $file, string $field, ?string $previousPath): string
    {
        $extension = strtolower((string) $file->extension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw InvalidFileTypeException::forField($field, $extension, self::ALLOWED_EXTENSIONS);
        }

        $newBytes = $file->getSize();
        $previousBytes = ($previousPath && Storage::disk('public')->exists($previousPath))
            ? Storage::disk('public')->size($previousPath)
            : 0;

        if ($newBytes - $previousBytes > $this->storageQuota->remainingFor($tenant)) {
            throw StorageLimitExceededException::forTenant($tenant->id);
        }

        $diskPath = $file->storeAs("branding/{$tenant->id}", "{$field}.{$extension}", 'public');

        $this->storageQuota->adjust($tenant, $newBytes - $previousBytes);

        return $diskPath;
    }
}

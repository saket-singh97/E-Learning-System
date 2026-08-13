<?php

namespace App\Providers;

use App\Domains\AI\Listeners\RetryIngestion;
use App\Domains\AI\Listeners\StartIngestion;
use App\Domains\AI\Livewire\DoubtChat;
use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Events\EnrolmentApprovalExpired;
use App\Domains\Batches\Events\EnrolmentAwaitingPayment;
use App\Domains\Batches\Listeners\FinalizeEnrolmentOnPaymentCaptured;
use App\Domains\Batches\Listeners\SendEnrolmentExpiredNotification;
use App\Domains\Batches\Livewire\BatchCatalog;
use App\Domains\Comms\Listeners\SendAbsenceAlert;
use App\Domains\Comms\Listeners\SendLowAttendanceAlert;
use App\Domains\Content\Events\LessonIngestionRetryRequested;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Listeners\CreateInvoiceForPendingEnrolment;
use App\Domains\AI\Services\AiSpendCap;
use App\Integrations\AiProvider;
use App\Integrations\CommsProvider;
use App\Integrations\DnsResolver;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\Drivers\FakeLiveClassDriver;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Integrations\Drivers\FakeVideoDriver;
use App\Integrations\Drivers\FakeWhatsAppDriver;
use App\Integrations\Drivers\RealDnsResolver;
use App\Integrations\Drivers\SpendCapGuardedAiDriver;
use App\Integrations\LiveClassProvider;
use App\Integrations\PaymentProvider;
use App\Integrations\VideoProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
        $this->app->bind(VideoProvider::class, FakeVideoDriver::class);

        // Wraps the driver, not a sixth spend-cap check duplicated across
        // every AiProvider call site (S9 B12) — every call site resolves
        // this binding fresh from the container (confirmed by reading all
        // six), so decorating it here covers all of them in one place.
        $this->app->bind(AiProvider::class, fn ($app) => new SpendCapGuardedAiDriver(
            $app->make(FakeAiDriver::class),
            $app->make(AiSpendCap::class),
        ));

        $this->app->bind(LiveClassProvider::class, FakeLiveClassDriver::class);
        $this->app->bind(PaymentProvider::class, FakeRazorpayDriver::class);
        $this->app->bind(CommsProvider::class, FakeWhatsAppDriver::class);

        // Unlike every driver above, this needs no vendor account —
        // dns_get_record() is a plain PHP builtin, confirmed callable on
        // this project's native Windows PHP 8.4 build (S9 B4) — so the
        // real driver binds by default, not a Fake.
        $this->app->bind(DnsResolver::class, RealDnsResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDomainViewNamespaces();
        $this->registerTenantAwareAuthUrls();

        // No EventServiceProvider (its auto-discovery only scans app/Listeners,
        // not app/Domains/*/Listeners) — cross-domain event wiring belongs here,
        // it's the boundary connecting Content -> AI, not either domain's own concern.
        Event::listen(LessonVideoReady::class, StartIngestion::class);
        Event::listen(LessonIngestionRetryRequested::class, RetryIngestion::class);
        Event::listen(EnrolmentApprovalExpired::class, SendEnrolmentExpiredNotification::class);
        Event::listen(EnrolmentAwaitingPayment::class, CreateInvoiceForPendingEnrolment::class);
        Event::listen(PaymentCaptured::class, FinalizeEnrolmentOnPaymentCaptured::class);
        Event::listen(AttendanceRecorded::class, SendAbsenceAlert::class);
        Event::listen(AttendanceRecorded::class, SendLowAttendanceAlert::class);

        // Lives at App\Domains\AI\Livewire, outside Livewire's default
        // App\Livewire auto-discovery root, so the <livewire:ai.doubt-chat />
        // tag needs an explicit alias.
        Livewire::component('ai.doubt-chat', DoubtChat::class);
        Livewire::component('batches.batch-catalog', BatchCatalog::class);

        // Unscoped (no third $scopes argument) — applies to all three
        // Filament panels (admin/teach/super) with one registration,
        // matching NotificationCentre's own "shared... staff panels
        // alike" requirement instead of three near-identical calls in
        // each PanelProvider.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render('<livewire:notification-centre /><livewire:impersonation-banner /><livewire:user-profile />'),
        );
    }

    /**
     * Verification and reset-password links must point at the user's tenant
     * subdomain, not the central app URL, since auth routes live under
     * Route::domain('{subdomain}.lms.test').
     */
    protected function registerTenantAwareAuthUrls(): void
    {
        VerifyEmail::createUrlUsing(function (User $notifiable) {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                [
                    'subdomain' => $this->tenantSubdomainOrFail($notifiable),
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });

        ResetPassword::createUrlUsing(function (User $notifiable, string $token) {
            return sprintf(
                '%s://%s.lms.test/reset-password/%s?email=%s',
                request()?->getScheme() ?? 'http',
                $this->tenantSubdomainOrFail($notifiable),
                $token,
                urlencode($notifiable->getEmailForPasswordReset())
            );
        });
    }

    /**
     * Every verification/reset route lives under Route::domain('{subdomain}.lms.test')
     * — there is no bare-domain equivalent yet. A tenant_id-null user (platform-level
     * super admin) has no subdomain to build one, and super-admin auth isn't wired up
     * yet, so fail loudly with a clear cause instead of a null-property fatal error.
     */
    protected function tenantSubdomainOrFail(User $notifiable): string
    {
        return $notifiable->tenant?->subdomain ?? throw new \LogicException(
            "Cannot build a tenant-scoped auth URL for user [{$notifiable->id}]: it has no tenant_id. ".
            'Platform-level (super admin) email verification / password reset is not supported yet.'
        );
    }

    /**
     * Domain views live at app/Domains/{Domain}/Views, not resources/views,
     * so each domain needs its own namespace: view('tenancy::ping').
     */
    protected function registerDomainViewNamespaces(): void
    {
        foreach (glob(app_path('Domains/*'), GLOB_ONLYDIR) as $domainPath) {
            $domain = strtolower(basename($domainPath));

            View::addNamespace($domain, "{$domainPath}/Views");
        }
    }
}

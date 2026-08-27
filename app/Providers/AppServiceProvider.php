<?php

namespace App\Providers;

use App\Domains\AI\Listeners\RetryIngestion;
use App\Domains\AI\Listeners\StartIngestion;
use App\Domains\AI\Livewire\DoubtChat;
use App\Domains\AI\Livewire\LessonRevisionNotes;
use App\Domains\AI\Livewire\PhotoDoubtUpload;
use App\Domains\AI\Livewire\SemanticSearch;
use App\Domains\AI\Livewire\WeakTopics;
use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Events\EnrolmentApprovalExpired;
use App\Domains\Batches\Events\EnrolmentAwaitingPayment;
use App\Domains\Batches\Listeners\FinalizeEnrolmentOnPaymentCaptured;
use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\Content\Listeners\EnrolFromProgramPayment;
use App\Domains\Content\Listeners\GenerateLessonSubtitles;
use App\Domains\Batches\Listeners\SendEnrolmentExpiredNotification;
use App\Domains\Batches\Livewire\BatchCatalog;
use App\Domains\Batches\Livewire\BatchFeedbackForm;
use App\Domains\Comms\Livewire\BatchForum;
use App\Domains\Comms\Livewire\DirectMessages;
use App\Domains\Comms\Livewire\GeneralForum;
use App\Domains\Comms\Livewire\HelpDeskTicketForm;
use App\Domains\Comms\Livewire\NoticeBoard;
use App\Domains\Comms\Livewire\SurveyRunner;
use App\Domains\Growth\Livewire\AffiliateDashboard;
use App\Domains\Growth\Livewire\CourseInterestForm;
use App\Domains\Planner\Livewire\Calendar;
use App\Domains\Content\Livewire\CourseReviews;
use App\Domains\Content\Livewire\LessonNotes;
use App\Domains\Content\Livewire\LessonQuestions;
use App\Domains\Batches\Listeners\BustAggregateCacheOnAttendance;
use App\Domains\Comms\Listeners\SendAbsenceAlert;
use App\Domains\Comms\Listeners\SendLowAttendanceAlert;
use App\Domains\Content\Events\LessonIngestionRetryRequested;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Fees\Events\FeeRefundProcessed;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Events\ProgramInvoicePaid;
use App\Domains\Fees\Listeners\CreateInvoiceForPendingEnrolment;
use App\Domains\Growth\Listeners\ReverseAffiliateCommissionOnRefund;
use App\Domains\Security\Listeners\ReconcileCookieConsent;
use App\Domains\Security\Listeners\RecordLoginHistory;
use App\Domains\Security\Listeners\RegisterDeviceOnLogin;
use App\Domains\AI\Services\AiSpendCap;
use App\Domains\Analytics\Listeners\AwardXpOnAssignmentSubmission;
use App\Domains\Analytics\Listeners\AwardXpOnAttendance;
use App\Domains\Analytics\Listeners\AwardXpOnQuizSubmission;
use App\Domains\Analytics\Livewire\GamificationWidget;
use App\Domains\Assessments\Events\QuizAttemptSubmitted;
use App\Domains\Assignments\Events\AssignmentSubmitted;
use App\Integrations\AiProvider;
use App\Integrations\CommsProvider;
use App\Integrations\DnsResolver;
use App\Integrations\Drivers\CircuitBreakerGuardedAiDriver;
use App\Integrations\Drivers\CircuitBreakerGuardedCommsDriver;
use App\Integrations\Drivers\CircuitBreakerGuardedLiveClassDriver;
use App\Integrations\Drivers\CircuitBreakerGuardedPaymentDriver;
use App\Integrations\Drivers\CircuitBreakerGuardedSubscriptionDriver;
use App\Integrations\Drivers\CircuitBreakerGuardedVideoDriver;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\Drivers\FakeGeoIpResolver;
use App\Integrations\Drivers\FakeLiveClassDriver;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Integrations\Drivers\FakeVideoDriver;
use App\Integrations\Drivers\FakeWhatsAppDriver;
use App\Integrations\Drivers\FakeRazorpaySubscriptionDriver;
use App\Integrations\Drivers\RealDnsResolver;
use App\Integrations\Drivers\SpendCapGuardedAiDriver;
use App\Integrations\GeoIpResolver;
use App\Integrations\LiveClassProvider;
use App\Integrations\PaymentProvider;
use App\Integrations\SubscriptionProvider;
use App\Integrations\VideoProvider;
use App\Models\User;
use App\Support\Resilience\CircuitBreaker;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
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
        $this->app->singleton(CircuitBreaker::class);

        // S12 B10 — circuit breaker wraps the real/fake driver directly
        // (the outbound call itself), not the outermost binding — a real
        // provider outage is what should trip it.
        $this->app->bind(VideoProvider::class, fn ($app) => new CircuitBreakerGuardedVideoDriver(
            $app->make(FakeVideoDriver::class),
            $app->make(CircuitBreaker::class),
        ));

        // Wraps the driver, not a sixth spend-cap check duplicated across
        // every AiProvider call site (S9 B12) — every call site resolves
        // this binding fresh from the container (confirmed by reading all
        // six), so decorating it here covers all of them in one place.
        // S12 B10 — the circuit breaker sits *inside* the spend-cap
        // decorator, not outside it: a spend-cap denial is a business-rule
        // pre-check, never a real provider failure, so it must never count
        // toward tripping the breaker.
        $this->app->bind(AiProvider::class, fn ($app) => new SpendCapGuardedAiDriver(
            new CircuitBreakerGuardedAiDriver(
                $app->make(FakeAiDriver::class),
                $app->make(CircuitBreaker::class),
            ),
            $app->make(AiSpendCap::class),
        ));

        // S12 B11 — same "wrap the container binding" shape B10 already
        // established for Video/AiProvider; protects whichever driver is
        // bound, fake today or a real one later.
        $this->app->bind(LiveClassProvider::class, fn ($app) => new CircuitBreakerGuardedLiveClassDriver(
            $app->make(FakeLiveClassDriver::class),
            $app->make(CircuitBreaker::class),
        ));
        $this->app->bind(PaymentProvider::class, fn ($app) => new CircuitBreakerGuardedPaymentDriver(
            $app->make(FakeRazorpayDriver::class),
            $app->make(CircuitBreaker::class),
        ));
        $this->app->bind(SubscriptionProvider::class, fn ($app) => new CircuitBreakerGuardedSubscriptionDriver(
            $app->make(FakeRazorpaySubscriptionDriver::class),
            $app->make(CircuitBreaker::class),
        ));
        $this->app->bind(CommsProvider::class, fn ($app) => new CircuitBreakerGuardedCommsDriver(
            $app->make(FakeWhatsAppDriver::class),
            $app->make(CircuitBreaker::class),
        ));

        // Unlike every driver above, this needs no vendor account —
        // dns_get_record() is a plain PHP builtin, confirmed callable on
        // this project's native Windows PHP 8.4 build (S9 B4) — so the
        // real driver binds by default, not a Fake.
        $this->app->bind(DnsResolver::class, RealDnsResolver::class);

        // S12 B3 — no real driver exists yet; see FakeGeoIpResolver's own
        // docblock for why (MaxMind gates even the free GeoLite2 tier
        // behind account registration, unlike DnsResolver's plain PHP
        // builtin above).
        $this->app->bind(GeoIpResolver::class, FakeGeoIpResolver::class);
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
        // S14 B2 — subtitles ride the same ingestion-complete signal
        // StartIngestion's own chain produces, not a separate manual trigger.
        Event::listen(LessonArtifactReady::class, GenerateLessonSubtitles::class);
        Event::listen(LessonIngestionRetryRequested::class, RetryIngestion::class);
        Event::listen(EnrolmentApprovalExpired::class, SendEnrolmentExpiredNotification::class);
        Event::listen(EnrolmentAwaitingPayment::class, CreateInvoiceForPendingEnrolment::class);
        Event::listen(PaymentCaptured::class, FinalizeEnrolmentOnPaymentCaptured::class);
        // S13 B17 — Fees' program-invoice half of the same PaymentCaptured
        // shape, fixed from a direct PaymentReconciler -> ProgramEnrolmentService
        // call the review pass caught (CLAUDE.md: Events only, never a
        // direct cross-domain service call).
        Event::listen(ProgramInvoicePaid::class, EnrolFromProgramPayment::class);
        // S20 R1 pt.2 — Growth's own reversal-on-refund, same event-only
        // discipline as the line above.
        Event::listen(FeeRefundProcessed::class, ReverseAffiliateCommissionOnRefund::class);
        Event::listen(AttendanceRecorded::class, SendAbsenceAlert::class);
        Event::listen(AttendanceRecorded::class, SendLowAttendanceAlert::class);

        // S12 B12 — a third listener on the same event, its own concern
        // (aggregate-cache invalidation), not merged into either alert
        // listener above.
        Event::listen(AttendanceRecorded::class, BustAggregateCacheOnAttendance::class);

        // S19 B13 (N18) — a fourth listener on the same event: XP/streak
        // gamification, its own concern, Analytics listening without Batches
        // knowing it exists (design doc §4 decision 8).
        Event::listen(AttendanceRecorded::class, AwardXpOnAttendance::class);
        Event::listen(QuizAttemptSubmitted::class, AwardXpOnQuizSubmission::class);
        Event::listen(AssignmentSubmitted::class, AwardXpOnAssignmentSubmission::class);

        // S12 B1 — the one point every login surface already funnels
        // through (password, TOTP, Google OAuth, all three Filament
        // panels, both portals); see the listener's own docblock.
        Event::listen(Login::class, RegisterDeviceOnLogin::class);

        // S12 B3 — a sibling listener on the same event, its own concern
        // (login history + impossible-travel), not merged into B1's own
        // class; see the listener's own docblock.
        Event::listen(Login::class, RecordLoginHistory::class);

        // S12 B7 — a third sibling listener on the same event; unlike the
        // two above, never skipped for a null-tenant login (see the
        // listener's own docblock).
        Event::listen(Login::class, ReconcileCookieConsent::class);

        // Lives at App\Domains\AI\Livewire, outside Livewire's default
        // App\Livewire auto-discovery root, so the <livewire:ai.doubt-chat />
        // tag needs an explicit alias.
        Livewire::component('ai.doubt-chat', DoubtChat::class);
        Livewire::component('ai.lesson-revision-notes', LessonRevisionNotes::class);
        Livewire::component('ai.semantic-search', SemanticSearch::class);
        Livewire::component('ai.weak-topics', WeakTopics::class);
        Livewire::component('ai.photo-doubt-upload', PhotoDoubtUpload::class);
        Livewire::component('batches.batch-catalog', BatchCatalog::class);
        Livewire::component('content.lesson-questions', LessonQuestions::class);
        Livewire::component('content.lesson-notes', LessonNotes::class);
        Livewire::component('comms.direct-messages', DirectMessages::class);
        Livewire::component('content.course-reviews', CourseReviews::class);
        // Found missing (S15 B17's own full-suite run): comms.batch-forum
        // (B6) and batches.batch-feedback-form (B7) were never registered
        // either — both silently 500'd on their real host pages, only ever
        // caught by direct Livewire::test(Class::class) calls that bypass
        // tag-name resolution entirely, never an HTTP hit on the real page.
        Livewire::component('comms.batch-forum', BatchForum::class);
        Livewire::component('comms.general-forum', GeneralForum::class);
        Livewire::component('comms.survey-runner', SurveyRunner::class);
        Livewire::component('comms.help-desk-ticket-form', HelpDeskTicketForm::class);
        Livewire::component('planner.calendar', Calendar::class);
        Livewire::component('growth.affiliate-dashboard', AffiliateDashboard::class);
        Livewire::component('growth.course-interest-form', CourseInterestForm::class);
        Livewire::component('batches.batch-feedback-form', BatchFeedbackForm::class);
        Livewire::component('comms.notice-board', NoticeBoard::class);
        Livewire::component('analytics.gamification-widget', GamificationWidget::class);

        // Unscoped (no third $scopes argument) — applies to all three
        // Filament panels (admin/teach/super) with one registration,
        // matching NotificationCentre's own "shared... staff panels
        // alike" requirement instead of three near-identical calls in
        // each PanelProvider.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render('<livewire:notification-centre /><livewire:impersonation-banner /><livewire:user-profile /><livewire:cookie-consent-banner />'),
        );

        // S12 B7 — TOPBAR_END above only renders inside an authenticated
        // panel shell; the guest login page (no topbar) needs the same
        // banner on its own hook, or a visitor who never logs in never
        // sees it at all.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_END,
            fn (): string => Blade::render('<livewire:cookie-consent-banner />'),
        );

        // S11 B9 — grace period is an institute-level billing concern an
        // institute_admin needs to see, not a super_admin (no tenant of
        // their own) or instructor/student (no billing visibility at all,
        // per design doc §2.2's own scope boundary) one. Registered
        // unscoped on the same proven TOPBAR_END hook as the trio above,
        // gated inside this closure via Filament::getCurrentPanel()
        // instead of the hook system's own `scopes:` argument — a
        // `scopes: 'admin'` registration on this exact hook silently never
        // rendered on a real Dashboard page (found by a failing test, not
        // assumed; root cause not chased further once this reliable fix
        // was in hand). The closure only runs during a real panel-page
        // render, so `getCurrentPanel()` is always resolvable here, unlike
        // inside the component itself when it's unit-tested directly via
        // `Livewire::test()` with no panel context at all.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Filament::getCurrentPanel()?->getId() === 'admin'
                ? Blade::render('<livewire:grace-period-banner />')
                : '',
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

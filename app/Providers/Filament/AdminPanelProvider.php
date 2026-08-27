<?php

namespace App\Providers\Filament;

use App\Domains\Assessments\Filament\Resources\OmrSheetScans\OmrSheetScanResource;
use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use App\Domains\Content\Filament\Resources\Categories\CategoryResource;
use App\Domains\Content\Filament\Resources\CourseReviews\CourseReviewResource;
use App\Domains\Content\Filament\Resources\Programs\ProgramResource;
use App\Domains\Content\Filament\Resources\Tags\TagResource;
use App\Domains\Tenancy\Services\BrandingService;
use App\Filament\Admin\Auth\Login;
use App\Filament\Widgets\CircuitBreakerStatusWidget;
use App\Filament\Widgets\TokenSpendWidget;
use App\Http\Middleware\EnsureAccountNotFlaggedForSharing;
use App\Http\Middleware\EnsureDeviceNotRevoked;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveImpersonation;
use App\Http\Middleware\ResolveTenant;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->domain('{subdomain}.lms.test')
            ->login(Login::class)
            ->profile()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->colors(fn () => ['primary' => app(BrandingService::class)->primaryColor()])
            ->favicon(fn () => app(BrandingService::class)->faviconUrl())
            ->brandLogo(fn () => app(BrandingService::class)->logoUrl())
            ->discoverResources(in: app_path('Domains/Tenancy/Filament/Resources'), for: 'App\Domains\Tenancy\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Batches/Filament/Resources'), for: 'App\Domains\Batches\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Fees/Filament/Resources'), for: 'App\Domains\Fees\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Comms/Filament/Resources'), for: 'App\Domains\Comms\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Operations/Filament/Resources'), for: 'App\Domains\Operations\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Growth/Filament/Resources'), for: 'App\Domains\Growth\Filament\Resources')
            // Explicit, not discoverResources — Content's own resource dir
            // also holds CourseResource et al., deliberately kept off this
            // panel (instructor-only, Teach panel). Category/Tag taxonomy
            // and Program bundle authoring are the Content concerns that
            // are an institute_admin job, so each is registered by name
            // instead of by directory scan. (S13 B7: the plan's own text
            // said "Super-panel ProgramResource" — Program is tenant-scoped
            // (BelongsToTenant), and SuperPanelProvider resolves no tenant
            // at all, so that placement can't actually work; Admin panel
            // matches every other tenant-owned Content resource instead.)
            ->resources([
                CategoryResource::class,
                TagResource::class,
                ProgramResource::class,
                CourseReviewResource::class,
                // S16 B12 — same explicit-registration reasoning as
                // Program/Category/Tag above: the Assessments resource
                // directory is otherwise Teach-panel-only
                // (Question/Quiz/QuizAnswer/FlaggedQuizAttempt), but a test
                // series bundles quizzes across the institute, an
                // institute_admin concern.
                TestSeriesResource::class,
                // S16 B15 — same explicit-registration reasoning as
                // TestSeriesResource above.
                OmrSheetScanResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
                TokenSpendWidget::class,
                CircuitBreakerStatusWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                ResolveTenant::class,
                ResolveImpersonation::class,
                EnsureDeviceNotRevoked::class,
                EnsureAccountNotFlaggedForSharing::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureRole::class.':institute_admin',
            ]);
    }
}

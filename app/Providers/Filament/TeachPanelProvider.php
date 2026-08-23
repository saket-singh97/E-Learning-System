<?php

namespace App\Providers\Filament;

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use App\Domains\Tenancy\Services\BrandingService;
use App\Filament\Teach\Auth\Login;
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

class TeachPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('teach')
            ->path('teach')
            ->domain('{subdomain}.lms.test')
            ->login(Login::class)
            ->profile()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->colors(fn () => ['primary' => app(BrandingService::class)->primaryColor()])
            ->favicon(fn () => app(BrandingService::class)->faviconUrl())
            ->brandLogo(fn () => app(BrandingService::class)->logoUrl())
            ->discoverResources(in: app_path('Domains/Tenancy/Filament/Resources'), for: 'App\Domains\Tenancy\Filament\Resources')
            // Directory-wide is safe here — Operations only ever holds
            // enquiry-related resources this slice, and enquiries are
            // tenant-wide CRM data, not per-instructor-scoped like Batch
            // (B2's own reason for switching Content/Batches to an
            // explicit list doesn't apply to this domain).
            ->discoverResources(in: app_path('Domains/Operations/Filament/Resources'), for: 'App\Domains\Operations\Filament\Resources')
            // Explicit, not discoverResources (S13 B17 fix) — Content's own
            // resource directory also holds CategoryResource/TagResource/
            // ProgramResource/CourseReviewResource, all deliberately
            // Admin-panel-only (institute_admin: taxonomy governance,
            // bundle pricing, review moderation). A directory-wide scan
            // here silently exposed all four to any instructor regardless
            // of AdminPanelProvider's own explicit ->resources() array,
            // since that array only *adds* them to Admin, it never
            // *removes* them from a sibling panel's own directory scan —
            // found by B17's own review pass. CourseSectionResource is
            // hidden from nav (CLAUDE.md) but still needs its own routes
            // for SectionsRelationManager's edit deep-link, so it's listed
            // explicitly here too, not dropped.
            // BatchResource added S15 B2 — closes §S.S1/§S.S2's own real gap
            // (design doc §2.3): instructors had no Teach-panel route to
            // Batch/BatchSession/Enrolment at all before this, which is
            // also why EnrolmentApprovalPolicy::DECIDER_ROLES already
            // listing 'instructor' was dead code — nothing could reach
            // EnrolmentsRelationManager's approve/reject actions as an
            // instructor. BatchResource::getEloquentQuery() scopes it to
            // the current instructor's own assigned batches only.
            ->resources([
                CourseResource::class,
                CourseSectionResource::class,
                LessonResource::class,
                BatchResource::class,
            ])
            ->discoverResources(in: app_path('Domains/Assessments/Filament/Resources'), for: 'App\Domains\Assessments\Filament\Resources')
            ->discoverResources(in: app_path('Domains/Assignments/Filament/Resources'), for: 'App\Domains\Assignments\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Teach/Pages'), for: 'App\Filament\Teach\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Teach/Widgets'), for: 'App\Filament\Teach\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
                EnsureRole::class.':instructor',
            ]);
    }
}

<?php

namespace App\Providers\Filament;

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
            ->discoverResources(in: app_path('Domains/Content/Filament/Resources'), for: 'App\Domains\Content\Filament\Resources')
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

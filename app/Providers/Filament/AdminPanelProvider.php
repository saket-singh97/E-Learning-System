<?php

namespace App\Providers\Filament;

use App\Domains\Tenancy\Services\BrandingService;
use App\Filament\Admin\Auth\Login;
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
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
                TokenSpendWidget::class,
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

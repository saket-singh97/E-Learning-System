@props(['title' => null, 'wide' => false])
@php($branding = app(\App\Domains\Tenancy\Services\BrandingService::class))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ? "{$title} — " . config('app.name') : config('app.name') }}</title>
    @if($favicon = $branding->faviconUrl())
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <style>:root { {{ $branding->cssVariables() }} }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="lms-shell">
    <livewire:impersonation-banner />

    <div class="lms-app">
        <x-lms-sidebar />

        <div class="lms-app-main">
            <header class="lms-topbar">
                <span class="lms-topbar-title">{{ $title ?? 'Dashboard' }}</span>
                @auth
                    <span class="lms-topbar-user">{{ auth()->user()->name }}</span>
                @endauth
            </header>

            <main class="lms-content @if($wide) lms-content--wide @endif">
                {{ $slot }}
            </main>
        </div>
    </div>

    <livewire:cookie-consent-banner />

    @livewireScripts
</body>
</html>

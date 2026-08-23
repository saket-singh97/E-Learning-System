@php($branding = app(\App\Domains\Tenancy\Services\BrandingService::class))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
    @if($favicon = $branding->faviconUrl())
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <style>:root { {{ $branding->cssVariables() }} }</style>
    @livewireStyles
</head>
<body>
    @if($logo = $branding->logoUrl())
        <img src="{{ $logo }}" alt="{{ config('app.name') }}">
    @endif

    {{-- parent-portal mounts first on purpose: its own mount() runs a
         tenant-mismatch check that force-logs-out a cross-tenant session
         (SESSION_DOMAIN is shared) — notification-centre must render
         after that check has had a chance to run, or it briefly reads
         Auth::user() before the logout takes effect (S8 B8 review). --}}
    <livewire:parent-portal />

    <livewire:comms.notice-board />
    <livewire:notification-centre />
    <livewire:impersonation-banner />
    <livewire:user-profile />
    <livewire:cookie-consent-banner />

    @livewireScripts
</body>
</html>

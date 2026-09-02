@php
    $subdomain = app(\App\Support\Tenancy\CurrentTenant::class)->isBound()
        ? app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain
        : null;

    $links = $subdomain ? [
        ['route' => 'student.dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
        ['route' => 'student.batches', 'label' => 'Batches', 'icon' => 'users'],
        ['route' => 'catalog.index', 'label' => 'Courses', 'icon' => 'book'],
        ['route' => 'wishlist.index', 'label' => 'Wishlist', 'icon' => 'heart'],
        ['route' => 'calendar.show', 'label' => 'Calendar', 'icon' => 'calendar'],
        ['route' => 'general-forum.show', 'label' => 'Forum', 'icon' => 'chat'],
        ['route' => 'messages.index', 'label' => 'Messages', 'icon' => 'mail'],
        ['route' => 'survey-runner.show', 'label' => 'Surveys', 'icon' => 'clipboard'],
        ['route' => 'gamification.show', 'label' => 'Progress', 'icon' => 'star'],
        ['route' => 'affiliate.dashboard', 'label' => 'Affiliate', 'icon' => 'share'],
        ['route' => 'help-desk.show', 'label' => 'Help desk', 'icon' => 'help'],
    ] : [];

    $icons = [
        'home' => 'M3 11.5 12 4l9 7.5M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9',
        'users' => 'M17 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M13 8a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm8 12v-1a3.5 3.5 0 0 0-2.5-3.36M16.5 8.13a3 3 0 0 1 0 5.74',
        'book' => 'M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15ZM4 20.5A2.5 2.5 0 0 1 6.5 18H20',
        'heart' => 'M12 20.5s-7.5-4.6-9.8-9.1C.6 8.2 2 4.8 5.4 4.1 7.6 3.6 10 4.7 12 7c2-2.3 4.4-3.4 6.6-2.9 3.4.7 4.8 4.1 3.2 7.3-2.3 4.5-9.8 9.1-9.8 9.1Z',
        'calendar' => 'M4 9h16M7 3v3m10-3v3M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z',
        'chat' => 'M4 5h16v11H8l-4 4V5Z',
        'mail' => 'M4 5h16v14H4V5Zm0 0 8 7 8-7',
        'clipboard' => 'M9 3h6v3H9V3ZM6 6h12v15H6V6Zm3 5h6m-6 4h6',
        'star' => 'm12 3 2.6 5.5 6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6-4.4-4.2 6-.8L12 3Z',
        'share' => 'M15 6a3 3 0 1 0-2.8-4M15 18a3 3 0 1 0-2.8 4M6 12a3 3 0 1 0 0 .1M8.6 10.5l6-3M8.6 13.5l6 3',
        'help' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-5v-.5c0-1 .6-1.5 1.3-2 .7-.5 1.2-1 1.2-2A2.5 2.5 0 0 0 12 9a2.5 2.5 0 0 0-2.4 1.7M12 17h.01',
    ];
@endphp

<nav class="lms-sidebar">
    <a href="{{ $subdomain ? route('student.dashboard', ['subdomain' => $subdomain]) : url('/') }}" class="lms-sidebar-brand">
        @if($logo = app(\App\Domains\Tenancy\Services\BrandingService::class)->logoUrl())
            <img src="{{ $logo }}" alt="{{ config('app.name') }}">
        @endif
        <span>{{ config('app.name') }}</span>
    </a>

    @foreach ($links as $link)
        @php($active = request()->routeIs($link['route']))
        <a href="{{ route($link['route'], ['subdomain' => $subdomain]) }}" class="lms-sidebar-link @if($active) lms-sidebar-link--active @endif">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icons[$link['icon']] }}" /></svg>
            <span>{{ $link['label'] }}</span>
        </a>
    @endforeach

</nav>

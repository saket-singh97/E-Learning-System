@php($branding = app(\App\Domains\Tenancy\Services\BrandingService::class))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $course->title }} — {{ config('app.name') }}</title>
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

    @if ($translationCounterpart)
        <p>
            Available in:
            {{ ['en' => 'English', 'hi' => 'Hindi'][$course->language] ?? $course->language }}
            /
            <a href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $translationCounterpart->slug]) }}">
                {{ ['en' => 'English', 'hi' => 'Hindi'][$translationCounterpart->language] ?? $translationCounterpart->language }}
            </a>
        </p>
    @endif

    <livewire:syllabus-tree :course="$course" />

    {{-- S17 B8 — course-scoped, so the syllabus page (already gated on
         course visibility) is the natural embed point, not a new page. --}}
    <livewire:ai.weak-topics :course="$course" />

    {{-- S24 B9 — same concept set weak-topics above already computes,
         each shown with the viewer's own peer percentile. --}}
    <livewire:analytics.peer-comparison-widget :course="$course" />

    @if ($recommendations->isNotEmpty())
        <h2>You might also like</h2>
        <ul>
            @foreach ($recommendations as $recommended)
                <li>
                    <a href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $recommended->slug]) }}">
                        {{ $recommended->title }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <livewire:content.course-reviews :course="$course" />

    @livewireScripts
</body>
</html>

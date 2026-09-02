<x-lms-layout :title="$course->title" wide>
    @if ($translationCounterpart)
        <p class="lms-meta" style="margin-bottom:1rem">
            Available in:
            {{ ['en' => 'English', 'hi' => 'Hindi'][$course->language] ?? $course->language }}
            /
            <a class="lms-link" style="margin-top:0" href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $translationCounterpart->slug]) }}">
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
        <div class="lms-panel">
            <h2 class="lms-field-label">You might also like</h2>
            <ul>
                @foreach ($recommendations as $recommended)
                    <li class="lms-list-item">
                        <a class="lms-link" style="margin-top:0" href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $recommended->slug]) }}">
                            {{ $recommended->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <livewire:content.course-reviews :course="$course" />
</x-lms-layout>

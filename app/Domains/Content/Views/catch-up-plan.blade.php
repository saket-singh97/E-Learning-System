<div>
    <h2 class="lms-heading">Catch up — {{ $batch->title }}</h2>

    @if ($plan->isEmpty())
        <p class="lms-subtext">You're all caught up.</p>
    @else
        <div class="lms-panel">
            @foreach ($plan as $lesson)
                <div class="lms-list-item" wire:key="catch-up-lesson-{{ $lesson->id }}">
                    <a class="lms-link" href="{{ route('lessons.watch', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'lesson' => $lesson->id]) }}">
                        {{ $lesson->title }}
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>

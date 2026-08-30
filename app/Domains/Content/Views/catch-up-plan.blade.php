<div>
    <h2>Catch up — {{ $batch->title }}</h2>

    @if ($plan->isEmpty())
        <p>You're all caught up.</p>
    @else
        <ul>
            @foreach ($plan as $lesson)
                <li wire:key="catch-up-lesson-{{ $lesson->id }}">
                    <a href="{{ route('lessons.watch', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'lesson' => $lesson->id]) }}">
                        {{ $lesson->title }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>

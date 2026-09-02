<div class="weak-topics lms-panel">
    <h3 class="lms-field-label">Topics to review</h3>

    @auth
        @if ($weakTopics->isEmpty())
            <p class="lms-meta">No weak topics detected yet — keep taking quizzes in this course.</p>
        @else
            <ul>
                @foreach ($weakTopics as $topic)
                    <li class="lms-list-item">
                        <strong>{{ $topic['concept'] }}</strong>
                        <span class="lms-meta">({{ $topic['correct_count'] }}/{{ $topic['total_count'] }} correct)</span>

                        @if ($topic['lessons']->isNotEmpty())
                            <ul style="margin-top:0.5rem">
                                @foreach ($topic['lessons'] as $lesson)
                                    <li>
                                        <a class="lms-link" style="margin-top:0.375rem;display:block" href="{{ route('lessons.watch', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'lesson' => $lesson->id]) }}">
                                            {{ $lesson->title }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <p class="lms-meta">Log in to see your weak topics.</p>
    @endauth
</div>

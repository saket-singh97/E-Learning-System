<div class="weak-topics">
    <h3>Topics to review</h3>

    @auth
        @if ($weakTopics->isEmpty())
            <p>No weak topics detected yet — keep taking quizzes in this course.</p>
        @else
            <ul>
                @foreach ($weakTopics as $topic)
                    <li>
                        <strong>{{ $topic['concept'] }}</strong>
                        ({{ $topic['correct_count'] }}/{{ $topic['total_count'] }} correct)

                        @if ($topic['lessons']->isNotEmpty())
                            <ul>
                                @foreach ($topic['lessons'] as $lesson)
                                    <li>
                                        <a href="{{ route('lessons.watch', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'lesson' => $lesson->id]) }}">
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
        <p>Log in to see your weak topics.</p>
    @endauth
</div>

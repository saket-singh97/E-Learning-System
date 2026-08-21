<div>
    <h1>{{ $course->title }}</h1>

    @foreach ($sections as $entry)
        <h2>{{ $entry['section']->title }}</h2>

        <ul>
            @foreach ($entry['rows'] as $row)
                @php($lesson = $row['lesson'])
                <li wire:key="lesson-{{ $lesson->id }}">
                    {{ $lesson->title }}
                    <span>({{ $lesson->type }})</span>

                    @if ($lesson->type === 'live_session')
                        @if ($row['session'] === null)
                            <em>Not offered in your batch</em>
                        @elseif ($row['liveState'] === 'scheduled')
                            Scheduled &mdash; {{ $row['session']->starts_at->diffForHumans() }}
                        @elseif ($row['liveState'] === 'live')
                            <strong>Live now</strong>
                            @if ($row['canJoin'])
                                <a href="{{ route('live.join', ['subdomain' => $subdomain, 'session' => $row['session']->id]) }}">Join</a>
                                <livewire:live-poll-response :session="$row['session']" :key="'live-poll-'.$row['session']->id" />
                            @endif
                        @elseif ($row['liveState'] === 'recorded')
                            <a href="{{ route('lessons.watch', ['subdomain' => $subdomain, 'lesson' => $lesson->id]) }}">Watch recording</a>
                        @else
                            Class ended &mdash; recording coming soon
                        @endif
                    @elseif ($lesson->type === 'quiz')
                        @if ($row['canAttemptQuiz'])
                            <a href="{{ route('quizzes.show', ['subdomain' => $subdomain, 'quiz' => $row['quiz']->id]) }}">Take quiz</a>
                        @else
                            <em>Not yet available</em>
                        @endif
                    @elseif ($lesson->type === 'assignment')
                        @if ($row['canSubmitAssignment'])
                            <a href="{{ route('assignments.show', ['subdomain' => $subdomain, 'assignment' => $row['assignment']->id]) }}">Open assignment</a>
                        @else
                            <em>Not yet available</em>
                        @endif
                    @elseif ($row['canStream'])
                        <a href="{{ route('lessons.watch', ['subdomain' => $subdomain, 'lesson' => $lesson->id]) }}">
                            {{ match ($lesson->type) {
                                'pdf' => 'View PDF',
                                'text' => 'Read',
                                'embed' => 'Open',
                                default => 'Watch',
                            } }}
                        </a>
                    @else
                        <em>Enrol to access</em>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
</div>

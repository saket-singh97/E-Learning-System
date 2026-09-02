<div class="lms-panel">
    <h1 class="lms-heading">{{ $course->title }}</h1>

    @include('content::syllabus-tracker', ['tracker' => $syllabusTracker])

    @foreach ($sections as $entry)
        <div class="lms-thread-item">
            <strong>{{ $entry['section']->title }}</strong>

            @foreach ($entry['rows'] as $row)
                @php($lesson = $row['lesson'])
                <div class="lms-thread-item" wire:key="lesson-{{ $lesson->id }}">
                    {{ $lesson->title }}
                    <span class="lms-meta">({{ $lesson->type }})</span>

                    @if ($lesson->type === 'live_session')
                        @if ($row['session'] === null)
                            <p class="lms-meta"><em>Not offered in your batch</em></p>
                        @elseif ($row['liveState'] === 'scheduled')
                            <p class="lms-meta">Scheduled &mdash; {{ $row['session']->starts_at->diffForHumans() }}</p>
                        @elseif ($row['liveState'] === 'live')
                            <p><span class="lms-badge">Live now</span></p>
                            @if ($row['canJoin'])
                                <a class="lms-link" style="margin-top:0.375rem" href="{{ route('live.join', ['subdomain' => $subdomain, 'session' => $row['session']->id]) }}">Join</a>
                                <livewire:live-poll-board :session="$row['session']" :key="'live-poll-board-'.$row['session']->id" />
                            @endif
                        @elseif ($row['liveState'] === 'recorded')
                            <a class="lms-link" style="margin-top:0.375rem" href="{{ route('lessons.watch', ['subdomain' => $subdomain, 'lesson' => $lesson->id]) }}">Watch recording</a>
                        @else
                            <p class="lms-meta">Class ended &mdash; recording coming soon</p>
                        @endif
                    @elseif ($lesson->type === 'quiz')
                        @if ($row['canAttemptQuiz'])
                            <a class="lms-link" style="margin-top:0.375rem" href="{{ route('quizzes.show', ['subdomain' => $subdomain, 'quiz' => $row['quiz']->id]) }}">Take quiz</a>
                        @else
                            <p class="lms-meta"><em>Not yet available</em></p>
                        @endif
                    @elseif ($lesson->type === 'assignment')
                        @if ($row['canSubmitAssignment'])
                            <a class="lms-link" style="margin-top:0.375rem" href="{{ route('assignments.show', ['subdomain' => $subdomain, 'assignment' => $row['assignment']->id]) }}">Open assignment</a>
                        @else
                            <p class="lms-meta"><em>Not yet available</em></p>
                        @endif
                    @elseif ($row['canStream'])
                        <a class="lms-link" style="margin-top:0.375rem" href="{{ route('lessons.watch', ['subdomain' => $subdomain, 'lesson' => $lesson->id]) }}">
                            {{ match ($lesson->type) {
                                'pdf' => 'View PDF',
                                'text' => 'Read',
                                'embed' => 'Open',
                                default => 'Watch',
                            } }}
                        </a>
                    @else
                        <p class="lms-meta"><em>Enrol to access</em></p>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>

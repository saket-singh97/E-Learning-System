<div class="lms-panel">
    <h2 class="lms-heading">My Progress</h2>

    @guest
        <p class="lms-meta">Log in to see your progress.</p>
    @else
        <p class="lms-meta">
            Level {{ $profile->level }} — {{ $profile->xp_total }} XP
            — streak: {{ $profile->current_streak_days }} day(s)
            (longest: {{ $profile->longest_streak_days }})
        </p>

        <div class="lms-bar">
            <div class="lms-bar-fill" style="width: {{ $profile->xp_total % 100 }}%"></div>
        </div>

        <h3 class="lms-field-label" style="margin-top:1rem">Badges</h3>
        <ul style="display:flex; flex-wrap:wrap; gap:0.5rem; list-style:none; padding:0; margin:0">
            @forelse ($badges as $studentBadge)
                <li class="lms-badge">{{ $studentBadge->badge->name }} — {{ $studentBadge->badge->description }}</li>
            @empty
                <li class="lms-meta">No badges earned yet.</li>
            @endforelse
        </ul>

        @if ($batches->isNotEmpty())
            <h3 class="lms-field-label" style="margin-top:1rem">Leaderboard</h3>

            @if (! $leaderboardEnabled)
                <p class="lms-meta">Leaderboard is disabled for this institute.</p>
            @else
                <select class="lms-input" wire:model.live="batchId">
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}">{{ $batch->title }}</option>
                    @endforeach
                </select>

                <ol style="padding-left:1.25rem; margin:0.5rem 0 0">
                    @forelse ($leaderboard as $row)
                        <li class="lms-list-item">
                            #{{ $row['rank'] }} {{ $row['name'] }}
                            — Level {{ $row['level'] }}, {{ $row['xp_total'] }} XP
                        </li>
                    @empty
                        <li class="lms-meta">No ranked students yet.</li>
                    @endforelse
                </ol>
            @endif
        @endif
    @endguest
</div>

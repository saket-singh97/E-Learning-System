<div>
    <h2>My Progress</h2>

    @guest
        <p>Log in to see your progress.</p>
    @else
        <p>
            Level {{ $profile->level }} — {{ $profile->xp_total }} XP
            — streak: {{ $profile->current_streak_days }} day(s)
            (longest: {{ $profile->longest_streak_days }})
        </p>

        <h3>Badges</h3>
        <ul>
            @forelse ($badges as $studentBadge)
                <li>{{ $studentBadge->badge->name }} — {{ $studentBadge->badge->description }}</li>
            @empty
                <li>No badges earned yet.</li>
            @endforelse
        </ul>

        @if ($batches->isNotEmpty())
            <h3>Leaderboard</h3>

            @if (! $leaderboardEnabled)
                <p>Leaderboard is disabled for this institute.</p>
            @else
                <select wire:model.live="batchId">
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}">{{ $batch->title }}</option>
                    @endforeach
                </select>

                <ol>
                    @forelse ($leaderboard as $row)
                        <li>
                            #{{ $row['rank'] }} {{ $row['name'] }}
                            — Level {{ $row['level'] }}, {{ $row['xp_total'] }} XP
                        </li>
                    @empty
                        <li>No ranked students yet.</li>
                    @endforelse
                </ol>
            @endif
        @endif
    @endguest
</div>

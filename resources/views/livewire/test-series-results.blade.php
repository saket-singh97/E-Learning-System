<div>
    <h1 class="lms-heading">{{ $series->title }}</h1>

    @if ($ownRow === null)
        <p class="lms-subtext">You haven't submitted any attempt in this test series yet.</p>
    @else
        <div class="lms-panel">
            <p>Your rank: {{ $ownRow['rank'] }} / {{ $totalParticipants }}</p>

            <div class="lms-bar" style="margin:0.75rem 0 0.375rem;">
                <div class="lms-bar-fill" style="width: {{ $ownRow['percentile'] }}%"></div>
            </div>
            <p class="lms-meta">Percentile: {{ $ownRow['percentile'] }}%</p>

            <p>Your score: {{ $ownRow['total_score'] }}</p>
            @if ($topScore !== null)
                <p class="lms-meta">Gap to topper: {{ round($topScore - $ownRow['total_score'], 2) }}</p>
            @endif
        </div>

        @if ($rankCard !== null)
            <a class="lms-link" href="{{ route('rank-cards.download', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'rankCard' => $rankCard->id]) }}">Download rank card</a>
        @endif
    @endif
</div>

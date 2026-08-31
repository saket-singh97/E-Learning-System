<div>
    <h1>{{ $series->title }}</h1>

    @if ($ownRow === null)
        <p>You haven't submitted any attempt in this test series yet.</p>
    @else
        <p>Your rank: {{ $ownRow['rank'] }} / {{ $totalParticipants }}</p>
        <p>Percentile: {{ $ownRow['percentile'] }}%</p>
        <p>Your score: {{ $ownRow['total_score'] }}</p>
        @if ($topScore !== null)
            <p>Gap to topper: {{ round($topScore - $ownRow['total_score'], 2) }}</p>
        @endif
        @if ($rankCard !== null)
            <p><a href="{{ route('rank-cards.download', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'rankCard' => $rankCard->id]) }}">Download rank card</a></p>
        @endif
    @endif
</div>

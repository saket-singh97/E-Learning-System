<div class="peer-comparison-widget">
    @auth
        @if ($rows->isNotEmpty())
            <h3>Where you stand</h3>
            <ul>
                @foreach ($rows as $row)
                    <li>You're in the top {{ $row['top_percent'] }}% of your batch on <strong>{{ $row['concept'] }}</strong></li>
                @endforeach
            </ul>
        @endif
    @endauth
</div>

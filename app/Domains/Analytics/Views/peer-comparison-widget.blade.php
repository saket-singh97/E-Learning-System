<div class="peer-comparison-widget lms-panel">
    @auth
        @if ($rows->isNotEmpty())
            <h3 class="lms-field-label">Where you stand</h3>
            <ul>
                @foreach ($rows as $row)
                    <li class="lms-list-item">
                        <p style="margin-bottom:0.375rem">You're in the top {{ $row['top_percent'] }}% of your batch on <strong>{{ $row['concept'] }}</strong></p>
                        <div class="lms-bar"><div class="lms-bar-fill" style="width: {{ 100 - $row['top_percent'] }}%"></div></div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endauth
</div>

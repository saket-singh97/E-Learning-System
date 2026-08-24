<div>
    @if ($checkedIn)
        <p>You're checked in for this session.</p>
    @else
        <button type="button" onclick="
            navigator.geolocation.getCurrentPosition(
                (position) => @this.call('checkIn', position.coords.latitude, position.coords.longitude),
                () => @this.call('geolocationUnavailable')
            )
        ">Check in</button>

        @if ($error)
            <p role="alert">{{ $error }}</p>
        @endif
    @endif
</div>

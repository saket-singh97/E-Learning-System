<div>
    @if ($checkedIn)
        <span class="lms-badge">Checked in</span>
    @else
        <button type="button" class="lms-button" style="width:auto;" onclick="
            navigator.geolocation.getCurrentPosition(
                (position) => @this.call('checkIn', position.coords.latitude, position.coords.longitude),
                () => @this.call('geolocationUnavailable')
            )
        ">Check in</button>

        @if ($error)
            <p class="lms-error" role="alert">{{ $error }}</p>
        @endif
    @endif
</div>

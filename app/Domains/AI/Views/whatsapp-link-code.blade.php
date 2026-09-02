<div class="lms-panel">
    @if ($link)
        <p class="lms-meta">WhatsApp linked: {{ $link->phone_e164 }}</p>
    @elseif ($code)
        <p>Text this code to our WhatsApp number to link your phone: <strong>{{ $code }}</strong></p>
        <p class="lms-meta">Expires in 15 minutes.</p>
    @else
        <button type="button" class="lms-button lms-button-secondary" style="width:auto;" wire:click="generate">Link WhatsApp</button>
    @endif
</div>

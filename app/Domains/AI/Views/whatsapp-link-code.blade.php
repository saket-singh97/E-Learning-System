<div>
    @if ($link)
        <p>WhatsApp linked: {{ $link->phone_e164 }}</p>
    @elseif ($code)
        <p>Text this code to our WhatsApp number to link your phone: <strong>{{ $code }}</strong></p>
        <p>Expires in 15 minutes.</p>
    @else
        <button type="button" wire:click="generate">Link WhatsApp</button>
    @endif
</div>

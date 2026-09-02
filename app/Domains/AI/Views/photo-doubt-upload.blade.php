<div class="lms-panel">
    <h3 class="lms-field-label">Ask a photo doubt</h3>

    @auth
        <form wire:submit="submit">
            <input class="lms-input" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp">
            @error('image') <p class="lms-error">{{ $message }}</p> @enderror
            <button type="submit" class="lms-button lms-button-secondary" style="width:auto; margin-top:0.75rem;">Ask</button>
        </form>

        <ul>
            @foreach ($queries as $query)
                <li class="lms-list-item" wire:key="photo-doubt-{{ $query->id }}">
                    @if (in_array($query->status, ['pending', 'processing'], true))
                        <p class="lms-meta" wire:poll.3s>Processing your photo...</p>
                    @elseif ($query->status === 'ready')
                        @if ($query->extracted_question)
                            <p><strong>Q:</strong> {{ $query->extracted_question }}</p>
                        @endif
                        <p>{{ $query->solution }}</p>
                    @elseif ($query->status === 'failed')
                        <p class="lms-error">Something went wrong reading that photo — try again.</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="lms-meta">Log in to ask a photo doubt.</p>
    @endauth
</div>

<div class="photo-doubt-upload">
    <h3>Ask a photo doubt</h3>

    @auth
        <form wire:submit="submit">
            <input type="file" wire:model="image" accept="image/jpeg,image/png,image/webp">
            @error('image') <p>{{ $message }}</p> @enderror
            <button type="submit">Ask</button>
        </form>

        <ul>
            @foreach ($queries as $query)
                <li wire:key="photo-doubt-{{ $query->id }}">
                    @if (in_array($query->status, ['pending', 'processing'], true))
                        <p wire:poll.3s>Processing your photo...</p>
                    @elseif ($query->status === 'ready')
                        @if ($query->extracted_question)
                            <p><strong>Q:</strong> {{ $query->extracted_question }}</p>
                        @endif
                        <p><strong>Solution:</strong> {{ $query->solution }}</p>
                    @elseif ($query->status === 'failed')
                        <p>Something went wrong reading that photo — try again.</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p>Log in to ask a photo doubt.</p>
    @endauth
</div>

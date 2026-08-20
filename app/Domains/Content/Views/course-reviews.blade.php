<div>
    <h2>Reviews</h2>

    @auth
        @if ($canReview)
            <form wire:submit="submit">
                <select wire:model="rating">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}">{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                    @endfor
                </select>
                @error('rating')
                    <p>{{ $message }}</p>
                @enderror

                <textarea wire:model="body" placeholder="Share your experience (optional)"></textarea>
                @error('body')
                    <p>{{ $message }}</p>
                @enderror

                <button type="submit">Submit review</button>
            </form>
        @else
            <p>You must be enrolled in this course to leave a review.</p>
        @endif
    @endauth

    <ul>
        @forelse ($reviews as $review)
            <li>
                <strong>{{ $review->user->name }}</strong> — {{ $review->rating }}/5
                @if ($review->body)
                    <p>{{ $review->body }}</p>
                @endif
                @if ($review->instructor_response)
                    <p><em>Instructor response:</em> {{ $review->instructor_response }}</p>
                @endif

                @if ($canRespond && ! $review->instructor_response)
                    <form wire:submit="respond({{ $review->id }})">
                        <textarea wire:model="responseDrafts.{{ $review->id }}" placeholder="Respond to this review"></textarea>
                        @error("responseDrafts.{$review->id}")
                            <p>{{ $message }}</p>
                        @enderror
                        <button type="submit">Respond</button>
                    </form>
                @endif
            </li>
        @empty
            <li>No reviews yet.</li>
        @endforelse
    </ul>
</div>

<div class="lms-panel">
    <h2 class="lms-field-label">Reviews</h2>

    @auth
        @if ($canReview)
            <form wire:submit="submit">
                <label class="lms-field">
                    <span class="lms-field-label">Rating</span>
                    <select class="lms-input" wire:model="rating">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}">{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                        @endfor
                    </select>
                </label>
                @error('rating')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">Your review</span>
                    <textarea class="lms-input" wire:model="body" placeholder="Share your experience (optional)"></textarea>
                </label>
                @error('body')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <button type="submit" class="lms-button" style="width:auto">Submit review</button>
            </form>

            <hr class="lms-divider">
        @else
            <p class="lms-meta">You must be enrolled in this course to leave a review.</p>
        @endif
    @endauth

    <ul>
        @forelse ($reviews as $review)
            <li class="lms-list-item">
                <strong>{{ $review->user->name }}</strong> <span class="lms-meta">{{ $review->rating }}/5</span>
                @if ($review->body)
                    <p style="margin-top:0.375rem">{{ $review->body }}</p>
                @endif
                @if ($review->instructor_response)
                    <p class="lms-meta" style="margin-top:0.375rem"><em>Instructor response:</em> {{ $review->instructor_response }}</p>
                @endif

                @if ($canRespond && ! $review->instructor_response)
                    <form wire:submit="respond({{ $review->id }})" style="margin-top:0.75rem">
                        <label class="lms-field">
                            <span class="lms-field-label">Respond to this review</span>
                            <textarea class="lms-input" wire:model="responseDrafts.{{ $review->id }}" placeholder="Respond to this review"></textarea>
                        </label>
                        @error("responseDrafts.{$review->id}")
                            <p class="lms-error">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="lms-button" style="width:auto">Respond</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="lms-meta">No reviews yet.</li>
        @endforelse
    </ul>
</div>

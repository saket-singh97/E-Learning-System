<div>
    <h1 class="lms-heading">Batch feedback</h1>

    @auth
        @if ($canSubmit)
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
                    <span class="lms-field-label">Comment</span>
                    <textarea class="lms-input" wire:model="comment" placeholder="Share your feedback (optional)" rows="4"></textarea>
                </label>
                @error('comment')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <button type="submit" class="lms-button">Submit feedback</button>
            </form>
        @else
            <p class="lms-subtext">You must have an active or completed enrolment in this batch to leave feedback.</p>
        @endif
    @endauth
</div>

<div>
    <h2>Batch feedback</h2>

    @auth
        @if ($canSubmit)
            <form wire:submit="submit">
                <select wire:model="rating">
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}">{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                    @endfor
                </select>
                @error('rating')
                    <p>{{ $message }}</p>
                @enderror

                <textarea wire:model="comment" placeholder="Share your feedback (optional)"></textarea>
                @error('comment')
                    <p>{{ $message }}</p>
                @enderror

                <button type="submit">Submit feedback</button>
            </form>
        @else
            <p>You must have an active or completed enrolment in this batch to leave feedback.</p>
        @endif
    @endauth
</div>

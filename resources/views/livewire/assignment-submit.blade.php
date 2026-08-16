<div>
    <h1>{{ $assignment->title }}</h1>
    <p>Due: {{ $assignment->due_at->format('Y-m-d H:i') }} &middot; Max marks: {{ (float) $assignment->max_marks }}</p>

    @if ($assignment->instructions)
        <p>{{ $assignment->instructions }}</p>
    @endif

    @error('submission')
        <p><strong>{{ $message }}</strong></p>
    @enderror

    @if ($history->isNotEmpty())
        <h2>Your submissions</h2>
        <ol>
            @foreach ($history as $version)
                <li wire:key="version-{{ $version->id }}">
                    Version {{ $version->version_number }} &mdash; {{ $version->submitted_at->diffForHumans() }}
                    @if ($version->is_late)
                        &mdash; <strong>late</strong>
                    @endif

                    <ul>
                        @foreach ($version->files as $file)
                            <li wire:key="version-{{ $version->id }}-file-{{ $loop->index }}">{{ $file['original_name'] }}</li>
                        @endforeach
                    </ul>

                    @if ($version->graded_at !== null)
                        <p>Marks: {{ (float) $version->marks_awarded }} / {{ (float) $assignment->max_marks }}</p>
                        @if ($version->feedback)
                            <p>{{ $version->feedback }}</p>
                        @endif
                    @else
                        <p><em>Awaiting grading.</em></p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canSubmitAnotherVersion)
        <input type="file" wire:model="files" multiple>

        @error('files.*')
            <p><strong>{{ $message }}</strong></p>
        @enderror

        <button type="button" wire:click="submit">{{ $history->isEmpty() ? 'Submit' : 'Resubmit' }}</button>
    @else
        <p><em>You have used all 3 allowed submissions for this assignment.</em></p>
    @endif
</div>
